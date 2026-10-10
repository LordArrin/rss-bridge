<?php

declare(strict_types=1);

namespace RSSBridge\Middlewares;

use RSSBridge\Http\Request;
use RSSBridge\Http\Response;
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

        // Graceful degradation: when regenerating a feed fails because the
        // upstream is temporarily unavailable (cURL timeout errno 28 -> code
        // 0, HTTP 5xx from upstream, 429 rate limit), serve the last known
        // good response even if it is past its TTL. The reader sees slightly
        // stale content instead of an error, and the transient failure does
        // not get negatively cached on top of it.
        if (
            in_array($code, [0, 429, 500, 502, 503, 504], true) === true
            || $this->isTimeoutResponse($response) === true
        ) {
            $stale = $this->serveStale($cacheKey, $response);
            if ($stale !== null) {
                return $stale;
            }
        }

        if (in_array($code, [400, 403, 404, 429, 500, 503], true) === true) {
            // Broken-bridge errors (displayed via the stub path in
            // DisplayAction) must never be negatively cached: retry after
            // deploy fix is the whole point of the stub design.
            if ($this->isBrokenBridgeRequest($request) === true) {
                $this->cache->delete($cacheKey);
                return $response;
            }

            // Transient transport failures (cURL timeouts, DNS/connect
            // problems) carry no HTTP status (code 0). Caching them would
            // turn one flaky upstream into a guaranteed error window for
            // every reader — skip negative caching entirely.
            if ($this->isTimeoutResponse($response) === true) {
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
     * Serves the last known good (expired) response from cache when a
     * regeneration failed. Returns null when no stale success entry exists,
     * in which case the caller falls through to normal error handling.
     */
    private function serveStale(string $cacheKey, Response $failed): ?Response
    {
        // Never resurrect an error page as "stale good data".
        if ($failed->getCode() === 200) {
            return null;
        }

        $cached = $this->cache->getWithStale($cacheKey);
        $stale = $cached['stale'] ?? null;

        if ($stale instanceof Response && $stale->getCode() === 200) {
            // Tag the response so downstream consumers (and logs) can tell
            // stale-on-error apart from a fresh hit. withHeader lowercases
            // the name and returns an immutable clone.
            return $stale->withHeader('x-feed-stale', '1');
        }

        return null;
    }

    /**
     * Detects transport-level failures (cURL timeouts errno 28, DNS/connect
     * errors) inside an error response body. CurlHttpClient throws
     * HttpException with code 0 for these, and templates render the message.
     */
    private function isTimeoutResponse(Response $response): bool
    {
        if ($response->getCode() !== 0) {
            return false;
        }
        return str_contains($response->getBody(), 'cURL error') === true;
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
        if (is_string($bridgeName) === false || $bridgeName === '') {
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
