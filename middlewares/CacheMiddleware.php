<?php

declare(strict_types=1);

namespace RSSBridge\Middlewares;

use Configuration;
use Request;
use Response;
use RSSBridge\Caches\CacheInterface;

final class CacheMiddleware implements Middleware
{
    private CacheInterface $cache;

    public function __construct(CacheInterface $cache)
    {
        $this->cache = $cache;
    }

    public function __invoke(Request $request, callable $next): Response
    {
        // Skip caching for certain actions
        $action = $request->get('action', 'display');
        if (in_array($action, ['frontpage', 'health', 'detect'], true)) {
            return $next($request);
        }

        // Build cache key from request parameters
        $cacheKey = $this->createCacheKey($request);

        // Try to get cached response
        $cachedResponse = $this->cache->get($cacheKey);
        if ($cachedResponse !== null) {
            return $cachedResponse;
        }

        // Execute the next middleware/action
        $response = $next($request);

        // Cache successful responses
        if ($response->getCode() === 200) {
            $ttl = Configuration::getConfig('cache', 'timeout') ?? 900;
            $this->cache->set($cacheKey, $response, $ttl);
        }

        return $response;
    }

    private function createCacheKey(Request $request): string
    {
        $params = $request->toArray();
        ksort($params);
        return 'response_' . md5(serialize($params));
    }
}
