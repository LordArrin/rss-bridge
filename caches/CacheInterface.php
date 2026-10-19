<?php

declare(strict_types=1);

namespace RSSBridge\Caches;

/**
 * Cache interface for RSS-Bridge.
 *
 * All cache implementations must be final classes with readonly properties.
 * Follows PSR-16 SimpleCache conventions where applicable.
 */
interface CacheInterface
{
    /**
     * Fetch a value from the cache.
     *
     * @param string $key     The unique key of this item in the cache.
     * @param mixed  $default Default value to return if the key does not exist.
     * @return mixed The value of the item from the cache, or $default in case of cache miss.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Fetch both fresh and stale values from the cache.
     *
     * Returns an associative array with two keys:
     * - 'fresh': The value if it's still within its TTL, or null if expired/missing
     * - 'stale': The value regardless of TTL (for fallback purposes), or null if missing
     *
     * @param string $key The unique key of this item in the cache.
     * @return array{fresh: mixed, stale: mixed}
     */
    public function getWithStale(string $key): array;

    /**
     * Persist data in the cache, uniquely referenced by a key with an optional expiration TTL time.
     *
     * @param string   $key   The key of the item to store.
     * @param mixed    $value The value of the item to store, must be serializable.
     * @param int|null $ttl   Optional. The TTL value of this item in seconds.
     *                        If null, store forever. If 0, do not store.
     */
    public function set(string $key, mixed $value, ?int $ttl = null): void;

    /**
     * Delete an item from the cache by its unique key.
     *
     * @param string $key The unique cache key of the deleted item.
     */
    public function delete(string $key): void;

    /**
     * Wipe clean the entire cache's keys.
     */
    public function clear(): void;

    /**
     * Remove expired items from the cache.
     * Called periodically to free up space.
     */
    public function prune(): void;

    /**
     * Attempt to acquire an exclusive, auto-expiring lock for a key.
     *
     * Used for single-flight regeneration: when a cached feed goes stale,
     * only one process at a time may regenerate it while everyone else keeps
     * serving the stale copy (see CacheMiddleware). Implementations that
     * cannot coordinate across processes should return true so regeneration
     * simply proceeds without deduplication.
     *
     * @param string $key      The key to lock.
     * @param int    $ttlSeconds Lock lifetime; must exceed the worst-case
     *                           regeneration time so a crashed holder cannot
     *                           block the feed forever.
     * @return bool True when the lock was acquired (caller may regenerate),
     *              false when another process holds it.
     */
    public function acquireLock(string $key, int $ttlSeconds): bool;
}
