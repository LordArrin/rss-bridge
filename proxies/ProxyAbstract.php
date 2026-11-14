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

        /** @var array{fresh: mixed, stale: mixed} $cached */
        $cached = ['fresh' => null, 'stale' => null];

        if ($useCache === true && $this->cache instanceof CacheInterface) {
            $cacheKey = $this->buildCacheKey($url, $options);
            $cached = $this->cache->getWithStale($cacheKey);

            if (is_string($cached['fresh']) === true && $cached['fresh'] !== '') {
                $this->log('debug', sprintf('Cache hit (fresh) for %s', $url));
                return $cached['fresh'];
            }
        }

        $this->log('info', sprintf('Fetching %s via %s', $url, $this->getName()));

        try {
            $html = $this->fetchHtml($url, $options);

            if ($this->validateResponse($html) === false) {
                throw new \RuntimeException(sprintf('Proxy returned invalid response for %s', $url));
            }

            if (
                $useCache === true
                && $this->cache instanceof CacheInterface
                && $cacheTtl > 0
            ) {
                $cacheKey = $this->buildCacheKey($url, $options);
                $this->cache->set($cacheKey, $html, $cacheTtl);
            }

            return $html;
        } catch (\Throwable $e) {
            if (
                $useCache === true
                && $this->cache instanceof CacheInterface
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
        }
    }

    /**
     * Retrieves binary data via proxy with Stale-while-revalidate fallback.
     *
     * @param string $url     URL to fetch
     * @param array<string, mixed> $options Proxy-specific options
     * @return array{body: string, type: string}
     * @throws \RuntimeException If fetch fails and no stale data is available
     */
    public function getBinary(string $url, array $options = []): array
    {
        $cacheTtl = self::toInt($options['cache_ttl'] ?? 86400, 86400);
        $useCache = self::toBool($options['use_cache'] ?? true, true);

        /** @var array{fresh: mixed, stale: mixed} $cached */
        $cached = ['fresh' => null, 'stale' => null];

        if ($useCache === true && $this->cache instanceof CacheInterface) {
            $cacheKey = $this->buildCacheKey('binary_' . $url, $options);
            $cached = $this->cache->getWithStale($cacheKey);

            $fresh = $this->normalizeBinaryPayload($cached['fresh']);
            if ($fresh !== null) {
                $this->log('debug', sprintf('Cache hit (fresh binary) for %s', $url));
                return $fresh;
            }
        }

        $this->log('info', sprintf('Fetching binary %s via %s', $url, $this->getName()));

        try {
            $payload = $this->doFetchBinary($url, $options);

            if (
                $useCache === true
                && $this->cache instanceof CacheInterface
                && $cacheTtl > 0
            ) {
                $cacheKey = $this->buildCacheKey('binary_' . $url, $options);
                $this->cache->set($cacheKey, $payload, $cacheTtl);
            }

            return $payload;
        } catch (\Throwable $e) {
            if ($useCache === true && $this->cache instanceof CacheInterface) {
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
        }
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
     * Narrows a mixed cached value into a strictly typed binary payload.
     *
     * @return array{body: string, type: string}|null
     */
    protected function normalizeBinaryPayload(mixed $value): ?array
    {
        if (is_array($value) === false) {
            return null;
        }

        $body = $value['body'] ?? null;
        $type = $value['type'] ?? null;

        if (is_string($body) === false || is_string($type) === false) {
            return null;
        }

        return ['body' => $body, 'type' => $type];
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
