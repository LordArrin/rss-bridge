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

    /**
     * Lease duration for the single-flight regeneration lock. Must exceed the
     * worst-case regeneration time (curl timeout cascade tops out around 90s
     * via request_terminate_timeout) so a hung-but-alive holder is never
     * duplicated, while a crashed holder unblocks the feed within ~2 minutes.
     */
    private const REGENERATION_LOCK_TTL = 120;

    /**
     * How long clients/proxies should reuse a stale-served response before
     * re-checking, while regeneration is in flight elsewhere.
     */
    private const STALE_RETRY_AFTER = 60;

    /**
     * Fallback freshness window for the Cache-Control header when the bridge
     * does not expose its own cache timeout (defensive: getCacheTimeout()
     * normally returns an int).
     */
    private const DEFAULT_MAX_AGE = 3600;

    public function __invoke(Request $request, callable $next): Response
    {
        // Skip caching for certain actions
        $action = $request->get('action', 'frontpage');
        if ($action !== 'display') {
            $response = $next($request);
            if ($response->getCode() === 200) {
                $response = $this->attachCacheHeaders($response, self::DEFAULT_MAX_AGE);
            }
            return $response;
        }

        // Build cache key from request parameters (must match DisplayAction's key)
        $cacheKey = $this->createCacheKey($request);

        // Try to get cached response
        $cachedResponse = $this->cache->get($cacheKey);
        if ($cachedResponse instanceof Response) {
            // Conditional request handling (304 Not Modified). ETag /
            // If-None-Match is checked first because it is the stronger
            // validator (exact byte identity, no clock-skew issues);
            // If-Modified-Since remains supported for older readers.
            $etag = $cachedResponse->getHeader('etag');
            $ifNoneMatch = $request->server('HTTP_IF_NONE_MATCH');
            if ($etag !== null && $ifNoneMatch !== null && self::etagMatches($ifNoneMatch, $etag) === true) {
                return new Response('', 304, self::createNotModifiedHeaders([
                    'etag' => $etag,
                    'last-modified' => $cachedResponse->getHeader('last-modified'),
                ]));
            }

            $ifModifiedSince = $request->server('HTTP_IF_MODIFIED_SINCE');
            $lastModified = $cachedResponse->getHeader('last-modified');
            if ($ifModifiedSince !== null && $lastModified !== null) {
                $lastModifiedTimestamp = strtotime($lastModified);
                $modifiedSince = strtotime($ifModifiedSince);
                if ($lastModifiedTimestamp !== false && $modifiedSince !== false && $lastModifiedTimestamp <= $modifiedSince) {
                    $modificationTimeGMT = gmdate('D, d M Y H:i:s ', $lastModifiedTimestamp) . 'GMT';
                    return new Response('', 304, self::createNotModifiedHeaders([
                        'last-modified' => $modificationTimeGMT,
                        'etag' => $etag,
                    ]));
                }
            }
            return $this->attachCacheHeaders(
                $cachedResponse,
                $this->resolveMaxAge($request),
                $etag
            );
        }

        // Stale-while-revalidate with single-flight regeneration.
        //
        // Under load from many readers hitting the same feed at (or just
        // after) TTL expiry, every one of them used to run the full bridge:
        // N concurrent upstream fetches per feed, which multiplies latency,
        // exhausts the php-fpm pool and gets the instance rate-limited by
        // the upstream. Now only the lock winner regenerates; everyone else
        // keeps serving the expired-but-valid copy tagged as stale. The
        // lock is a short lease (auto-expires), so a crashed worker can
        // never wedge a feed.
        $staleEntry = $this->cache->getWithStale($cacheKey);
        $staleResponse = $staleEntry['stale'] ?? null;
        if ($staleResponse instanceof Response && $staleResponse->getCode() === 200) {
            if ($this->cache->acquireLock($cacheKey, self::REGENERATION_LOCK_TTL) === false) {
                return $this->attachStaleHeaders($staleResponse);
            }
        }

        // Execute the next middleware/action. DisplayAction computes the
        // effective per-feed freshness window while rendering and stores it
        // as an attribute on this shared Request object; reading it here
        // keeps the client-facing max-age exactly in sync with the
        // server-side cache TTL without re-resolving bridge defaults.
        $response = $next($request);

        $code = $response->getCode();

        if ($code === 200) {
            $response = $this->attachCacheHeaders(
                $response,
                $this->resolveMaxAge($request),
                $response->getHeader('etag')
            );
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
            return $this->attachStaleHeaders($stale);
        }

        return null;
    }

    /**
     * Returns the stale copy with cache headers that discourage readers and
     * proxies from hammering us while another worker regenerates:
     *   - Age reflects how far past freshness the payload is.
     *   - Cache-Control: stale-while-revalidate advertises that serving the
     *     expired copy is intentional.
     *   - Expires pushes browser/proxy rechecks to roughly one bridge TTL
     *     ahead instead of "immediately".
     * The last-modified header of the stale payload drives the staleness
     * estimate; conditional (304) handling keeps working against it.
     */
    private function attachStaleHeaders(Response $stale): Response
    {
        $headers = [
            'x-feed-stale' => '1',
            'age' => (string) self::estimateStaleAge($stale),
            'cache-control' => 'public, max-age=' . self::STALE_RETRY_AFTER . ', stale-while-revalidate=' . self::REGENERATION_LOCK_TTL,
        ];

        $lastModified = $stale->getHeader('last-modified');
        if ($lastModified !== null) {
            $lmTimestamp = strtotime($lastModified);
            if ($lmTimestamp !== false) {
                $headers['expires'] = gmdate('D, d M Y H:i:s ', $lmTimestamp + self::STALE_RETRY_AFTER) . 'GMT';
            }
        }

        foreach ($headers as $name => $value) {
            $stale = $stale->withHeader($name, $value);
        }

        return $stale;
    }

    /**
     * Approximates the staleness of a cached response from its last-modified
     * header (set when the feed was rendered successfully).
     */
    private static function estimateStaleAge(Response $response): int
    {
        $lastModified = $response->getHeader('last-modified');
        if ($lastModified === null) {
            return 0;
        }
        $timestamp = strtotime($lastModified);
        if ($timestamp === false) {
            return 0;
        }
        return max(0, time() - $timestamp);
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

    /**
     * Resolves the freshness window advertised to clients/proxies via
     * Cache-Control: max-age for a feed response.
     *
     * Priority:
     *  1. the TTL computed by DisplayAction and attached to the shared
     *     Request as '_resolved_cache_timeout' (accounts for the bridge's
     *     own CACHE_TIMEOUT and the custom_timeout config/query parameter),
     *  2. an explicit ?_cache_timeout query parameter,
     *  3. the DEFAULT_MAX_AGE fallback.
     */
    private function resolveMaxAge(Request $request): int
    {
        $attribute = $request->getAttribute('_resolved_cache_timeout');
        if ($attribute !== null && (int) $attribute > 0) {
            return min(86400, max(1, (int) $attribute));
        }

        $param = $request->get('_cache_timeout');
        if ($param !== null && (int) $param > 0) {
            return min(86400, max(1, (int) $param));
        }

        return self::DEFAULT_MAX_AGE;
    }

    /**
     * Decorates a successful (200) response with cache-control headers for
     * downstream caches:
     *   - Cache-Control: public, max-age=<freshness>, stale-while-revalidate
     *     tells browsers and proxies how long the payload may be reused
     *     without asking us, and that serving a slightly expired copy while
     *     another worker regenerates it is intentional. This is what removes
     *     the bulk of repeated full-feed traffic for popular feeds.
     *   - Expires mirrors max-age as an absolute date for HTTP/1.0 caches.
     *   - ETag is echoed (and back-filled from the body for legacy entries
     *     stored before validators existed) so clients can revalidate with
     *     If-None-Match and receive cheap 304 responses.
     *   - Vary documents the request headers that legitimately change the
     *     representation (auth/token-gated feeds, gzip negotiation), keeping
     *     shared caches from mixing variants.
     */
    private function attachCacheHeaders(Response $response, int $maxAge, ?string $etag = null): Response
    {
        if ($response->getCode() !== 200) {
            return $response;
        }

        if (($etag === null || $etag === '') === true) {
            // Back-fill a strong validator over the exact bytes we send.
            $etag = '"' . md5($response->getBody()) . '"';
        }

        $staleWhileRevalidate = max(self::REGENERATION_LOCK_TTL, intdiv($maxAge, 4));

        $response = $response
            ->withHeader('etag', $etag)
            ->withHeader(
                'cache-control',
                sprintf('public, max-age=%d, stale-while-revalidate=%d', $maxAge, $staleWhileRevalidate)
            )
            ->withHeader('expires', gmdate('D, d M Y H:i:s ', time() + $maxAge) . 'GMT')
            ->withHeader('vary', 'Accept-Encoding, Accept-Language, Cookie');

        return $response;
    }

    /**
     * RFC 9110 section 13.2.2 If-None-Match evaluation: supports "*",
     * comma-separated lists and the weak/strong comparison rules (a weak
     * tag matches a strong one when the opaque entity-tags are equal).
     */
    private static function etagMatches(string $ifNoneMatch, string $etag): bool
    {
        $ifNoneMatch = trim($ifNoneMatch);
        if ($ifNoneMatch === '*') {
            return true;
        }

        $target = self::normalizeEtag($etag);

        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = self::normalizeEtag(trim($candidate));
            if ($candidate !== '' && $candidate === $target) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strips the W/ weakness prefix and surrounding quotes so tags compare
     * on their opaque value.
     */
    private static function normalizeEtag(string $etag): string
    {
        $etag = trim($etag);
        if (str_starts_with($etag, 'W/') === true || str_starts_with($etag, 'w/') === true) {
            $etag = substr($etag, 2);
        }
        return trim($etag, "\"");
    }

    /**
     * Builds the header set for a 304 Not Modified reply. Per RFC 9110 the
     * response must carry the current validators (ETag, Last-Modified) plus
     * Vary, and no payload-related headers.
     *
     * @param array<string, string|null> $validators
     * @return array<string, string>
     */
    private static function createNotModifiedHeaders(array $validators): array
    {
        $headers = ['vary' => 'Accept-Encoding, Accept-Language, Cookie'];
        foreach ($validators as $name => $value) {
            if (is_string($value) === true && $value !== '') {
                $headers[$name] = $value;
            }
        }
        return $headers;
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
