<?php

declare(strict_types=1);

namespace RSSBridge\Actions;

use RSSBridge\Utils\Json;
use RSSBridge\Http\Request;
use RSSBridge\Http\Response;
use RSSBridge\Caches\CacheInterface;
use RSSBridge\SafeBridgeLoader;

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
            'version' => get_rssbridge_version(),
            'checks' => $checks,
        ];

        return new Response(
            Json::encode($response),
            $httpCode,
            ['content-type' => 'application/json']
        );
    }

    /**
     * @return array{status: string, broken_count: int, broken_bridges: list<string>}
     */
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

    /**
     * @return array<string, mixed>
     */
    private function checkCache(): array
    {
        // Safe environment read with explicit fallback
        $cacheTypeRaw = getenv('CACHE_TYPE');
        $cacheType = $cacheTypeRaw !== false ? $cacheTypeRaw : 'file';

        try {
            $testKey = 'health_check_' . uniqid();
            $testValue = 'test_' . time();

            $startSet = microtime(true);
            $this->cache->set($testKey, $testValue, 10);
            $setLatency = round((microtime(true) - $startSet) * 1000, 2);

            $startGet = microtime(true);
            $retrieved = $this->cache->get($testKey);
            $getLatency = round((microtime(true) - $startGet) * 1000, 2);

            $this->cache->delete($testKey);

            $result = [
                'status' => 'ok',
                'message' => 'cache read/write working correctly',
                'type' => $cacheType,
                'set_latency_ms' => $setLatency,
                'get_latency_ms' => $getLatency,
            ];

            if (method_exists($this->cache, 'getStats') === true) {
                $stats = $this->cache->getStats();
                if (is_array($stats) === true && $stats !== []) {
                    $serverStats = reset($stats);
                    if (is_array($serverStats) === true) {
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
                'type' => $cacheType,
            ];
        }
    }

    /**
     * @param array<string, mixed> $stats
     */
    private function calculateHitRate(array $stats): string
    {
        $hitsRaw = $stats['get_hits'] ?? 0;
        $missesRaw = $stats['get_misses'] ?? 0;

        $hits = 0;
        if (is_int($hitsRaw) === true) {
            $hits = $hitsRaw;
        } elseif (is_string($hitsRaw) === true && is_numeric($hitsRaw) === true) {
            $hits = (int) $hitsRaw;
        }

        $misses = 0;
        if (is_int($missesRaw) === true) {
            $misses = $missesRaw;
        } elseif (is_string($missesRaw) === true && is_numeric($missesRaw) === true) {
            $misses = (int) $missesRaw;
        }

        $total = $hits + $misses;

        if ($total === 0) {
            return 'N/A';
        }

        return sprintf('%.1f%%', ($hits / $total) * 100);
    }

    /**
     * @return array{status: string, profiles: list<string>, count: int}
     */
    private function checkProxy(): array
    {
        $profiles = [];
        $possibleProfiles = [
            'flaresolverr',
            'tgws',
            'custom',
        ];

        foreach ($possibleProfiles as $profileName) {
            $envKey = 'PROXY_PROFILE_' . strtoupper($profileName) . '_TYPE';
            $type = getenv($envKey);

            // Explicit strict comparisons to satisfy PHPCS
            if ($type !== false && trim($type) !== '') {
                $profiles[] = $profileName;
            }
        }

        $status = count($profiles) !== 0 ? 'ok' : 'disabled';

        return [
            'status' => $status,
            'profiles' => $profiles,
            'count' => count($profiles),
        ];
    }

    /**
     * @param array<string, mixed> $checks
     */
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
