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
 * Supported values: scalars, arrays (of scalars/arrays), and a single top-level
 * Response object. Any other object — including objects nested inside arrays or
 * a Response nested inside an array — is rejected with an InvalidArgumentException.
 * Objects are never silently serialized, because json_encode() would drop private
 * state and read-back would silently change their type (object -> array).
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
     * @throws \InvalidArgumentException If the value contains unsupported objects.
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

        $freshUntilRaw = $decoded['fresh_until'] ?? 0;
        $freshUntil = 0;
        if (is_int($freshUntilRaw) === true) {
            $freshUntil = $freshUntilRaw;
        } elseif (is_string($freshUntilRaw) === true && is_numeric($freshUntilRaw) === true) {
            $freshUntil = (int) $freshUntilRaw;
        }

        $now = time();
        $isFresh = ($freshUntil === 0 || $freshUntil > $now);

        return [
            'fresh' => $isFresh === true ? $value : null,
            'stale' => $value,
        ];
    }

    /**
     * Converts a top-level Response into its array representation and validates
     * that everything else contains only scalars and arrays.
     *
     * Binary data (images, files) in a Response is automatically base64-encoded to
     * ensure valid UTF-8 for JSON serialization.
     *
     * @param mixed $value The value to normalize.
     * @param bool  $isRoot Whether this is the top-level value (only the root may be a Response).
     * @return mixed The normalized value (arrays/scalars pass through unchanged).
     * @throws \InvalidArgumentException If an unsupported object is encountered.
     */
    private function normalizeForJson(mixed $value, bool $isRoot = true): mixed
    {
        if ($value instanceof Response) {
            if ($isRoot === false) {
                throw new \InvalidArgumentException(
                    'Nested Response objects are not supported by the cache. Cache the Response as the top-level value instead.'
                );
            }
            return $this->responseToArray($value);
        }

        if (is_object($value) === true) {
            throw new \InvalidArgumentException(
                'Cache only supports scalars, arrays, and Response objects. Convert objects to arrays before caching (e.g., via json_encode or toArray).'
            );
        }

        if (is_array($value) === true) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->normalizeForJson($item, false);
            }
        }

        return $value;
    }

    /**
     * Builds the storable array representation of a Response, base64-encoding
     * binary bodies so the result is always valid UTF-8 for JSON.
     *
     * @return array<string, mixed>
     */
    private function responseToArray(Response $value): array
    {
        $body = $value->getBody();
        $headers = $value->getHeaders();

        $isBinary = mb_check_encoding($body, 'UTF-8') === false;

        if ($isBinary === true) {
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
            $bodyRaw = $value['body'] ?? '';
            $body = is_string($bodyRaw) === true ? $bodyRaw : '';

            $encoding = $value['body_encoding'] ?? 'utf8';
            if ($encoding === 'base64') {
                $decoded = base64_decode($body, true);
                $body = ($decoded !== false) ? $decoded : '';
            }

            $codeRaw = $value['code'] ?? 200;
            $code = 200;
            if (is_int($codeRaw) === true) {
                $code = $codeRaw;
            } elseif (is_string($codeRaw) === true && is_numeric($codeRaw) === true) {
                $code = (int) $codeRaw;
            }

            // Rebuild a strictly typed header map: array<string, string|list<string>>.
            $headersRaw = $value['headers'] ?? [];
            $headers = [];
            if (is_array($headersRaw) === true) {
                foreach ($headersRaw as $name => $headerValue) {
                    if (is_string($name) === false) {
                        continue;
                    }

                    if (is_string($headerValue) === true) {
                        $headers[$name] = $headerValue;
                    } elseif (is_array($headerValue) === true) {
                        $list = [];
                        foreach ($headerValue as $item) {
                            if (is_string($item) === true) {
                                $list[] = $item;
                            }
                        }
                        $headers[$name] = $list;
                    }
                }
            }

            return new Response($body, $code, $headers);
        }

        return $value;
    }
}
