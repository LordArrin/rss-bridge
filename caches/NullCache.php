<?php

declare(strict_types=1);

namespace RSSBridge\Caches;

/**
 * Null cache implementation.
 * Never stores anything, always returns default values.
 * Useful for debugging or when caching must be disabled entirely.
 */
final class NullCache implements CacheInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function getWithStale(string $key): array
    {
        return ['fresh' => null, 'stale' => null];
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
    }

    public function delete(string $key): void
    {
    }

    public function clear(): void
    {
    }

    public function prune(): void
    {
    }
}
