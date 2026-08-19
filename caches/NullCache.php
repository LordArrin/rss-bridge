<?php

declare(strict_types=1);

namespace RSSBridge\Caches;

/**
 * Null cache implementation.
 * Does not store anything, always returns default values.
 * Useful for testing or when caching should be disabled.
 */
final class NullCache implements CacheInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
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
