<?php

declare(strict_types=1);

namespace RSSBridge\Middlewares;

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

    /**
     * Parameters that must never influence the cache key:
     * - 'token': authentication secret — keeping it in the key would let anyone
     *   pollute the cache with unique keys (DoS) and leaks secrets into logs.
     * - 'action': routing only; display is the only cached action anyway.
     */
    private const REMOVE_KEYS = ['token', 'action'];

    public function __invoke(Request $request, callable $next): Response
    {
        // Skip caching for certain actions
        $action = $request->get('action', 'frontpage');
        if ($action !== 'display') {
            return $next($request);
        }

        // Build cache key from request parameters
        $cacheKey = $this->createCacheKey($request);

        // Try to get cached response
        $cachedResponse = $this->cache->get($cacheKey);
        if ($cachedResponse instanceof Response) {
            // Conditional request handling (304 Not Modified), as in upstream.
            $ifModifiedSince = $request->server('HTTP_IF_MODIFIED_SINCE');
            $lastModified = $cachedResponse->getHeader('last-modified');
            if ($ifModifiedSince !== null && $lastModified !== null) {
                $lastModifiedTimestamp = strtotime($lastModified);
                $modifiedSince = strtotime($ifModifiedSince);
                if ($lastModifiedTimestamp !== false && $modifiedSince !== false && $lastModifiedTimestamp <= $modifiedSince) {
                    $modificationTimeGMT = gmdate('D, d M Y H:i:s ', $lastModifiedTimestamp) . 'GMT';
                    return new Response('', 304, ['last-modified' => $modificationTimeGMT]);
                }
            }
            return $cachedResponse;
        }

        // Execute the next middleware/action
        $response = $next($request);

        // Successful responses are cached by DisplayAction itself (it knows the
        // correct TTL: bridge cache timeout / _cache_timeout). Do not write a
        // second copy here — that would double memory usage and desynchronize
        // TTLs between the two layers.
        // Error responses (4xx/5xx) are cached briefly to protect upstreams
        // from hammering on broken bridges (with jitter to avoid a thundering
        // herd on expiry).
        $code = $response->getCode();
        if (in_array($code, [400, 403, 404, 429, 500, 503], true) === true) {
            $ttl = 60 * 5 + random_int(1, 60 * 10);
            $this->cache->set($cacheKey, $response, $ttl);
        }

        // For 1% of requests, prune cache (FileCache/SQLite cleanup)
        if (random_int(1, 100) === 1) {
            $this->cache->prune();
        }

        return $response;
    }

    private function createCacheKey(Request $request): string
    {
        $params = $request->toArray();
        foreach (self::REMOVE_KEYS as $key) {
            unset($params[$key]);
        }
        ksort($params);
        return 'http_' . json_encode($params);
    }
}
