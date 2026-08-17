<?php

declare(strict_types=1);

class HealthAction implements ActionInterface
{
    private SafeBridgeLoader $safeBridgeLoader;
    private CacheInterface $cache;

    public function __construct(SafeBridgeLoader $safeBridgeLoader, CacheInterface $cache)
    {
        $this->safeBridgeLoader = $safeBridgeLoader;
        $this->cache = $cache;
    }

    public function __invoke(Request $request): Response
    {
        $checks = [
            'bridges' => $this->checkBridges(),
            'cache' => $this->checkCache(),
            'proxy' => $this->checkProxy(),
        ];

        $overallStatus = $this->calculateOverallStatus($checks);
        $httpCode = $this->getHttpCode($overallStatus);

        $response = [
            'status' => $overallStatus,
            'timestamp' => date('c'),
            'version' => Configuration::getVersion(),
            'checks' => $checks,
        ];

        return new Response(
            Json::encode($response),
            $httpCode,
            ['content-type' => 'application/json']
        );
    }

    private function checkBridges(): array
    {
        $broken = $this->safeBridgeLoader->getBrokenBridges();
        $brokenCount = count($broken);

        return [
            'status' => $brokenCount === 0 ? 'ok' : 'degraded',
            'broken_count' => $brokenCount,
            'broken_bridges' => array_keys($broken),
        ];
    }

    private function checkCache(): array
    {
        try {
            $testKey = 'health_check_' . uniqid();
            $testValue = 'test_' . time();
            
            $this->cache->set($testKey, $testValue, 10);
            $retrieved = $this->cache->get($testKey);
            
            if (method_exists($this->cache, 'delete')) {
                $this->cache->delete($testKey);
            }

            if ($retrieved === $testValue) {
                return [
                    'status' => 'ok',
                    'message' => 'cache read/write working correctly',
                    'type' => Configuration::getConfig('cache', 'type'),
                ];
            } else {
                return [
                    'status' => 'degraded',
                    'message' => 'cache read/write mismatch',
                    'type' => Configuration::getConfig('cache', 'type'),
                ];
            }
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'type' => Configuration::getConfig('cache', 'type'),
            ];
        }
    }

    private function checkProxy(): array
    {
        $profiles = [];
        
        // We check for the presence of configured proxy_profile_* in the configuration
        $possibleProfiles = [
            'flaresolverr',
            'tgws',
            'custom',
        ];
        
        foreach ($possibleProfiles as $profileName) {
            $type = Configuration::getConfig('proxy_profile_' . $profileName, 'type');
            if ($type) {
                $profiles[] = $profileName;
            }
        }

        return [
            'status' => count($profiles) > 0 ? 'ok' : 'disabled',
            'profiles' => $profiles,
            'count' => count($profiles),
        ];
    }

    private function calculateOverallStatus(array $checks): string
    {
        $statuses = [];
        foreach ($checks as $check) {
            if (is_array($check) && isset($check['status'])) {
                $statuses[] = $check['status'];
            }
        }
        
        if (in_array('error', $statuses)) {
            return 'down';
        }
        if (in_array('degraded', $statuses)) {
            return 'degraded';
        }
        return 'ok';
    }

    private function getHttpCode(string $status): int
    {
        return match($status) {
            'ok' => 200,
            'degraded' => 200,
            'down' => 503,
            default => 500,
        };
    }
}
