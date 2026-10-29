<?php

declare(strict_types=1);

namespace RSSBridge\Middlewares;

use HttpException;
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
    private const REGENERATION_LOCK_TTL = 120;
    private const STALE_RETRY_AFTER = 60;
    private const DEFAULT_MAX_AGE = 3600;
    /** Default Retry-After (seconds) advertised with 503 responses. */
    private const SERVICE_UNAVAILABLE_RETRY_AFTER = 600;

    public function __invoke(Request $request, callable $next): Response
    {
        $action = $request->get('action', 'frontpage');
        if ($action !== 'display') {
            $response = $next($request);
            if ($response->getCode() === 200) {
                $response = $this->attachCacheHeaders($response, self::DEFAULT_MAX_AGE);
            }
            return $response;
        }

        $cacheKey = $this->createCacheKey($request);
        $cachedResponse = $this->cache->get($cacheKey);

        if ($cachedResponse instanceof Response) {
            // Conditional requests are only answered for cached *successful*
            // feeds. A 304 must never be returned for an error entry: feed
            // readers such as FreshRSS would interpret it as "no new items"
            // and hide the fact that the bridge is failing.
            if ($cachedResponse->getCode() === 200) {
                $notModified = $this->createNotModifiedResponse($request, $cachedResponse);
                if ($notModified !== null) {
                    return $notModified;
                }
            }
            return $this->attachCacheHeaders($cachedResponse, $this->resolveMaxAge($request), $cachedResponse->getHeader('etag'));
        }

        $staleEntry = $this->cache->getWithStale($cacheKey);
        $staleResponse = $staleEntry['stale'] ?? null;
        if ($staleResponse instanceof Response && $staleResponse->getCode() === 200) {
            if ($this->cache->acquireLock($cacheKey, self::REGENERATION_LOCK_TTL) === false) {
                // Another process is already regenerating this feed: serve the
                // stale-but-valid copy immediately instead of stampeding the
                // upstream site (which would invite real 429 rate limits).
                return $this->attachStaleHeaders($staleResponse);
            }
        }

        $response = $next($request);
        $code = $response->getCode();

        if ($code === 200) {
            $response = $this->attachCacheHeaders(
                $response,
                $this->resolveMaxAge($request),
                $response->getHeader('etag')
            );
            if ((int) $request->get('_cache_timeout', -1) === 0) {
                $this->cache->delete($cacheKey);
            }
            return $response;
        }

        if ($this->isTransientStatus($code) === true || $this->isTimeoutResponse($response) === true) {
            // Temporary problem (rate limit, timeout, upstream 5xx): never
            // surface it as a permanent error or as 304. Prefer serving a
            // stale copy of the feed; otherwise normalize the response to
            // HTTP 429/503 with Retry-After so readers like FreshRSS back off
            // and retry on the next polling cycle.
            $stale = $this->serveStale($cacheKey, $response);
            if ($stale !== null) {
                $retryAfter = min(max(self::STALE_RETRY_AFTER, $this->resolveMaxAge($request)), 3600);
                return $stale->withHeader('retry-after', (string) $retryAfter);
            }
            return $this->normalizeTransientResponse($response, $request);
        }

        if (in_array($code, [400, 403, 404], true) === true) {
            if ($this->isBrokenBridgeRequest($request) === true) {
                $this->cache->delete($cacheKey);
                return $response;
            }

            $ttl = 60 + random_int(0, 120);

            $retryAfterHint = \HttpException::parseRetryAfter($response->getHeader('retry-after'));
            if ($retryAfterHint !== null) {
                $ttl = max(30, min(1800, $retryAfterHint));
                $response = $response->withHeader('retry-after', (string) $ttl);
            }

            $this->cache->set($cacheKey, $response, $ttl);
        }

        if (random_int(1, 100) === 1) {
            $this->cache->prune();
        }

        return $response;
    }

    /**
     * Status codes that indicate a *temporary* failure which readers such as
     * FreshRSS should retry later, rather than a permanent bridge breakage.
     */
    private function isTransientStatus(int $code): bool
    {
        return in_array($code, [0, 408, 429, 500, 502, 503, 504], true) === true;
    }

    /**
     * Rewrites a transient failure response into a spec-compliant one:
     *  - 429 Too Many Requests keeps its (clamped) Retry-After
     *  - timeouts / 5xx become 503 Service Unavailable with Retry-After
     *  - negative caching uses Cache-Control: no-store (the middleware's own
     *    cache layer decides how long to remember the failure; HTTP caches
     *    must not extend it)
     */
    private function normalizeTransientResponse(Response $response, Request $request): Response
    {
        $code = $response->getCode();
        $isRateLimited = $code === 429;

        if ($isRateLimited === true) {
            $status = 429;
            $defaultRetry = max(self::STALE_RETRY_AFTER, min(3600, $this->resolveMaxAge($request)));
        } else {
            $status = 503;
            $defaultRetry = self::SERVICE_UNAVAILABLE_RETRY_AFTER;
        }

        $retryAfter = \HttpException::parseRetryAfter($response->getHeader('retry-after'));
        if ($retryAfter === null || $retryAfter < 1) {
            $retryAfter = $defaultRetry;
        }
        $retryAfter = max(1, min(86400, $retryAfter));

        // A code of 0 carries no meaningful status line/body for the client.
        if ($code === 0 || str_contains($response->getBody(), 'cURL error') === true) {
            $body = render(__DIR__ . '/../templates/error.html.php', [
                'title' => sprintf('%d %s', $status, \RSSBridge\Http\Response::STATUS_CODES[$status] ?? ''),
                'message' => $isRateLimited === true
                    ? 'The bridge was rate limited by the upstream service. Please retry later.'
                    : 'The requested feed is temporarily unavailable. Please retry later.',
            ]);
        } else {
            $body = $response->getBody();
        }

        $normalized = new Response($body, $status, [
            'retry-after' => (string) $retryAfter,
            'cache-control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'pragma' => 'no-cache',
            'expires' => gmdate('D, d M Y H:i:s') . ' GMT',
        ]);

        // Validators from the original response are meaningless for a
        // rewritten error status; drop them so clients never revalidate an
        // error as if it were a feed document.
        foreach (['etag', 'last-modified'] as $name) {
            $normalized = $normalized->withoutHeader($name);
        }

        $contentType = $response->getHeader('content-type');
        if (is_string($contentType) === true && $contentType !== '') {
            $normalized = $normalized->withHeader('content-type', $contentType);
        }

        return $normalized;
    }

    /**
     * Evaluates If-None-Match / If-Modified-Since against a cached 200
     * response and returns a RFC 9110 compliant 304 (no body, no content
     * headers) when the client copy is still valid. Returns null otherwise.
     */
    private function createNotModifiedResponse(Request $request, Response $cached): ?Response
    {
        $etag = $cached->getHeader('etag');
        $lastModified = $cached->getHeader('last-modified');

        $ifNoneMatch = $request->server('HTTP_IF_NONE_MATCH');
        if ($etag !== null && $ifNoneMatch !== null && self::etagMatches($ifNoneMatch, $etag) === true) {
            return new Response('', 304, self::createNotModifiedHeaders([
                'etag' => $etag,
                'cache-control' => $cached->getHeader('cache-control'),
                'vary' => $cached->getHeader('vary'),
                'last-modified' => $lastModified,
            ]));
        }

        $ifModifiedSince = $request->server('HTTP_IF_MODIFIED_SINCE');
        // Per RFC 9110, If-Modified-Since must be ignored when If-None-Match
        // was sent but did not match (the strong validator wins).
        if ($ifNoneMatch !== null) {
            return null;
        }

        if ($ifModifiedSince !== null && $lastModified !== null) {
            $lastModifiedTimestamp = strtotime($lastModified);
            $modifiedSince = strtotime($ifModifiedSince);
            if ($lastModifiedTimestamp !== false && $modifiedSince !== false && $lastModifiedTimestamp <= $modifiedSince) {
                $modificationTimeGMT = gmdate('D, d M Y H:i:s ', $lastModifiedTimestamp) . 'GMT';
                return new Response('', 304, self::createNotModifiedHeaders([
                    'last-modified' => $modificationTimeGMT,
                    'etag' => $etag,
                    'cache-control' => $cached->getHeader('cache-control'),
                    'vary' => $cached->getHeader('vary'),
                ]));
            }
        }

        return null;
    }

    private function serveStale(string $cacheKey, Response $failed): ?Response
    {
        if ($failed->getCode() === 200) {
            return null;
        }

        $cached = $this->cache->getWithStale($cacheKey);
        $stale = $cached['stale'] ?? null;

        if ($stale instanceof Response && $stale->getCode() === 200) {
            return $this->attachStaleHeaders($stale);
        }

        return null;
    }

    private function attachStaleHeaders(Response $stale): Response
    {
        $maxAge = self::STALE_RETRY_AFTER;

        $headers = [
            'x-feed-stale' => '1',
            'age' => (string) self::estimateStaleAge($stale),
            // A stale copy is only good for a short window: after max-age the
            // client must revalidate, which keeps FreshRSS polls honest.
            'cache-control' => sprintf(
                'public, max-age=%d, stale-while-revalidate=%d',
                $maxAge,
                self::REGENERATION_LOCK_TTL
            ),
        ];

        $lmTimestamp = strtotime((string) $stale->getHeader('last-modified'));
        if ($lmTimestamp === false) {
            $lmTimestamp = time();
        }
        // Expires must always accompany Cache-Control for HTTP/1.0 caches.
        $headers['expires'] = gmdate('D, d M Y H:i:s ', $lmTimestamp + $maxAge) . 'GMT';

        foreach ($headers as $name => $value) {
            $stale = $stale->withHeader($name, $value);
        }

        return $stale;
    }

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

    private function isTimeoutResponse(Response $response): bool
    {
        if ($response->getCode() !== 0) {
            return false;
        }
        return str_contains($response->getBody(), 'cURL error') === true;
    }

    private function isBrokenBridgeRequest(Request $request): bool
    {
        $bridgeName = $request->get('bridge');
        if (is_string($bridgeName) === false || $bridgeName === '') {
            return false;
        }

        if (class_exists(\RSSBridge\BrokenBridgeStub::class, false) === false) {
            return false;
        }

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

    private function attachCacheHeaders(Response $response, int $maxAge, ?string $etag = null): Response
    {
        if ($response->getCode() === 200) {
            if (($etag === null || $etag === '') === true) {
                $etag = '"' . md5($response->getBody()) . '"';
            }

            $staleWhileRevalidate = max(self::REGENERATION_LOCK_TTL, intdiv($maxAge, 4));

            return $response
                ->withHeader('etag', $etag)
                ->withHeader('cache-control', sprintf('public, max-age=%d, stale-while-revalidate=%d', $maxAge, $staleWhileRevalidate))
                ->withHeader('expires', gmdate('D, d M Y H:i:s ', time() + $maxAge) . 'GMT')
                ->withHeader('vary', 'Accept-Encoding');
        }

        // Error responses (4xx/5xx) must never be cached by shared caches or
        // clients: FreshRSS needs to see the real status code (429/503 with
        // Retry-After) on its very next poll, not a copy replayed by an
        // intermediate cache. Negative caching is handled exclusively by the
        // bridge's own cache layer.
        return $response
            ->withHeader('cache-control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->withHeader('pragma', 'no-cache')
            ->withHeader('expires', gmdate('D, d M Y H:i:s') . ' GMT');
    }

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

    private static function normalizeEtag(string $etag): string
    {
        $etag = trim($etag);
        if (str_starts_with($etag, 'W/') === true || str_starts_with($etag, 'w/') === true) {
            $etag = substr($etag, 2);
        }
        return trim($etag, '"');
    }

    private static function createNotModifiedHeaders(array $validators): array
    {
        $headers = self::addDateHeader(['vary' => 'Accept-Encoding']);
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

    private static function addDateHeader(array $headers): array
    {
        if (isset($headers['date']) === false) {
            $headers['date'] = gmdate('D, d M Y H:i:s') . ' GMT';
        }
        return $headers;
    }
}
