<?php

declare(strict_types=1);

namespace RSSBridge\Proxies;

use Logger;
use RSSBridge\Caches\CacheInterface;

/**
 * Base class for all proxy implementations.
 *
 * Provides common functionality:
 * - Automatic caching with Stale-while-revalidate fallback
 * - Retry logic with exponential backoff
 * - Unified logging interface
 * - Response validation hooks
 */
abstract class ProxyAbstract implements ProxyInterface
{
    protected array $config;
    protected int $timeout = 180;
    protected int $maxRetries = 3;
    protected ?CacheInterface $cache = null;
    protected ?Logger $logger = null;

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
     * Gets HTML with automatic caching and Stale-while-revalidate fallback.
     *
     * When upstream request fails (timeout, HTTP error, exception), the last
     * successfully cached response is returned instead, preventing bridge failures.
     *
     * @param string $url     URL to fetch
     * @param array  $options Proxy-specific options (cache_ttl, use_cache, etc.)
     * @return string HTML content
     * @throws \RuntimeException If fetch fails and no stale data is available
     */
    public function getHtml(string $url, array $options = []): string
    {
        $cacheTtl = $options['cache_ttl'] ?? 3600;
        $useCache = $options['use_cache'] ?? true;

        $cached = ['fresh' => null, 'stale' => null];

        if ($useCache === true && $this->cache instanceof CacheInterface) {
            $cacheKey = $this->buildCacheKey($url, $options);
            $cached = $this->cache->getWithStale($cacheKey);

            if ($cached['fresh'] !== null && is_string($cached['fresh']) === true) {
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
                && $cached['stale'] !== null
                && is_string($cached['stale']) === true
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
     * @param array  $options Proxy-specific options
     * @return array{body: string, type: string}
     * @throws \RuntimeException If fetch fails and no stale data is available
     */
    public function getBinary(string $url, array $options = []): array
    {
        $cacheTtl = $options['cache_ttl'] ?? 86400;
        $useCache = $options['use_cache'] ?? true;

        $cached = ['fresh' => null, 'stale' => null];

        if ($useCache === true && $this->cache instanceof CacheInterface) {
            $cacheKey = $this->buildCacheKey('binary_' . $url, $options);
            $cached = $this->cache->getWithStale($cacheKey);

            if ($this->isValidBinaryPayload($cached['fresh']) === true) {
                $this->log('debug', sprintf('Cache hit (fresh binary) for %s', $url));
                /** @var array{body: string, type: string} */
                return $cached['fresh'];
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
            if (
                $useCache === true
                && $this->cache instanceof CacheInterface
                && $this->isValidBinaryPayload($cached['stale']) === true
            ) {
                $this->log('warning', sprintf(
                    'Returning stale binary cache for %s due to proxy error: %s',
                    $url,
                    $e->getMessage()
                ));
                /** @var array{body: string, type: string} */
                return $cached['stale'];
            }

            throw $e;
        }
    }

    /**
     * Default binary fetcher - throws by default.
     * Child classes must override either doFetchBinary() or getBinary() directly.
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
        return empty($response) === false;
    }

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
        if (is_array($value) === false) {
            return false;
        }

        return isset($value['body']) === true
            && isset($value['type']) === true
            && is_string($value['body']) === true
            && is_string($value['type']) === true;
    }

    /**
     * Logger
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger instanceof Logger === false) {
            return;
        }

        $fullMessage = sprintf('[Proxy: %s] %s', $this->getName(), $message);

        if (method_exists($this->logger, $level) === true) {
            $this->logger->$level($fullMessage, $context);
        } else {
            $this->logger->info($fullMessage, $context);
        }
    }

    /**
     * A general-purpose method for HTTP requests with retries and exponential backoff.
     * Used by child classes (e.g., FlareSolverrProxy) for API calls.
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

    abstract protected function fetchHtml(string $url, array $options): string;

    abstract protected function executeRequest(string $method, string $url, array $payload, array $headers): array;
}
