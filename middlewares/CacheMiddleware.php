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

    private const REMOVE_KEYS = ['token', 'action'];

    public function __invoke(Request $request, callable $next): Response
    {
        // Skip caching for certain actions
        $action = $request->get('action', 'frontpage');
        if ($action !== 'display') {
            return $next($request);
        }

        // Build cache key from request parameters (must match DisplayAction's key)
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

        $code = $response->getCode();

        if ($code === 200) {
            // A bridge timeout of 0 means "never cache" (e.g. broken bridges,
            // which must be retried immediately after a deployment fix).
            // Drop any stale negative entry so the fixed bridge recovers at
            // once instead of serving a cached error for up to the negative
            // caching TTL below.
            if ((int) $request->get('_cache_timeout', -1) === 0) {
                $this->cache->delete($cacheKey);
            }
            // Success responses are already stored by DisplayAction under this
            // same key with the bridge's own cache timeout. Re-storing them
            // here would overwrite that TTL with a fixed one and double the
            // cache writes, so only persist error responses (short-TTL
            // negative caching).
            return $response;
        }

        if (in_array($code, [400, 403, 404, 429, 500, 503], true) === true) {
            // Broken-bridge errors (displayed via the stub path in
            // DisplayAction) must never be negatively cached: retry after
            // deploy fix is the whole point of the stub design.
            if ($this->isBrokenBridgeRequest($request) === true) {
                $this->cache->delete($cacheKey);
                return $response;
            }

            // Negative caching must be short: a stale 429/500 would block the
            // feed for every user while upstream is temporarily unavailable.
            $ttl = 60 + random_int(0, 120);
            $this->cache->set($cacheKey, $response, $ttl);
        }

        // For 1% of requests, prune cache (FileCache/SQLite cleanup)
        if (random_int(1, 100) === 1) {
            $this->cache->prune();
        }

        return $response;
    }

    /**
     * Detects whether the requested bridge failed to load and was replaced
     * by a BrokenBridgeStub (see SafeBridgeLoader::createSafely()).
     *
     * The check is intentionally cheap: it only looks at already-declared
     * classes (no autoloading, no instantiation) so it never triggers the
     * sandbox loading machinery. If the stub class has not been declared in
     * this request, the bridge cannot be broken.
     */
    private function isBrokenBridgeRequest(Request $request): bool
    {
        $bridgeName = $request->get('bridge');
        if (!is_string($bridgeName) || $bridgeName === '') {
            return false;
        }

        // No stub was created during this request -> nothing is broken.
        if (class_exists(\RSSBridge\BrokenBridgeStub::class, false) === false) {
            return false;
        }

        // Match against the original name stored by the stub:
        // 'TelegramBridge (Broken)' => 'telegrambridge'.
        $normalized = strtolower(\RSSBridge\BridgeFactory::normalizeBridgeName($bridgeName));

        foreach (get_declared_classes() as $className) {
            if (is_subclass_of($className, \RSSBridge\BrokenBridgeStub::class) === false) {
                continue;
            }
            $stubName = substr($className, -strlen(' (Broken)'));
            if (strtolower(\RSSBridge\BridgeFactory::normalizeBridgeName($stubName)) === $normalized) {
                return true;
            }
        }

        return false;
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
