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

        // ?_cache_timeout=0 is the documented cache-bypass: it must always
        // hit the bridge. Serving cached or stale content here would
        // silently ignore the freshness contract FreshRSS (and
        // intermediaries revalidating with max-stale=0) rely on.
        $bypassCache = (int) $request->get('_cache_timeout', -1) === 0;

        if ($bypassCache === false) {
            $cachedResponse = $this->cache->get($cacheKey);

            if ($cachedResponse instanceof Response) {
                if ($cachedResponse->getCode() === 200) {
                    $notModified = $this->createNotModifiedResponse($request, $cachedResponse);
                    if ($notModified !== null) {
                        return $notModified;
                    }
                }
                return $this->attachCacheHeaders($cachedResponse, $this->resolveMaxAge($request), $cachedResponse->getHeader('etag'));
            }
        }

        if ($bypassCache === false) {
            $staleEntry = $this->cache->getWithStale($cacheKey);
            $staleResponse = $staleEntry['stale'] ?? null;
            if ($staleResponse instanceof Response && $staleResponse->getCode() === 200) {
                // Single-flight regeneration: only one process may refresh an
                // expired entry at a time. If the lease is held by another
                // process, serve stale (marked with X-Feed-Stale) instead of
                // stampeding upstream. acquireLock() fails open (returns true)
                // when the lock layer itself is broken, so feeds keep
                // regenerating even if memcached is down.
                if ($this->cache->acquireLock($cacheKey, self::REGENERATION_LOCK_TTL) === false) {
                    return $this->attachStaleHeaders($staleResponse);
                }
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
            if ($bypassCache === false) {
                $stale = $this->serveStale($cacheKey, $response);
                if ($stale !== null) {
                    $retryAfter = min(max(self::STALE_RETRY_AFTER, $this->resolveMaxAge($request)), 3600);
                    return $stale->withHeader('retry-after', (string) $retryAfter);
                }
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

    private function isTransientStatus(int $code): bool
    {
        return in_array($code, [0, 408, 429, 500, 502, 503, 504], true) === true;
    }

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

        if ($code === 0 || str_contains($response->getBody(), 'cURL error') === true) {
            $body = render(__DIR__ . '/../templates/error.html.php', [
                'title' => sprintf('%d %s', $status, \RSSBridge\Http\Response::STATUS_CODES[$status]),
                'message' => $isRateLimited === true ? 'The bridge was rate limited by the upstream service. Please retry later.' : 'The requested feed is temporarily unavailable. Please retry later.',
            ]);
        } else {
            $body = $response->getBody();
        }

        $normalized = new Response($body, $status, [
            'retry-after' => (string) $retryAfter,
            'cache-control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'pragma' => 'no-cache',
            'expires' => gmdate('D, d M Y H:i:s \G\M\T'),
        ]);

        foreach (['etag', 'last-modified'] as $name) {
            $normalized = $normalized->withoutHeader($name);
        }

        $contentType = $response->getHeader('content-type');
        if (is_string($contentType) === true && $contentType !== '') {
            $normalized = $normalized->withHeader('content-type', $contentType);
        }

        return $normalized;
    }

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
        if ($ifNoneMatch !== null) {
            return null;
        }

        if ($ifModifiedSince !== null && $lastModified !== null) {
            $lastModifiedTimestamp = strtotime($lastModified);
            $modifiedSince = strtotime($ifModifiedSince);
            if ($lastModifiedTimestamp !== false && $modifiedSince !== false && $lastModifiedTimestamp <= $modifiedSince) {
                $modificationTimeGMT = gmdate('D, d M Y H:i:s \G\M\T', $lastModifiedTimestamp);
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
        $headers['expires'] = gmdate('D, d M Y H:i:s \G\M\T', $lmTimestamp + $maxAge);

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
                ->withHeader('expires', gmdate('D, d M Y H:i:s \G\M\T', time() + $maxAge))
                ->withHeader('vary', 'Accept-Encoding');
        }

        return $response
            ->withHeader('cache-control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->withHeader('pragma', 'no-cache')
            ->withHeader('expires', gmdate('D, d M Y H:i:s \G\M\T'));
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
            $headers['date'] = gmdate('D, d M Y H:i:s \G\M\T');
        }
        return $headers;
    }
}
