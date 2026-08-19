<?php

declare(strict_types=1);

use RSSBridge\Caches\CacheInterface;

abstract class ProxyAbstract implements ProxyInterface
{
    protected array $config;
    protected int $timeout = 180;
    protected int $maxRetries = 3;
    protected ?CacheInterface $cache = null;
    protected ?\Logger $logger = null;

    public function __construct(array $config = [])
    {
        $this->config = $config;
        
        global $container;
        if (isset($container['cache'])) {
            $this->cache = $container['cache'];
        }
        if (isset($container['logger'])) {
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
     * Gets HTML with automatic caching
     */
    public function getHtml(string $url, array $options = []): string
    {
        $cacheTtl = $options['cache_ttl'] ?? 3600;
        $useCache = $options['use_cache'] ?? true;
        
        if ($useCache && $this->cache) {
            $cacheKey = $this->buildCacheKey($url, $options);
            $cached = $this->cache->get($cacheKey);
            
            if ($cached !== null) {
                $this->log('debug', "Cache hit for {$url}");
                return $cached;
            }
        }

        $this->log('info', "Fetching {$url} via {$this->getName()}");
        $html = $this->fetchHtml($url, $options);

        if (!$this->validateResponse($html)) {
            throw new \RuntimeException("Proxy returned invalid response for {$url}");
        }

        if ($useCache && $this->cache && $cacheTtl > 0) {
            $cacheKey = $this->buildCacheKey($url, $options);
            $this->cache->set($cacheKey, $html, $cacheTtl);
        }

        return $html;
    }

    /**
     * Retrieves binary data via a proxy.
     * Not supported by default - overridden in child classes.
     */
    public function getBinary(string $url, array $options = []): array
    {
        throw new \RuntimeException('Binary downloads not supported by ' . $this->getName());
    }
    /**
     * Basic response validation.
     * Overridden in child classes for specific logic (e.g. Cloudflare check).
     */
    protected function validateResponse(string $response): bool
    {
        return !empty($response);
    }

    protected function buildCacheKey(string $url, array $options): string
    {
        return 'proxy_' . md5($url . serialize($options));
    }

    /**
     * Logger
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        if (!$this->logger) {
            return;
        }

        $fullMessage = "[Proxy: {$this->getName()}] {$message}";
        
        if (method_exists($this->logger, $level)) {
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

        throw new \RuntimeException("Proxy request failed after {$this->maxRetries} attempts: {$lastError}");
    }

    abstract protected function fetchHtml(string $url, array $options): string;

    abstract protected function executeRequest(string $method, string $url, array $payload, array $headers): array;
}