<?php

declare(strict_types=1);

namespace RSSBridge\Caches;

/**
 * Cache interface for RSS-Bridge.
 *
 * All cache implementations must be final classes.
 * Follows PSR-16 SimpleCache conventions where applicable.
 *
 * Implementations should extend AbstractCache to inherit JSON serialization
 * logic and avoid code duplication.
 */
interface CacheInterface
{
    /**
     * Fetch a value from the cache.
     *
     * @param string $key     The unique key of this item in the cache.
     * @param mixed  $default Default value to return if the key does not exist.
     * @return mixed The fresh value of the item, or $default on miss/expiry.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Fetch both fresh and stale values from the cache.
     *
     * Returns an associative array with two keys:
     * - 'fresh': The value if it is still within its TTL, or null if expired/missing.
     * - 'stale': The value regardless of freshness, or null if missing or past
     *   the hard retention window (fresh TTL + AbstractCache::DEFAULT_STALE_TTL).
     *
     * A single backend read serves both keys; implementations must not decode
     * the payload twice.
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
     *                        Negative values are not supported; callers must
     *                        pass a positive TTL or null.
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
     *
     * May be a full scan (FileCache, ArrayCache) and is therefore intended for
     * a scheduled background job rather than the request path. Backends with
     * native expiration (Memcached) may implement this as a no-op.
     */
    public function prune(): void;

    /**
     * Attempt to acquire an exclusive lock for a key.
     *
     * Used for single-flight regeneration: when a cached feed goes stale,
     * only one process at a time may regenerate it while everyone else keeps
     * serving the stale copy (see CacheMiddleware).
     *
     * Semantics:
     *  - Returns true when the caller may regenerate, false when another
     *    holder owns the lease.
     *  - Implementations that cannot coordinate across processes, or whose
     *    backend is unavailable, return true so regeneration proceeds without
     *    deduplication rather than blocking the feed.
     *  - Locks are NOT reentrant: acquiring the same key twice without an
     *    intervening release is unsupported. Callers acquire once and release
     *    in a finally block.
     *  - The lease is bounded either by $ttlSeconds (token/generation based
     *    backends) or by the lifetime of the holding process (flock based
     *    backends, where $ttlSeconds is advisory/diagnostic only). In both
     *    cases a crashed holder must not block the feed indefinitely.
     *
     * @param string $key        The key to lock.
     * @param int    $ttlSeconds Lock lifetime in seconds (>= 1); must exceed
     *                           the worst-case regeneration time so a crashed
     *                           holder cannot block the feed forever.
     * @return bool True when the lock was acquired (caller may regenerate),
     *              false when another process holds it.
     */
    public function acquireLock(string $key, int $ttlSeconds): bool;

    /**
     * Release a lock previously acquired by this instance via acquireLock().
     *
     * Must be a no-op when this instance does not hold the lock, and must
     * never remove a lease reclaimed by another process after the original
     * lease expired: ownership is verified against the token/generation
     * recorded at acquire time (or the open file handle for flock backends).
     *
     * @param string $key The key to unlock.
     */
    public function releaseLock(string $key): void;
}
