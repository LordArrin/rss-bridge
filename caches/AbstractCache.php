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

        return json_encode($wrapper, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
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
     * @param mixed $value The value to normalize.
     * @return mixed The normalized value (arrays/scalars pass through unchanged).
     */
    private function normalizeForJson(mixed $value): mixed
    {
        if ($value instanceof Response) {
            return [
                '__type' => 'Http\\Response',
                'code' => $value->getCode(),
                'headers' => $value->getHeaders(),
                'body' => $value->getBody(),
            ];
        }

        return $value;
    }

    /**
     * Restores objects from their array representation after retrieving
     * them from the cache.
     *
     * @param mixed $value The decoded value from JSON.
     * @return mixed The restored object or original value.
     */
    private function restoreFromJson(mixed $value): mixed
    {
        if (is_array($value) === true && ($value['__type'] ?? null) === 'Http\\Response') {
            return new Response(
                (string) ($value['body'] ?? ''),
                (int) ($value['code'] ?? 200),
                (array) ($value['headers'] ?? [])
            );
        }

        return $value;
    }
}
