<?php

declare(strict_types=1);

namespace RSSBridge\Caches;

use RSSBridge\Logger\Logger;
use RSSBridge\Paths;
use RSSBridge\Utils\Url;

/**
 * SQLite-based persistent cache with WAL mode for better concurrency.
 *
 * Implements Stale-while-revalidate: entries are kept beyond their fresh TTL
 * (up to 7 additional days) so that stale data can be served when upstream fails.
 *
 * Storage format in the 'value' BLOB column:
 *   JSON-encoded array: {"__v":2, "value":..., "fresh_until":...}
 *
 * The 'expiration' column stores the hard-expiry timestamp (fresh + stale TTL)
 * and is used exclusively by prune() for garbage collection.
 *
 * Locking: leases are rows in the 'locks' table keyed by the lock key, with
 * 'expiration' as the generation token. Generations strictly increase (a new
 * lease is only written after the previous one expired), so releaseLock()
 * matching on the recorded generation can never delete a lease reclaimed by
 * another process. When the write lock stays busy, acquireLock() degrades to
 * "no deduplication" (returns true) rather than blocking the feed.
 */
final class SQLiteCache extends AbstractCache
{
    private readonly Logger $logger;
    private readonly bool $enablePurge;

    /** @var array<string, int> */
    private array $lockGenerations = [];

    /**
     * SQLITE_BUSY result code. The sqlite3 extension does not define the
     * SQLITE_* constants, so we carry the value ourselves (stable since
     * SQLite 2.x).
     */
    private const SQLITE_BUSY = 5;

    private readonly \SQLite3 $db;
    private readonly int $busyTimeoutMs;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(Logger $logger, array $config)
    {
        $this->logger = $logger;

        $default = [
            'file'         => null,
            'timeout'      => 5000,
            'enable_purge' => true,
        ];

        $config = array_merge($default, $config);

        $fileRaw = $config['file'] ?? null;
        if (is_string($fileRaw) === false || $fileRaw === '') {
            throw new \Exception('SQLiteCache needs a file path');
        }

        $this->enablePurge = (bool) $config['enable_purge'];

        $file = $fileRaw;

        $dir = dirname($file);
        if ($dir === '.') {
            $file = Paths::getCache() . $file;
            $dir = Paths::getCache();
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

        $timeoutRaw = $config['timeout'] ?? 5000;
        $timeoutMs = 5000;
        if (is_int($timeoutRaw) === true) {
            $timeoutMs = $timeoutRaw;
        } elseif (is_string($timeoutRaw) === true && is_numeric($timeoutRaw) === true) {
            $timeoutMs = (int) $timeoutRaw;
        }

        $this->db->busyTimeout($timeoutMs);
        $this->busyTimeoutMs = $timeoutMs;

        $walOk = false;
        for ($attempt = 0; $attempt < 5 && $walOk === false; $attempt++) {
            try {
                $mode = $this->db->querySingle('PRAGMA journal_mode = WAL');
                if (is_string($mode) === true && strtolower($mode) === 'wal') {
                    $walOk = true;
                } else {
                    break;
                }
            } catch (\Exception $e) {
                if ($this->db->lastErrorCode() !== self::SQLITE_BUSY) {
                    throw $e;
                }
                usleep(200000);
            }
        }

        if ($walOk === true) {
            $this->db->exec('PRAGMA synchronous = NORMAL');
        } else {
            $this->db->exec('PRAGMA synchronous = FULL');
        }

        $this->db->exec("CREATE TABLE IF NOT EXISTS locks (
            'key' BLOB PRIMARY KEY,
            'expiration' INTEGER
        )");
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_locks_expiration ON locks (expiration)');

        $this->db->exec("CREATE TABLE IF NOT EXISTS storage (
            'key' BLOB PRIMARY KEY,
            'value' BLOB,
            'expiration' INTEGER
        )");
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_storage_expiration ON storage (expiration)');
    }

    /**
     * Cross-process single-flight lock via SQLite's write serialization.
     *
     * BEGIN IMMEDIATE acquires the write lock upfront. busy_timeout is set to
     * 0 so callers are not blocked behind an unrelated cache write; instead
     * SQLITE_BUSY is retried a few times with a short sleep, so a concurrent
     * set()/prune() does not silently disable single-flight. The winner upserts
     * a lease row; expired leases left by crashed workers are reclaimed
     * atomically. If the write lock stays busy, we degrade to no dedup.
     *
     * Not reentrant: a second acquire of a still-held key returns false.
     */
    public function acquireLock(string $key, int $ttlSeconds): bool
    {
        $lockKey = $this->createCacheKey('lock_' . $key);
        $now = time();
        $expiry = $now + max(1, $ttlSeconds);

        try {
            $this->db->exec('PRAGMA busy_timeout = 0');

            $begun = false;
            for ($attempt = 0; $attempt < 3; $attempt++) {
                try {
                    $this->db->exec('BEGIN IMMEDIATE');
                    $begun = true;
                    break;
                } catch (\Exception $e) {
                    if ($this->db->lastErrorCode() !== self::SQLITE_BUSY) {
                        throw $e;
                    }
                    usleep(30000);
                }
            }

            if ($begun === false) {
                $this->logger->warning('SQLite lock acquire busy after retries: proceeding without single-flight');
                return true;
            }

            $stmt = $this->db->prepare('SELECT expiration FROM locks WHERE key = :key');
            if ($stmt === false) {
                throw new \RuntimeException('Failed to prepare SQLite statement for lock check');
            }
            $stmt->bindValue(':key', $lockKey, \SQLITE3_BLOB);
            $result = $stmt->execute();
            $row = $result !== false ? $result->fetchArray(\SQLITE3_ASSOC) : false;

            if ($row !== false) {
                $expirationRaw = $row['expiration'] ?? 0;
                $expiration = 0;
                if (is_int($expirationRaw) === true) {
                    $expiration = $expirationRaw;
                } elseif (is_string($expirationRaw) === true && is_numeric($expirationRaw) === true) {
                    $expiration = (int) $expirationRaw;
                }

                if ($expiration > $now) {
                    $this->db->exec('ROLLBACK');
                    return false;
                }
            }

            $upsert = $this->db->prepare(
                'INSERT OR REPLACE INTO locks (key, expiration) VALUES (:key, :expiration)'
            );
            if ($upsert === false) {
                throw new \RuntimeException('Failed to prepare SQLite statement for lock upsert');
            }
            $upsert->bindValue(':key', $lockKey, \SQLITE3_BLOB);
            $upsert->bindValue(':expiration', $expiry, \SQLITE3_INTEGER);
            $upsert->execute();

            $this->db->exec('COMMIT');
            $this->lockGenerations[$key] = $expiry;
            return true;
        } catch (\Exception $e) {
            try {
                @$this->db->exec('ROLLBACK');
            } catch (\Exception $inner) {
            }
            $this->logger->warning(sprintf(
                'SQLite lock acquire failed (%s): proceeding without single-flight',
                $e->getMessage()
            ));
            return true;
        } finally {
            try {
                $this->db->exec('PRAGMA busy_timeout = ' . $this->busyTimeoutMs);
            } catch (\Exception $e) {
                $this->logger->warning(Url::createSaneExceptionMessage($e));
            }
        }
    }

    public function releaseLock(string $key): void
    {
        $expiry = $this->lockGenerations[$key] ?? null;
        if (is_int($expiry) === false) {
            return;
        }
        unset($this->lockGenerations[$key]);

        $lockKey = $this->createCacheKey('lock_' . $key);

        try {
            $stmt = $this->db->prepare('DELETE FROM locks WHERE key = :key AND expiration = :expiration');
            if ($stmt === false) {
                return;
            }
            $stmt->bindValue(':key', $lockKey, \SQLITE3_BLOB);
            $stmt->bindValue(':expiration', $expiry, \SQLITE3_INTEGER);
            $stmt->execute();
        } catch (\Exception $e) {
            $this->logger->warning(Url::createSaneExceptionMessage($e));
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

        try {
            $stmt = $this->db->prepare('SELECT value, expiration FROM storage WHERE key = :key');
            if ($stmt === false) {
                return ['fresh' => null, 'stale' => null];
            }
            $stmt->bindValue(':key', $cacheKey, \SQLITE3_BLOB);

            $result = $stmt->execute();
            if ($result === false) {
                return ['fresh' => null, 'stale' => null];
            }

            $row = $result->fetchArray(\SQLITE3_ASSOC);
        } catch (\Exception $e) {
            $this->logger->warning(Url::createSaneExceptionMessage($e));
            return ['fresh' => null, 'stale' => null];
        }

        if ($row === false) {
            return ['fresh' => null, 'stale' => null];
        }

        $expirationRaw = $row['expiration'] ?? 0;
        $hardExpiration = 0;
        if (is_int($expirationRaw) === true) {
            $hardExpiration = $expirationRaw;
        } elseif (is_string($expirationRaw) === true && is_numeric($expirationRaw) === true) {
            $hardExpiration = (int) $expirationRaw;
        }

        $now = time();

        if ($hardExpiration !== 0 && $hardExpiration <= $now) {
            return ['fresh' => null, 'stale' => null];
        }

        $valueRaw = $row['value'] ?? '';
        $blob = is_string($valueRaw) === true ? $valueRaw : '';

        $decoded = $this->decode($blob, $key);

        if ($decoded === null) {
            $this->logger->info(sprintf('Discarding non-JSON legacy cache entry for key "%s"', $key));
            $this->delete($key);
            return ['fresh' => null, 'stale' => null];
        }

        return $decoded;
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

        try {
            $blob = $this->encode($value, $freshUntil);
        } catch (\JsonException | \InvalidArgumentException $e) {
            $this->logger->error(sprintf('Failed to encode cache key "%s": %s', $key, $e->getMessage()));
            return;
        }

        try {
            $stmt = $this->db->prepare(
                'INSERT OR REPLACE INTO storage (key, value, expiration) VALUES (:key, :value, :expiration)'
            );
            if ($stmt === false) {
                $this->logger->warning('Failed to prepare SQLite statement for set()');
                return;
            }
            $stmt->bindValue(':key', $cacheKey, \SQLITE3_BLOB);
            $stmt->bindValue(':value', $blob, \SQLITE3_BLOB);
            $stmt->bindValue(':expiration', $hardExpiration, \SQLITE3_INTEGER);
            $stmt->execute();
        } catch (\Exception $e) {
            $this->logger->warning(Url::createSaneExceptionMessage($e));
        }
    }

    public function delete(string $key): void
    {
        $cacheKey = $this->createCacheKey($key);

        try {
            $stmt = $this->db->prepare('DELETE FROM storage WHERE key = :key');
            if ($stmt === false) {
                return;
            }
            $stmt->bindValue(':key', $cacheKey, \SQLITE3_BLOB);
            $stmt->execute();
        } catch (\Exception $e) {
            $this->logger->warning(Url::createSaneExceptionMessage($e));
        }
    }

    public function prune(): void
    {
        if ($this->enablePurge === false) {
            return;
        }

        $now = time();

        try {
            $stmt = $this->db->prepare('DELETE FROM storage WHERE expiration > 0 AND expiration <= :now');
            if ($stmt !== false) {
                $stmt->bindValue(':now', $now, \SQLITE3_INTEGER);
                $stmt->execute();
            }
        } catch (\Exception $e) {
            $this->logger->warning(Url::createSaneExceptionMessage($e));
        }

        try {
            $locks = $this->db->prepare('DELETE FROM locks WHERE expiration <= :now');
            if ($locks !== false) {
                $locks->bindValue(':now', $now, \SQLITE3_INTEGER);
                $locks->execute();
            }
        } catch (\Exception $e) {
            $this->logger->warning(Url::createSaneExceptionMessage($e));
        }
    }

    public function clear(): void
    {
        try {
            $this->db->exec('DELETE FROM storage');
            $this->db->exec('DELETE FROM locks');
        } catch (\Exception $e) {
            $this->logger->warning(Url::createSaneExceptionMessage($e));
        }
    }
}
