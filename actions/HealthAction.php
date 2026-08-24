<?php

declare(strict_types=1);

namespace RSSBridge\Actions;

use Json;
use Request;
use Response;
use RSSBridge\Caches\CacheInterface;
use RSSBridge\Configuration;
use SafeBridgeLoader;

final class HealthAction implements ActionInterface
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

            $startSet = microtime(true);
            $this->cache->set($testKey, $testValue, 10);
            $setLatency = round((microtime(true) - $startSet) * 1000, 2);

            $startGet = microtime(true);
            $retrieved = $this->cache->get($testKey);
            $getLatency = round((microtime(true) - $startGet) * 1000, 2);

            if (method_exists($this->cache, 'delete') === true) {
                $this->cache->delete($testKey);
            }

            $result = [
                'status' => 'ok',
                'message' => 'cache read/write working correctly',
                'type' => Configuration::getConfig('cache', 'type'),
                'set_latency_ms' => $setLatency,
                'get_latency_ms' => $getLatency,
            ];

            if (method_exists($this->cache, 'getStats') === true) {
                $stats = $this->cache->getStats();
                if (empty($stats) === false) {
                    $serverStats = reset($stats);
                    $result['memcached'] = [
                        'curr_items' => $serverStats['curr_items'] ?? 0,
                        'total_items' => $serverStats['total_items'] ?? 0,
                        'bytes' => $serverStats['bytes'] ?? 0,
                        'get_hits' => $serverStats['get_hits'] ?? 0,
                        'get_misses' => $serverStats['get_misses'] ?? 0,
                        'hit_rate' => $this->calculateHitRate($serverStats),
                    ];
                }
            }

            if ($retrieved !== $testValue) {
                $result['status'] = 'degraded';
                $result['message'] = 'cache read/write mismatch';
            }

            if ($setLatency > 50 || $getLatency > 50) {
                $result['status'] = 'degraded';
                $result['message'] = sprintf('Slow cache operations: set=%sms, get=%sms', $setLatency, $getLatency);
            }

            return $result;
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'type' => Configuration::getConfig('cache', 'type'),
            ];
        }
    }

    private function calculateHitRate(array $stats): string
    {
        $hits = $stats['get_hits'] ?? 0;
        $misses = $stats['get_misses'] ?? 0;
        $total = $hits + $misses;

        if ($total === 0) {
            return 'N/A';
        }

        return sprintf('%.1f%%', ($hits / $total) * 100);
    }

    private function checkProxy(): array
    {
        $profiles = [];

        $possibleProfiles = [
            'flaresolverr',
            'tgws',
            'custom',
        ];

        foreach ($possibleProfiles as $profileName) {
            $type = Configuration::getConfig('proxy_profile_' . $profileName, 'type');
            if ($type !== null && $type !== '') {
                $profiles[] = $profileName;
            }
        }

        $status = 'disabled';
        if (count($profiles) !== 0) {
            $status = 'ok';
        }

        return [
            'status' => $status,
            'profiles' => $profiles,
            'count' => count($profiles),
        ];
    }

    private function calculateOverallStatus(array $checks): string
    {
        $statuses = [];
        foreach ($checks as $check) {
            if (is_array($check) === true && isset($check['status']) === true) {
                $statuses[] = $check['status'];
            }
        }

        if (in_array('error', $statuses, true) === true) {
            return 'down';
        }
        if (in_array('degraded', $statuses, true) === true) {
            return 'degraded';
        }
        return 'ok';
    }

    private function getHttpCode(string $status): int
    {
        return match ($status) {
            'ok' => 200,
            'degraded' => 200,
            'down' => 503,
            default => 500,
        };
    }
}
