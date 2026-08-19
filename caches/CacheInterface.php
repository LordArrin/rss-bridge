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
}
