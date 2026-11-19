<?php

/**
 * Media embedding utilities for RSS feed items.
 *
 * This file provides functions for fetching remote media (images, videos, etc.)
 * and embedding them as data URIs directly in feed content. This approach:
 *
 * - Eliminates hotlinking issues (readers don't need to fetch from original host)
 * - Bypasses CORS/referrer restrictions in RSS readers
 * - Preserves media even if the original source goes offline
 * - Reduces requests to the original server after initial fetch
 *
 * The main entry point is media_embed_url_to_data_uri(), which handles:
 * - Fetching binary content (with optional retry and proxy support)
 * - MIME type detection (from extension or HTTP headers)
 * - Base64 encoding into data URI format
 * - Optional size limits (returns original URL if exceeded)
 * - Optional persistent caching (via CacheInterface)
 * - A short negative cache so failed URLs are not retried on every regeneration
 *
 * Loading mechanism: registered in composer.json "files" autoload,
 * so the functions are available globally without any require.
 */

declare(strict_types=1);

use RSSBridge\Caches\CacheInterface;
use RSSBridge\Http\Response;
use RSSBridge\Logger\Logger;

/**
 * Map of file extensions to MIME types for media embedding.
 *
 * @var array<string, string>
 */
const MEDIA_EMBED_MIME_TYPES = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'avif' => 'image/avif',
    'svg'  => 'image/svg+xml',
    'mp4'  => 'video/mp4',
    'webm' => 'video/webm',
    'mp3'  => 'audio/mpeg',
    'ogg'  => 'audio/ogg',
    'wav'  => 'audio/wav',
];

/**
 * Negative-cache lifetime for transient fetch failures (seconds).
 * Kept short so a feed self-heals within a couple of regeneration cycles.
 */
const MEDIA_EMBED_FAIL_TTL = 900;

/**
 * Extra jitter added to the transient negative-cache TTL (seconds),
 * so concurrent feeds do not all retry the same dead URL at once.
 */
const MEDIA_EMBED_FAIL_JITTER = 300;

/**
 * Negative-cache lifetime for deterministic "too large" rejections (seconds).
 * The size of a given CDN object does not change, so this can be long.
 */
const MEDIA_EMBED_SIZE_FAIL_TTL = 21600;

/**
 * Convert a remote media URL into an embedded data URI.
 *
 * Fetches the media, detects its MIME type, encodes it as base64, and
 * returns a data URI suitable for embedding directly in HTML src/href
 * attributes. Supports persistent caching and size limits.
 *
 * Behavior:
 * - If $maxSize > 0 and the fetched content exceeds it, returns the original URL.
 * - If $cache is provided, the result is cached under key 'embed_' . md5($url).
 * - If fetching fails, returns the original URL as fallback and records a
 *   short-lived negative cache entry so the URL is not retried immediately.
 *
 * @param string $url The remote media URL to fetch.
 * @param CacheInterface|null $cache Optional cache for persistent storage.
 * @param int $cacheTtl Cache lifetime in seconds (default 7 days).
 * @param int $maxSize Maximum allowed size in bytes (0 = unlimited).
 * @param string|null $proxyProfile Optional proxy profile name for protected fetch.
 * @param Logger|null $logger Optional logger for warnings on fetch failures.
 * @param int $retries Number of retry attempts on transient failures.
 * @param array<string, mixed> $options Extra options forwarded to the proxy fetch
 *                                      (e.g. 'timeout', 'cache_ttl').
 * @return string The data URI, or the original URL on failure/size limit.
 */
function media_embed_url_to_data_uri(
    string $url,
    ?CacheInterface $cache = null,
    int $cacheTtl = 604800,
    int $maxSize = 0,
    ?string $proxyProfile = null,
    ?Logger $logger = null,
    int $retries = 1,
    array $options = []
): string {
    if ($url === '') {
        return '';
    }

    $cacheKey = 'embed_' . md5($url);

    // 1. Check persistent cache first
    if ($cache !== null) {
        $cached = $cache->get($cacheKey);
        if (is_string($cached) === true && $cached !== '') {
            return $cached;
        }

        // Recently failed? Skip the fetch entirely for a while.
        if (media_embed_is_neg_cached($cache, $url) === true) {
            return $url;
        }
    }

    // 2. Fetch binary content (size limit enforced during transfer when possible)
    $media = media_embed_fetch_binary($url, $proxyProfile, $logger, $retries, $maxSize, $options);
    if ($media === null) {
        if ($cache !== null) {
            media_embed_record_failure($cache, $url, 'fetch');
        }
        return $url;
    }

    // 3. Check size limit (safety net; the fetch already aborts oversized transfers)
    if ($maxSize > 0 && strlen($media['body']) > $maxSize) {
        if ($cache !== null) {
            media_embed_record_failure($cache, $url, 'size');
        }
        return $url;
    }

    // 4. Build data URI
    $mime = $media['type'];
    if ($mime === '' || $mime === 'application/octet-stream') {
        $mime = media_embed_mime_from_url($url);
    }
    if ($mime === '') {
        return $url;
    }

    $dataUri = sprintf('data:%s;base64,%s', $mime, base64_encode($media['body']));

    // 5. Persist to cache
    if ($cache !== null) {
        $cache->set($cacheKey, $dataUri, $cacheTtl);
    }

    return $dataUri;
}

/**
 * Whether a URL is currently under a negative-cache marker.
 */
function media_embed_is_neg_cached(CacheInterface $cache, string $url): bool
{
    $marker = $cache->get('embed_fail_' . md5($url));
    return is_array($marker) === true;
}

/**
 * Record a fetch failure so the URL is not retried on every regeneration.
 *
 * @param string $reason 'size' for deterministic oversize rejections,
 *                       anything else for transient failures.
 */
function media_embed_record_failure(CacheInterface $cache, string $url, string $reason): void
{
    if ($reason === 'size') {
        $ttl = MEDIA_EMBED_SIZE_FAIL_TTL;
    } else {
        $ttl = MEDIA_EMBED_FAIL_TTL + random_int(0, MEDIA_EMBED_FAIL_JITTER);
    }

    $cache->set('embed_fail_' . md5($url), [
        'reason' => $reason,
        'time'   => time(),
    ], $ttl);
}

/**
 * Fetch binary content from a remote URL with optional retry and proxy.
 *
 * Returns an array with the body and detected MIME type, or null on failure.
 * Images are automatically sanitized to strip EXIF metadata and neutralize
 * polyglot attacks.
 *
 * When a proxy profile is given, the protected fetch is the only path used:
 * a direct fallback would bypass the proxy and (for blocked hosts) is
 * guaranteed to fail, wasting time and load. The proxy itself may still
 * perform its own configured direct fallback internally.
 *
 * @param string $url The URL to fetch.
 * @param string|null $proxyProfile Optional proxy profile name.
 * @param Logger|null $logger Optional logger for warnings.
 * @param int $retries Number of retry attempts.
 * @param int $maxSize Maximum allowed size in bytes (0 = unlimited).
 * @param array<string, mixed> $options Extra options forwarded to the proxy fetch.
 * @return array{body: string, type: string}|null The fetched content or null on failure.
 */
function media_embed_fetch_binary(
    string $url,
    ?string $proxyProfile = null,
    ?Logger $logger = null,
    int $retries = 1,
    int $maxSize = 0,
    array $options = []
): ?array {
    if ($retries < 1) {
        $retries = 1;
    }

    $lastException = null;

    for ($attempt = 1; $attempt <= $retries; $attempt++) {
        try {
            // Protected fetch via proxy if a profile is specified.
            if ($proxyProfile !== null && $proxyProfile !== '' && function_exists('getProtectedBinary') === true) {
                $fetchOptions = $options;
                if ($maxSize > 0) {
                    $fetchOptions['max_size'] = $maxSize;
                }

                /** @var array{body: string, type: string}|null $data */
                $data = getProtectedBinary($url, $proxyProfile, $fetchOptions);
                if ($data !== null) {
                    if (str_starts_with($data['type'], 'image/') === true) {
                        $sanitized = sanitize_image($data['body']);
                        if ($sanitized !== $data['body']) {
                            $data['body'] = $sanitized;
                            $data['type'] = 'image/webp';
                        }
                    }
                    return $data;
                }

                // Proxy was requested but failed: do NOT fall back to a direct
                // fetch here. The proxy owns any direct fallback it is configured
                // for; a blind direct attempt to a blocked host only adds latency.
                return null;
            }

            // Direct fetch (only when no proxy profile was requested).
            $curlOptions = [];
            if ($maxSize > 0) {
                $curlOptions[CURLOPT_MAXFILESIZE] = $maxSize;
                $curlOptions[CURLOPT_NOPROGRESS] = false;
                $curlOptions[CURLOPT_XFERINFOFUNCTION] = function ($ch, $downloadSize, $downloaded, $uploadSize, $uploaded) use ($maxSize): int {
                    return $downloaded > $maxSize ? 1 : 0;
                };
            }

            $response = getContents($url, [], $curlOptions, true);

            $body = $response->getBody();

            if ($body === '') {
                throw new \RuntimeException('Empty response body');
            }

            if ($maxSize > 0 && strlen($body) > $maxSize) {
                throw new \RuntimeException(sprintf('Media exceeds size limit (%d bytes)', $maxSize));
            }

            $headers = $response->getHeaders();
            $contentType = $headers['content-type'][0] ?? 'application/octet-stream';
            $type = trim(explode(';', $contentType)[0]);

            if (str_starts_with($type, 'image/') === true) {
                $sanitized = sanitize_image($body);
                if ($sanitized !== $body) {
                    $body = $sanitized;
                    $type = 'image/webp';
                }
            }

            return [
                'body' => $body,
                'type' => $type,
            ];
        } catch (\Throwable $e) {
            $lastException = $e;
            if ($logger !== null) {
                $logger->warning(sprintf(
                    'media_embed_fetch_binary failed (attempt %d/%d) for %s: %s',
                    $attempt,
                    $retries,
                    $url,
                    $e->getMessage()
                ));
            }
            if ($attempt < $retries) {
                usleep($attempt * 1000000); // 1s, 2s, 3s backoff
            }
        }
    }

    if ($logger !== null && $lastException !== null) {
        $logger->warning(sprintf(
            'media_embed_fetch_binary exhausted retries for %s: %s',
            $url,
            $lastException->getMessage()
        ));
    }

    return null;
}

/**
 * Detect MIME type from a URL's file extension.
 *
 * Falls back to 'application/octet-stream' if the extension is unknown.
 *
 * @param string $url The URL to inspect.
 * @return string The MIME type, or empty string if not detectable.
 */
function media_embed_mime_from_url(string $url): string
{
    // Strip query string and fragment
    $cleanUrl = $url;
    $qpos = strpos($cleanUrl, '?');
    if ($qpos !== false) {
        $cleanUrl = substr($cleanUrl, 0, $qpos);
    }
    $hpos = strpos($cleanUrl, '#');
    if ($hpos !== false) {
        $cleanUrl = substr($cleanUrl, 0, $hpos);
    }

    $ext = strtolower(pathinfo($cleanUrl, PATHINFO_EXTENSION));
    return MEDIA_EMBED_MIME_TYPES[$ext] ?? '';
}

/**
 * Detect MIME type from a file extension string.
 *
 * @param string $ext The extension (with or without leading dot).
 * @return string The MIME type, or empty string if unknown.
 */
function media_embed_mime_from_extension(string $ext): string
{
    $ext = strtolower(ltrim($ext, '.'));
    return MEDIA_EMBED_MIME_TYPES[$ext] ?? '';
}

/**
 * Parse a human-readable size string into bytes.
 *
 * Supports formats like: '10m', '1.5g', '512k', '1024', '10 mb', '2GB'.
 * Returns 0 for empty or unparseable input.
 *
 * @param string|int|float $value The size value to parse.
 * @return int The size in bytes, or 0 if unparseable.
 */
function media_embed_parse_size(string|int|float $value): int
{
    $value = trim((string) $value);
    if ($value === '') {
        return 0;
    }

    if (preg_match('/^(\d+(?:\.\d+)?)\s*([kmg])?b?$/i', $value, $m) !== 1) {
        return (int) $value;
    }

    $mult = match (strtolower($m[2] ?? '')) {
        'k' => 1024,
        'm' => 1048576,
        'g' => 1073741824,
        default => 1,
    };

    return (int) round((float) $m[1] * $mult);
}
