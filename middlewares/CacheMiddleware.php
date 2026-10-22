<?php

declare(strict_types=1);

namespace RSSBridge\Middlewares;

use RSSBridge\Http\HttpException;
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
            return $this->attachCacheHeaders($cachedResponse, $this->resolveMaxAge($request), $etag);
        }

        $staleEntry = $this->cache->getWithStale($cacheKey);
        $staleResponse = $staleEntry['stale'] ?? null;
        if ($staleResponse instanceof Response && $staleResponse->getCode() === 200) {
            if ($this->cache->acquireLock($cacheKey, self::REGENERATION_LOCK_TTL) === false) {
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

        if (in_array($code, [0, 429, 500, 502, 503, 504], true) === true || $this->isTimeoutResponse($response) === true) {
            $stale = $this->serveStale($cacheKey, $response);
            if ($stale !== null) {
                $retryAfter = min(max(self::STALE_RETRY_AFTER, $this->resolveMaxAge($request)), 3600);
                return $stale->withHeader('retry-after', (string) $retryAfter);
            }
        }

        if (in_array($code, [400, 403, 404, 429, 500, 503], true) === true) {
            if ($this->isBrokenBridgeRequest($request) === true) {
                $this->cache->delete($cacheKey);
                return $response;
            }

            if ($this->isTimeoutResponse($response) === true) {
                return $response;
            }

            $ttl = 60 + random_int(0, 120);

            $retryAfterHint = HttpException::parseRetryAfter($response->getHeader('retry-after'));
            // ИСПРАВЛЕНО: Удалена избыточная проверка `$code >= 400`, так как она всегда true в этом блоке
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
        if ($response->getCode() !== 200) {
            return $response;
        }

        if (($etag === null || $etag === '') === true) {
            $etag = '"' . md5($response->getBody()) . '"';
        }

        $staleWhileRevalidate = max(self::REGENERATION_LOCK_TTL, intdiv($maxAge, 4));

        return $response
            ->withHeader('etag', $etag)
            ->withHeader('cache-control', sprintf('public, max-age=%d, stale-while-revalidate=%d', $maxAge, $staleWhileRevalidate))
            ->withHeader('expires', gmdate('D, d M Y H:i:s ', time() + $maxAge) . 'GMT')
            ->withHeader('vary', 'Accept-Encoding, Accept-Language, Cookie');
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
        $headers = self::addDateHeader(['vary' => 'Accept-Encoding, Accept-Language, Cookie']);
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
