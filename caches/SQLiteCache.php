<?php

declare(strict_types=1);

namespace RSSBridge\Caches;

/**
 * SQLite-based persistent cache with WAL mode for better concurrency.
 */
final class SQLiteCache implements CacheInterface
{
    private const ALLOWED_CLASSES = [
        'stdClass',
        'DateTime',
        'DateTimeImmutable',
    ];

    private readonly \Logger $logger;
    private readonly bool $enablePurge;
    private readonly \SQLite3 $db;

    public function __construct(\Logger $logger, array $config)
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

        // WAL mode for better concurrent access
        $this->db->exec('PRAGMA journal_mode = WAL');

        // NORMAL synchronous for better performance (safe with WAL)
        $this->db->exec('PRAGMA synchronous = NORMAL');
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $cacheKey = $this->createCacheKey($key);

        $stmt = $this->db->prepare('SELECT value, expiration FROM storage WHERE key = :key');
        $stmt->bindValue(':key', $cacheKey, \SQLITE3_BLOB);

        $result = $stmt->execute();
        if ($result === false) {
            return $default;
        }

        $row = $result->fetchArray(\SQLITE3_ASSOC);
        if ($row === false) {
            return $default;
        }

        $expiration = (int) $row['expiration'];

        if ($expiration === 0 || $expiration > time()) {
            $blob = $row['value'];
            $value = unserialize((string) $blob, ['allowed_classes' => self::ALLOWED_CLASSES]);

            if ($value === false) {
                $this->logger->error(sprintf(
                    "Failed to unserialize: '%s'",
                    mb_substr((string) $blob, 0, 100)
                ));
                return $default;
            }

            return $value;
        }

        return $default;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        if ($ttl === 0) {
            return;
        }

        $cacheKey = $this->createCacheKey($key);
        $blob = serialize($value);
        $expiration = $ttl === null ? 0 : time() + $ttl;

        $stmt = $this->db->prepare('INSERT OR REPLACE INTO storage (key, value, expiration) VALUES (:key, :value, :expiration)');
        $stmt->bindValue(':key', $cacheKey, \SQLITE3_BLOB);
        $stmt->bindValue(':value', $blob, \SQLITE3_BLOB);
        $stmt->bindValue(':expiration', $expiration, \SQLITE3_INTEGER);

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

        $stmt = $this->db->prepare('DELETE FROM storage WHERE expiration > 0 AND expiration <= :now');
        $stmt->bindValue(':now', time(), \SQLITE3_INTEGER);

        try {
            $stmt->execute();
        } catch (\Exception $e) {
            $this->logger->warning(create_sane_exception_message($e));
        }
    }

    public function clear(): void
    {
        try {
            $this->db->exec('DELETE FROM storage');
        } catch (\Exception $e) {
            $this->logger->warning(create_sane_exception_message($e));
        }
    }

    private function createCacheKey(string $key): string
    {
        return hash('sha256', $key, true);
    }
}
