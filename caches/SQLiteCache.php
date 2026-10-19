<?php

declare(strict_types=1);

namespace RSSBridge\Caches;

use RSSBridge\Logger\Logger;
use RSSBridge\Configuration;

/**
 * SQLite-based persistent cache with WAL mode for better concurrency.
 *
 * Implements Stale-while-revalidate: entries are kept beyond their fresh TTL
 * (up to 7 additional days) so that stale data can be served when upstream fails.
 *
 * Storage format in the 'value' BLOB column:
 *   serialized(['value' => $actualData, 'fresh_until' => int])
 *
 * The 'expiration' column stores the hard-expiry timestamp (fresh + stale TTL)
 * and is used exclusively by prune() for garbage collection.
 */
final class SQLiteCache implements CacheInterface
{
    /**
     * How long stale data is retained after fresh TTL expires (7 days).
     */
    private const DEFAULT_STALE_TTL = 604800;

    private readonly Logger $logger;
    private readonly bool $enablePurge;
    /**
     * SQLITE_BUSY result code. The sqlite3 extension does not define the
     * SQLITE_* constants, so we carry the value ourselves (stable since
     * SQLite 2.x).
     */
    private const SQLITE_BUSY = 5;

    private readonly \SQLite3 $db;
    private readonly int $busyTimeoutMs;

    public function __construct(Logger $logger, array $config)
    {
        $this->logger = $logger;

        $default = [
            'file'         => null,
            'timeout'      => 5000,
            'enable_purge' => true,
        ];

        $config = array_merge($default, $config);

        if ((bool) $config['file'] === false) {
            throw new \Exception('SQLiteCache needs a file path');
        }

        $this->enablePurge = (bool) $config['enable_purge'];

        $file = (string) $config['file'];

        $dir = dirname($file);
        if ($dir === '.') {
            $file = Configuration::getPathCache() . $file;
            $dir = Configuration::getPathCache();
        }

        if (is_dir($dir) === false) {
            throw new \Exception(sprintf('Invalid directory for SQLiteCache: %s', $dir));
        }

        if (is_writable($dir) === false) {
            throw new \Exception(sprintf('The directory for SQLiteCache is not writable: %s', $dir));
        }

        if (file_exists($file) === true && is_writable($file) === false) {
            throw new \Exception(sprintf('The SQLiteCache file is not writable: %s', $file));
        }

        $this->db = new \SQLite3($file);
        $this->db->enableExceptions(true);

        if (is_file($file) === false || filesize($file) === 0) {
            $this->db->exec("CREATE TABLE storage (
                'key' BLOB PRIMARY KEY,
                'value' BLOB,
                'expiration' INTEGER
            )");
            $this->db->exec('CREATE INDEX idx_storage_expiration ON storage (expiration)');
        }

        $this->db->busyTimeout((int) $config['timeout']);
        $this->busyTimeoutMs = (int) $config['timeout'];

        // WAL mode for better concurrent access
        $this->db->exec('PRAGMA journal_mode = WAL');

        // NORMAL synchronous for better performance (safe with WAL)
        $this->db->exec('PRAGMA synchronous = NORMAL');

        // Lease table backing the single-flight lock (acquireLock()). Kept
        // separate from "storage" so lock churn never rewrites feed payloads.
        $this->db->exec("CREATE TABLE IF NOT EXISTS locks (
            'key' BLOB PRIMARY KEY,
            'expiration' INTEGER
        )");
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_locks_expiration ON locks (expiration)');
    }

    /**
     * Cross-process single-flight lock via SQLite's write serialization.
     *
     * BEGIN IMMEDIATE acquires the write lock upfront and fails fast when a
     * competing writer already holds it (busy_timeout is deliberately set to
     * 0 for this short transaction so callers are not blocked behind an
     * unrelated cache write). The winner upserts a lease row; expired leases
     * left behind by crashed workers are reclaimed atomically.
     */
    public function acquireLock(string $key, int $ttlSeconds): bool
    {
        $lockKey = $this->createCacheKey('lock_' . $key);
        $now = time();
        $expiry = $now + $ttlSeconds;

        try {
            $this->db->exec('PRAGMA busy_timeout = 0');

            if (@$this->db->exec('BEGIN IMMEDIATE') === false) {
                // Another process is inside its own lock transaction right
                // now. Treat as "busy elsewhere": caller serves stale instead
                // of piling onto the contention.
                return false;
            }

            $stmt = $this->db->prepare('SELECT expiration FROM locks WHERE key = :key');
            $stmt->bindValue(':key', $lockKey, \SQLITE3_BLOB);
            $result = $stmt->execute();
            $row = $result !== false ? $result->fetchArray(\SQLITE3_ASSOC) : false;

            if ($row !== false && (int) $row['expiration'] > $now) {
                // Live lease held by another worker.
                $this->db->exec('ROLLBACK');
                return false;
            }

            $upsert = $this->db->prepare(
                'INSERT OR REPLACE INTO locks (key, expiration) VALUES (:key, :expiration)'
            );
            $upsert->bindValue(':key', $lockKey, \SQLITE3_BLOB);
            $upsert->bindValue(':expiration', $expiry, \SQLITE3_INTEGER);
            $upsert->execute();

            $this->db->exec('COMMIT');
            return true;
        } catch (\Exception $e) {
            try {
                // SQLite3::lastErrorCode() is SQLITE_BUSY (5) when another
                // process still owns the write lock: rolling back would fail
                // anyway, so leave it alone and let the lease expire on its
                // own. Any other error means our (possibly open) transaction
                // is ours to clean up.
                if ($this->db->lastErrorCode() !== self::SQLITE_BUSY) {
                    @$this->db->exec('ROLLBACK');
                }
            } catch (\Exception $inner) {
                // Ignore rollback failures; the lease simply expires on its own.
            }
            // Fail open: cache problems must never stop feed regeneration.
            $this->logger->warning(sprintf(
                'SQLite lock acquire failed (%s): proceeding without single-flight',
                $e->getMessage()
            ));
            return true;
        } finally {
            // Restore the configured busy timeout for normal cache traffic.
            $this->db->exec('PRAGMA busy_timeout = ' . $this->busyTimeoutMs);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $cached = $this->getWithStale($key);

        return $cached['fresh'] ?? $default;
    }

    public function getWithStale(string $key): array
    {
        $cacheKey = $this->createCacheKey($key);

        $stmt = $this->db->prepare('SELECT value, expiration FROM storage WHERE key = :key');
        $stmt->bindValue(':key', $cacheKey, \SQLITE3_BLOB);

        $result = $stmt->execute();
        if ($result === false) {
            return ['fresh' => null, 'stale' => null];
        }

        $row = $result->fetchArray(\SQLITE3_ASSOC);
        if ($row === false) {
            return ['fresh' => null, 'stale' => null];
        }

        $hardExpiration = (int) $row['expiration'];
        $now = time();

        // Hard-expired: data is too old even for stale serving
        if ($hardExpiration !== 0 && $hardExpiration <= $now) {
            return ['fresh' => null, 'stale' => null];
        }

        $blob = $row['value'];
        $unserialized = unserialize((string) $blob, ['allowed_classes' => true]);

        if ($unserialized === false) {
            $this->logger->error(sprintf(
                "Failed to unserialize cache entry: '%s'",
                mb_substr((string) $blob, 0, 100)
            ));
            return ['fresh' => null, 'stale' => null];
        }

        // Handle legacy format (raw value without metadata wrapper)
        if (is_array($unserialized) === false || array_key_exists('value', $unserialized) === false) {
            return ['fresh' => $unserialized, 'stale' => $unserialized];
        }

        $value = $unserialized['value'];
        $freshUntil = (int) ($unserialized['fresh_until'] ?? 0);

        $isFresh = ($freshUntil === 0 || $freshUntil > $now);

        return [
            'fresh' => $isFresh === true ? $value : null,
            'stale' => $value,
        ];
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        if ($ttl === 0) {
            return;
        }

        $cacheKey = $this->createCacheKey($key);
        $now = time();

        $freshUntil = $ttl === null ? 0 : $now + $ttl;
        $hardExpiration = $ttl === null ? 0 : $freshUntil + self::DEFAULT_STALE_TTL;

        $wrapper = [
            'value'       => $value,
            'fresh_until' => $freshUntil,
        ];

        $blob = serialize($wrapper);

        $stmt = $this->db->prepare(
            'INSERT OR REPLACE INTO storage (key, value, expiration) VALUES (:key, :value, :expiration)'
        );
        $stmt->bindValue(':key', $cacheKey, \SQLITE3_BLOB);
        $stmt->bindValue(':value', $blob, \SQLITE3_BLOB);
        $stmt->bindValue(':expiration', $hardExpiration, \SQLITE3_INTEGER);

        try {
            $stmt->execute();
        } catch (\Exception $e) {
            $this->logger->warning(create_sane_exception_message($e));
        }
    }

    public function delete(string $key): void
    {
        $cacheKey = $this->createCacheKey($key);

        $stmt = $this->db->prepare('DELETE FROM storage WHERE key = :key');
        $stmt->bindValue(':key', $cacheKey, \SQLITE3_BLOB);

        try {
            $stmt->execute();
        } catch (\Exception $e) {
            $this->logger->warning(create_sane_exception_message($e));
        }
    }

    public function prune(): void
    {
        if ($this->enablePurge === false) {
            return;
        }

        $now = time();

        $stmt = $this->db->prepare('DELETE FROM storage WHERE expiration > 0 AND expiration <= :now');
        $stmt->bindValue(':now', $now, \SQLITE3_INTEGER);

        try {
            $stmt->execute();
        } catch (\Exception $e) {
            $this->logger->warning(create_sane_exception_message($e));
        }

        // Expired lock leases are pure garbage: no worker will ever reclaim
        // them through the normal path once the entry is regenerated.
        $locks = $this->db->prepare('DELETE FROM locks WHERE expiration <= :now');
        $locks->bindValue(':now', $now, \SQLITE3_INTEGER);

        try {
            $locks->execute();
        } catch (\Exception $e) {
            $this->logger->warning(create_sane_exception_message($e));
        }
    }

    public function clear(): void
    {
        try {
            $this->db->exec('DELETE FROM storage');
            $this->db->exec('DELETE FROM locks');
        } catch (\Exception $e) {
            $this->logger->warning(create_sane_exception_message($e));
        }
    }

    private function createCacheKey(string $key): string
    {
        return hash('sha256', $key, true);
    }
}
