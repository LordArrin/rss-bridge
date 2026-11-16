<?php

declare(strict_types=1);

namespace RSSBridge\Proxies;

use RSSBridge\Caches\CacheInterface;
use RSSBridge\Logger\Logger;

/**
 * Base class for all proxy implementations.
 *
 * Provides common functionality:
 * - Automatic caching with Stale-while-revalidate fallback
 * - Retry logic with exponential backoff
 * - Unified logging interface
 * - Response validation hooks
 * - Safe scalar coercion helpers for mixed config/option values
 */
abstract class ProxyAbstract implements ProxyInterface
{
    /** @var array<string, mixed> */
    protected array $config;
    protected int $timeout = 180;
    protected int $maxRetries = 3;
    protected ?CacheInterface $cache = null;
    protected ?Logger $logger = null;

    private const FLIGHT_LOCK_TTL = 120;
    private const FLIGHT_WAIT_MS = 2000;
    private const FLIGHT_POLL_MS = 100;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
        global $container;
        if (isset($container['cache']) === true) {
            $this->cache = $container['cache'];
        }
        if (isset($container['logger']) === true) {
            $this->logger = $container['logger'];
        }
        $this->initialize();
    }

    protected function initialize(): void
    {
    }

    public function getName(): string
    {
        return 'AbstractProxy';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * Safely coerces a mixed config/option value to int.
     * Accepts ints, numeric strings and floats; anything else yields $default.
     */
    protected static function toInt(mixed $value, int $default): int
    {
        if (is_int($value) === true) {
            return $value;
        }
        if (is_float($value) === true) {
            return (int) $value;
        }
        if (is_string($value) === true && is_numeric($value) === true) {
            return (int) $value;
        }
        return $default;
    }

    /**
     * Safely coerces a mixed config/option value to string.
     * Non-scalar values yield $default.
     */
    protected static function toString(mixed $value, string $default = ''): string
    {
        if (is_string($value) === true) {
            return $value;
        }
        if (is_int($value) === true || is_float($value) === true) {
            return (string) $value;
        }
        if (is_bool($value) === true) {
            return $value === true ? '1' : '';
        }
        return $default;
    }

    /**
     * Safely coerces a mixed config/option value to bool.
     */
    protected static function toBool(mixed $value, bool $default): bool
    {
        if (is_bool($value) === true) {
            return $value;
        }
        if (is_int($value) === true) {
            return $value !== 0;
        }
        if (is_string($value) === true) {
            $lower = strtolower(trim($value));
            if ($lower === '') {
                return $default;
            }
            return filter_var($lower, FILTER_VALIDATE_BOOLEAN);
        }
        return $default;
    }

    /**
     * Gets HTML with automatic caching and Stale-while-revalidate fallback.
     *
     * When upstream request fails (timeout, HTTP error, exception), the last
     * successfully cached response is returned instead, preventing bridge failures.
     *
     * @param string $url     URL to fetch
     * @param array<string, mixed> $options Proxy-specific options (cache_ttl, use_cache, etc.)
     * @return string HTML content
     * @throws \RuntimeException If fetch fails and no stale data is available
     */
    public function getHtml(string $url, array $options = []): string
    {
        $cacheTtl = self::toInt($options['cache_ttl'] ?? 3600, 3600);
        $useCache = self::toBool($options['use_cache'] ?? true, true);
        $cache = $this->cache;
        $cacheKey = '';
        if ($useCache === true && $cache instanceof CacheInterface) {
            $cacheKey = $this->buildCacheKey($url, $options);
        }
        $lockAcquired = false;

        /** @var array{fresh: mixed, stale: mixed} $cached */
        $cached = ['fresh' => null, 'stale' => null];

        if ($useCache === true && $cache instanceof CacheInterface) {
            $cached = $cache->getWithStale($cacheKey);

            if (is_string($cached['fresh']) === true && $cached['fresh'] !== '') {
                $this->log('debug', sprintf('Cache hit (fresh) for %s', $url));
                return $cached['fresh'];
            }

            $lockTtl = max(self::FLIGHT_LOCK_TTL, $this->timeout + 30);
            if ($cache->acquireLock($cacheKey, $lockTtl) === true) {
                $lockAcquired = true;
            } else {
                $waited = $this->waitForFresh($cacheKey);
                if (is_string($waited) === true && $waited !== '') {
                    $this->log('debug', sprintf('Cache hit (fresh, waited) for %s', $url));
                    return $waited;
                }
            }
        }

        $this->log('info', sprintf('Fetching %s via %s', $url, $this->getName()));

        try {
            $html = $this->fetchHtml($url, $options);

            if ($this->validateResponse($html) === false) {
                throw new \RuntimeException(sprintf('Proxy returned invalid response for %s', $url));
            }

            if ($useCache === true && $cache instanceof CacheInterface && $cacheTtl > 0) {
                $cache->set($cacheKey, $html, $cacheTtl);
            }

            return $html;
        } catch (\Throwable $e) {
            if (
                $useCache === true
                && $cache instanceof CacheInterface
                && is_string($cached['stale']) === true
                && $cached['stale'] !== ''
            ) {
                $this->log('warning', sprintf(
                    'Returning stale cache for %s due to proxy error: %s',
                    $url,
                    $e->getMessage()
                ));
                return $cached['stale'];
            }

            throw $e;
        } finally {
            if ($lockAcquired === true && $cache instanceof CacheInterface) {
                $cache->releaseLock($cacheKey);
            }
        }
    }

    /**
     * @param string $url
     * @param array<string, mixed> $options
     * @return array{body: string, type: string}
     * @throws \RuntimeException If fetch fails and no stale data is available
     */
    public function getBinary(string $url, array $options = []): array
    {
        $cacheTtl = self::toInt($options['cache_ttl'] ?? 86400, 86400);
        $useCache = self::toBool($options['use_cache'] ?? true, true);
        $cache = $this->cache;
        $cacheKey = '';
        if ($useCache === true && $cache instanceof CacheInterface) {
            $cacheKey = $this->buildCacheKey('binary_' . $url, $options);
        }
        $lockAcquired = false;

        /** @var array{fresh: mixed, stale: mixed} $cached */
        $cached = ['fresh' => null, 'stale' => null];

        if ($useCache === true && $cache instanceof CacheInterface) {
            $cached = $cache->getWithStale($cacheKey);

            $fresh = $this->normalizeBinaryPayload($cached['fresh']);
            if ($fresh !== null) {
                $this->log('debug', sprintf('Cache hit (fresh binary) for %s', $url));
                return $fresh;
            }

            $lockTtl = max(self::FLIGHT_LOCK_TTL, $this->timeout + 30);
            if ($cache->acquireLock($cacheKey, $lockTtl) === true) {
                $lockAcquired = true;
            } else {
                $waited = $this->normalizeBinaryPayload($this->waitForFresh($cacheKey));
                if ($waited !== null) {
                    $this->log('debug', sprintf('Cache hit (fresh binary, waited) for %s', $url));
                    return $waited;
                }
            }
        }

        $this->log('info', sprintf('Fetching binary %s via %s', $url, $this->getName()));

        try {
            $payload = $this->doFetchBinary($url, $options);

            if ($useCache === true && $cache instanceof CacheInterface && $cacheTtl > 0) {
                $cache->set($cacheKey, $this->encodeBinaryPayload($payload), $cacheTtl);
            }

            return $payload;
        } catch (\Throwable $e) {
            if ($useCache === true && $cache instanceof CacheInterface) {
                $stale = $this->normalizeBinaryPayload($cached['stale']);
                if ($stale !== null) {
                    $this->log('warning', sprintf(
                        'Returning stale binary cache for %s due to proxy error: %s',
                        $url,
                        $e->getMessage()
                    ));
                    return $stale;
                }
            }

            throw $e;
        } finally {
            if ($lockAcquired === true && $cache instanceof CacheInterface) {
                $cache->releaseLock($cacheKey);
            }
        }
    }

    protected function waitForFresh(string $cacheKey): mixed
    {
        $cache = $this->cache;
        if ($cache instanceof CacheInterface === false) {
            return null;
        }

        $waited = 0;
        while ($waited < self::FLIGHT_WAIT_MS) {
            usleep(self::FLIGHT_POLL_MS * 1000);
            $waited += self::FLIGHT_POLL_MS;
            $entry = $cache->getWithStale($cacheKey);
            if ($entry['fresh'] !== null) {
                return $entry['fresh'];
            }
        }

        return null;
    }

    /**
     * Default binary fetcher - throws by default.
     * Child classes must override either doFetchBinary() or getBinary() directly.
     *
     * @param array<string, mixed> $options
     * @return array{body: string, type: string}
     */
    protected function doFetchBinary(string $url, array $options): array
    {
        throw new \RuntimeException(sprintf('Binary downloads not supported by %s', $this->getName()));
    }

    /**
     * Basic response validation.
     * Overridden in child classes for specific logic (e.g. Cloudflare check).
     */
    protected function validateResponse(string $response): bool
    {
        return $response !== '';
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function buildCacheKey(string $url, array $options): string
    {
        return 'proxy_' . md5($url . serialize($options));
    }

    /**
     * Validates that a cached value is a proper binary payload.
     *
     * @param mixed $value The cached value to validate
     * @return bool True if the value is a valid ['body' => string, 'type' => string] array
     */
    protected function isValidBinaryPayload(mixed $value): bool
    {
        return $this->normalizeBinaryPayload($value) !== null;
    }

    /**
     * @param array{body: string, type: string} $payload
     * @return array{body: string, type: string, body_encoding: string}
     */
    protected function encodeBinaryPayload(array $payload): array
    {
        return [
            'body' => base64_encode($payload['body']),
            'type' => $payload['type'],
            'body_encoding' => 'base64',
        ];
    }

    /**
     * @return array{body: string, type: string}|null
     */
    protected function normalizeBinaryPayload(mixed $value): ?array
    {
        if (is_array($value) === false) {
            return null;
        }

        $body = $value['body'] ?? null;
        $type = $value['type'] ?? null;
        $encoding = $value['body_encoding'] ?? null;

        if (is_string($body) === false || is_string($type) === false || $encoding !== 'base64') {
            return null;
        }

        $decoded = base64_decode($body, true);
        if ($decoded === false) {
            return null;
        }

        return ['body' => $decoded, 'type' => $type];
    }

    /**
     * Logger
     *
     * @param array<string, mixed> $context
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger instanceof Logger === false) {
            return;
        }

        $fullMessage = sprintf('[Proxy: %s] %s', $this->getName(), $message);

        match ($level) {
            'debug' => $this->logger->debug($fullMessage, $context),
            'info', 'notice' => $this->logger->info($fullMessage, $context),
            'warning' => $this->logger->warning($fullMessage, $context),
            'error', 'critical', 'alert', 'emergency' => $this->logger->error($fullMessage, $context),
            default => $this->logger->info($fullMessage, $context),
        };
    }

    /**
     * A general-purpose method for HTTP requests with retries and exponential backoff.
     * Used by child classes (e.g., FlareSolverrProxy) for API calls.
     *
     * @param array<string, mixed> $payload
     * @param array<int, string> $headers
     * @return array<string, mixed>
     */
    protected function request(string $method, string $url, array $payload = [], array $headers = []): array
    {
        $attempt = 0;
        $lastError = '';

        while ($attempt < $this->maxRetries) {
            try {
                return $this->executeRequest($method, $url, $payload, $headers);
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                $attempt++;
                if ($attempt < $this->maxRetries) {
                    sleep(2 ** ($attempt - 1));
                }
            }
        }

        throw new \RuntimeException(sprintf(
            'Proxy request failed after %d attempts: %s',
            $this->maxRetries,
            $lastError
        ));
    }

    /**
     * @param array<string, mixed> $options
     */
    abstract protected function fetchHtml(string $url, array $options): string;

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $headers
     * @return array<string, mixed>
     */
    abstract protected function executeRequest(string $method, string $url, array $payload, array $headers): array;
}
