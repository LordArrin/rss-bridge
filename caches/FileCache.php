<?php

declare(strict_types=1);

namespace RSSBridge\Caches;

use RSSBridge\Logger\Logger;
use RSSBridge\Utils\Url;

/**
 * File-based cache storage with security hardening.
 * Each cache entry is stored as a separate file with JSON-encoded data.
 *
 * Implements Stale-while-revalidate: entries are kept beyond their fresh TTL
 * (up to 7 additional days) so that stale data can be served when upstream fails.
 *
 * Storage format per file:
 *   JSON-encoded: {"__v":2, "value":..., "fresh_until":...}
 */
final class FileCache extends AbstractCache
{
    private readonly string $path;
    private readonly bool $enablePurge;
    private readonly Logger $logger;

    public function __construct(Logger $logger, array $config = [])
    {
        $this->logger = $logger;

        $default = [
            'path'         => null,
            'enable_purge' => true,
        ];

        $config = array_merge($default, $config);

        if ((bool) $config['path'] === false) {
            throw new \Exception('The FileCache needs a path value');
        }

        $this->path = rtrim((string) $config['path'], '/') . '/';
        $this->enablePurge = (bool) $config['enable_purge'];

        if (is_dir($this->path) === false) {
            throw new \Exception(sprintf('The FileCache path does not exist: %s', $this->path));
        }

        if (is_writable($this->path) === false) {
            throw new \Exception(sprintf('The FileCache path is not writable: %s', $this->path));
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $cached = $this->getWithStale($key);

        return $cached['fresh'] ?? $default;
    }

    public function getWithStale(string $key): array
    {
        $cacheFile = $this->createCacheFile($key);

        if (file_exists($cacheFile) === false) {
            return ['fresh' => null, 'stale' => null];
        }

        $data = file_get_contents($cacheFile);
        if ($data === false) {
            return ['fresh' => null, 'stale' => null];
        }

        // Use AbstractCache::decode() for JSON deserialization
        $decoded = $this->decode($data, $key);

        // If decode() returned nulls due to JsonException (legacy/corrupt data),
        // invalidate the entry to prevent repeated failures.
        if ($decoded['fresh'] === null && $decoded['stale'] === null) {
            $this->logger->info(sprintf('Discarding non-JSON legacy cache file: %s', $cacheFile));
            $this->delete($key);
            return ['fresh' => null, 'stale' => null];
        }

        // Check hard expiration (stale TTL)
        // Note: decode() already checks fresh_until, but we need to check
        // if the file itself should be deleted (hard expiration)
        $fileMtime = filemtime($cacheFile);
        if ($fileMtime !== false) {
            $now = time();
            $hardExpiration = $fileMtime + self::DEFAULT_STALE_TTL;
            if ($hardExpiration <= $now) {
                $this->delete($key);
                return ['fresh' => null, 'stale' => null];
            }
        }

        return $decoded;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        if ($ttl === 0) {
            return;
        }

        $cacheFile = $this->createCacheFile($key);
        $now = time();

        $freshUntil = $ttl === null ? 0 : $now + $ttl;

        try {
            // Use AbstractCache::encode() for JSON serialization
            $data = $this->encode($value, $freshUntil);
        } catch (\JsonException $e) {
            $this->logger->error(sprintf('Failed to JSON encode cache key "%s": %s', $key, $e->getMessage()));
            return;
        }

        try {
            // LOCK_EX for atomic writes, 0600 for security (owner-only access)
            file_put_contents($cacheFile, $data, \LOCK_EX);
            chmod($cacheFile, 0600);
        } catch (\Exception $e) {
            $this->logger->warning(Url::createSaneExceptionMessage($e));
        }
    }

    public function delete(string $key): void
    {
        $cacheFile = $this->createCacheFile($key);

        if (file_exists($cacheFile) === true) {
            unlink($cacheFile);
        }
    }

    public function clear(): void
    {
        foreach (scandir($this->path) as $filename) {
            if ($this->isExcludedFile($filename) === true) {
                continue;
            }

            $cacheFile = $this->path . $filename;

            if (is_file($cacheFile) === true) {
                unlink($cacheFile);
            }
        }
    }

    public function prune(): void
    {
        if ($this->enablePurge === false) {
            return;
        }

        $now = time();

        foreach (scandir($this->path) as $filename) {
            if ($this->isExcludedFile($filename) === true) {
                continue;
            }

            $cacheFile = $this->path . $filename;

            if (is_file($cacheFile) === false) {
                continue;
            }

            $data = file_get_contents($cacheFile);
            if ($data === false) {
                unlink($cacheFile);
                continue;
            }

            // Try to decode as JSON; if it fails, it's legacy/corrupt - delete it
            try {
                $decoded = json_decode($data, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                unlink($cacheFile);
                continue;
            }

            if (is_array($decoded) === false || array_key_exists('fresh_until', $decoded) === false) {
                unlink($cacheFile);
                continue;
            }

            // Check hard expiration based on file mtime + stale TTL
            $fileMtime = filemtime($cacheFile);
            if ($fileMtime === false) {
                unlink($cacheFile);
                continue;
            }

            $hardExpiration = $fileMtime + self::DEFAULT_STALE_TTL;
            if ($hardExpiration !== 0 && $hardExpiration <= $now) {
                unlink($cacheFile);
            }
        }
    }

    /**
     * Cross-process single-flight lock built on O_EXCL file creation.
     *
     * The lock file is created atomically; its mtime doubles as the lease
     * start. An expired lease (holder crashed or regeneration hung past the
     * terminate timeout) is reclaimed by unlinking and retrying once, so a
     * dead worker can never block a feed permanently.
     */
    public function acquireLock(string $key, int $ttlSeconds): bool
    {
        $lockFile = $this->path . md5($key) . '.lock';

        // The loop always returns on its first pass: fopen('xb') either wins,
        // finds a live lease (false), or reclaims an expired one and retries
        // the atomic create exactly once.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $handle = @fopen($lockFile, 'xb');
            if ($handle !== false) {
                fclose($handle);
                return true;
            }

            clearstatcache(true, $lockFile);
            $mtime = @filemtime($lockFile);
            if ($mtime === false) {
                // Vanished between fopen and stat: another process released
                // the lock. Retry the atomic create once.
                continue;
            }

            if ((time() - $mtime) >= $ttlSeconds) {
                // Stale lease from a crashed/timed-out holder: reclaim it.
                // Race-safe: two reclaimers both unlink, but only one fopen
                // with 'xb' can win; the loser falls through to "false".
                @unlink($lockFile);
                continue;
            }

            return false;
        }

        return false;
    }

    private function createCacheFile(string $key): string
    {
        // Use AbstractCache::createCacheKey() for consistent SHA-256 hashing
        return $this->path . bin2hex($this->createCacheKey($key)) . '.cache';
    }

    private function isExcludedFile(string $filename): bool
    {
        return in_array($filename, ['.', '..', '.gitkeep', '.htaccess'], true) === true;
    }
}
