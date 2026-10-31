<?php

declare(strict_types=1);

namespace RSSBridge\Caches;

use RSSBridge\Http\Response;

/**
 * Abstract base class for all cache implementations.
 *
 * Provides JSON serialization/deserialization logic to avoid code duplication
 * and ensure consistent handling of non-JSON-serializable objects (like Response).
 *
 * All concrete cache classes should extend this class.
 */
abstract class AbstractCache implements CacheInterface
{
    /**
     * Cache format version. Bump this when the JSON structure changes
     * to force invalidation of old entries.
     */
    protected const CACHE_FORMAT_VERSION = 2;

    /**
     * How long stale data is retained after fresh TTL expires (7 days).
     */
    protected const DEFAULT_STALE_TTL = 604800;

    /**
     * Creates a hashed cache key from a string.
     *
     * @param string $key The raw cache key.
     * @return string Binary SHA-256 hash (32 bytes).
     */
    protected function createCacheKey(string $key): string
    {
        return hash('sha256', $key, true);
    }

    /**
     * Encodes a value with metadata into a JSON string for storage.
     *
     * @param mixed    $value     The value to store (will be normalized for JSON).
     * @param int|null $freshUntil Unix timestamp when the value becomes stale, or null for forever.
     * @return string JSON-encoded string.
     * @throws \JsonException If encoding fails.
     */
    protected function encode(mixed $value, ?int $freshUntil): string
    {
        $wrapper = [
            '__v'         => self::CACHE_FORMAT_VERSION,
            'value'       => $this->normalizeForJson($value),
            'fresh_until' => $freshUntil,
        ];

        // JSON_INVALID_UTF8_SUBSTITUTE: replaces invalid UTF-8 sequences with U+FFFD
        // This provides defense-in-depth for any non-UTF8 strings that slipped through
        return json_encode(
            $wrapper,
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    /**
     * Decodes a JSON blob from storage and returns fresh/stale values.
     *
     * @param string $blob The JSON-encoded string from storage.
     * @param string $key  The original cache key (for logging).
     * @return array{fresh: mixed, stale: mixed} Associative array with 'fresh' and 'stale' keys.
     */
    protected function decode(string $blob, string $key): array
    {
        try {
            $decoded = json_decode($blob, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // Invalidation of legacy/corrupt cache entries.
            // We do NOT fall back to unserialize() to avoid Object Injection vulnerabilities.
            return ['fresh' => null, 'stale' => null];
        }

        if (is_array($decoded) === false || array_key_exists('value', $decoded) === false) {
            return ['fresh' => null, 'stale' => null];
        }

        $value = $this->restoreFromJson($decoded['value']);
        $freshUntil = (int) ($decoded['fresh_until'] ?? 0);
        $now = time();

        $isFresh = ($freshUntil === 0 || $freshUntil > $now);

        return [
            'fresh' => $isFresh === true ? $value : null,
            'stale' => $value,
        ];
    }

    /**
     * Converts non-JSON-serializable objects (like Response) into arrays
     * before storing them in the cache.
     *
     * Binary data (images, files) is automatically base64-encoded to ensure
     * valid UTF-8 for JSON serialization.
     *
     * @param mixed $value The value to normalize.
     * @return mixed The normalized value (arrays/scalars pass through unchanged).
     */
    private function normalizeForJson(mixed $value): mixed
    {
        if ($value instanceof Response) {
            $body = $value->getBody();
            $headers = $value->getHeaders();

            // Check if body contains binary data (not valid UTF-8)
            // mb_check_encoding returns false for binary data like images
            $isBinary = mb_check_encoding($body, 'UTF-8') === false;

            if ($isBinary === true) {
                // Encode binary data as base64 to make it JSON-safe
                return [
                    '__type' => 'Http\\Response',
                    'code' => $value->getCode(),
                    'headers' => $headers,
                    'body' => base64_encode($body),
                    'body_encoding' => 'base64',
                ];
            }

            return [
                '__type' => 'Http\\Response',
                'code' => $value->getCode(),
                'headers' => $headers,
                'body' => $body,
                'body_encoding' => 'utf8',
            ];
        }

        return $value;
    }

    /**
     * Restores objects from their array representation after retrieving
     * them from the cache.
     *
     * Base64-encoded binary data is automatically decoded back to raw bytes.
     *
     * @param mixed $value The decoded value from JSON.
     * @return mixed The restored object or original value.
     */
    private function restoreFromJson(mixed $value): mixed
    {
        if (is_array($value) === true && ($value['__type'] ?? null) === 'Http\\Response') {
            $body = (string) ($value['body'] ?? '');
            $encoding = $value['body_encoding'] ?? 'utf8';

            if ($encoding === 'base64') {
                $decoded = base64_decode($body, true);
                $body = ($decoded !== false) ? $decoded : '';
            }

            return new Response(
                $body,
                (int) ($value['code'] ?? 200),
                (array) ($value['headers'] ?? [])
            );
        }

        return $value;
    }
}
