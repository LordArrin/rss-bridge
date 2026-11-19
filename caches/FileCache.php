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
 *
 * Locking: single-flight uses flock() on a persistent per-key lock file. The
 * lease is bound to the open handle (released by the kernel when the holder
 * dies), so the TTL passed to acquireLock() is diagnostic only. Lock files are
 * intentionally never unlinked, because removing a flock file races with
 * concurrent openers and would break mutual exclusion.
 */
final class FileCache extends AbstractCache
{
    private readonly string $path;
    private readonly bool $enablePurge;
    private readonly Logger $logger;

    /** @var array<string, resource> */
    private array $lockHandles = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(Logger $logger, array $config = [])
    {
        $this->logger = $logger;

        $default = [
            'path'         => null,
            'enable_purge' => true,
        ];

        $config = array_merge($default, $config);

        $pathRaw = $config['path'] ?? null;
        if (is_string($pathRaw) === false || $pathRaw === '') {
            throw new \Exception('The FileCache needs a path value');
        }

        $this->path = rtrim($pathRaw, '/') . '/';
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

        $decoded = $this->decode($data, $key);

        if ($decoded === null) {
            $this->logger->info(sprintf('Discarding non-JSON legacy cache file: %s', $cacheFile));
            $this->delete($key);
            return ['fresh' => null, 'stale' => null];
        }

        if ($decoded['fresh'] !== null) {
            return $decoded;
        }

        $freshUntil = $this->extractFreshUntil($data);
        if ($freshUntil !== null && $freshUntil > 0) {
            $hardExpiration = $freshUntil + self::DEFAULT_STALE_TTL;
        } else {
            $fileMtime = filemtime($cacheFile);
            $hardExpiration = $fileMtime !== false ? $fileMtime + self::DEFAULT_STALE_TTL : null;
        }

        if ($hardExpiration !== null && $hardExpiration <= time()) {
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

        $cacheFile = $this->createCacheFile($key);
        $now = time();
        $freshUntil = $ttl === null ? 0 : $now + $ttl;

        try {
            $data = $this->encode($value, $freshUntil);
        } catch (\JsonException | \InvalidArgumentException $e) {
            // InvalidArgumentException: unsupported objects (see AbstractCache).
            // Fail soft — a cache write must never crash the request.
            $this->logger->error(sprintf('Failed to encode cache key "%s": %s', $key, $e->getMessage()));
            return;
        }

        // Atomic write: temp file in the SAME directory + rename. Lock-free
        // readers (file_get_contents without LOCK_SH) see either the old or the
        // new complete file, never a torn write. LOCK_EX alone is NOT atomic.
        $tmpFile = $cacheFile . '.' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            $written = file_put_contents($tmpFile, $data, \LOCK_EX);
            if ($written === false) {
                $this->logger->warning(sprintf('Failed to write cache file "%s"', $tmpFile));
                @unlink($tmpFile);
                return;
            }
            if (chmod($tmpFile, 0600) === false) {
                $this->logger->warning(sprintf('Failed to chmod cache file "%s"', $tmpFile));
            }
            if (rename($tmpFile, $cacheFile) === false) {
                $this->logger->warning(sprintf('Failed to rename cache file "%s" to "%s"', $tmpFile, $cacheFile));
                @unlink($tmpFile);
                return;
            }
        } catch (\Throwable $e) {
            $this->logger->warning(Url::createSaneExceptionMessage($e));
            @unlink($tmpFile);
        }
    }

    public function delete(string $key): void
    {
        $cacheFile = $this->createCacheFile($key);
        @unlink($cacheFile);
    }

    public function clear(): void
    {
        $files = scandir($this->path);
        if ($files === false) {
            return;
        }

        foreach ($files as $filename) {
            if ($this->isOwnCacheFile($filename) === false && $this->isOwnTempFile($filename) === false) {
                continue;
            }

            $cacheFile = $this->path . $filename;

            if (is_file($cacheFile) === true) {
                @unlink($cacheFile);
            }
        }
    }

    /**
     * Garbage-collects hard-expired entries and orphaned temp files.
     *
     * Fast path reads only the trailing bytes of each file to recover
     * fresh_until (the wrapper always serializes it last), avoiding a full
     * JSON decode of potentially large payloads. Files whose tail cannot be
     * parsed fall back to a full decode so corrupt/legacy entries are still
     * discarded exactly as before. Lock files are never touched.
     */
    public function prune(): void
    {
        if ($this->enablePurge === false) {
            return;
        }

        $now = time();

        $files = scandir($this->path);
        if ($files === false) {
            return;
        }

        foreach ($files as $filename) {
            $cacheFile = $this->path . $filename;

            if ($this->isOwnTempFile($filename) === true) {
                if (is_file($cacheFile) === true) {
                    $tmpMtime = filemtime($cacheFile);
                    if ($tmpMtime !== false && ($now - $tmpMtime) > 3600) {
                        @unlink($cacheFile);
                    }
                }
                continue;
            }

            if ($this->isOwnLockFile($filename) === true) {
                continue;
            }

            if ($this->isOwnCacheFile($filename) === false || is_file($cacheFile) === false) {
                continue;
            }

            $freshUntil = $this->readFreshUntilTail($cacheFile);
            if ($freshUntil === null) {
                $this->pruneViaFullDecode($cacheFile, $now);
                continue;
            }

            if ($freshUntil <= 0) {
                continue;
            }

            if ($freshUntil + self::DEFAULT_STALE_TTL <= $now) {
                @unlink($cacheFile);
            }
        }
    }

    /**
     * Fallback prune for a single file whose tail could not be parsed: decode
     * the whole blob and discard it when corrupt/legacy or hard-expired.
     */
    private function pruneViaFullDecode(string $cacheFile, int $now): void
    {
        $data = file_get_contents($cacheFile);
        if ($data === false) {
            @unlink($cacheFile);
            return;
        }

        try {
            $decoded = json_decode($data, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            @unlink($cacheFile);
            return;
        }

        if (is_array($decoded) === false || array_key_exists('fresh_until', $decoded) === false) {
            @unlink($cacheFile);
            return;
        }

        $freshUntilRaw = $decoded['fresh_until'];
        $freshUntil = 0;
        if (is_int($freshUntilRaw) === true) {
            $freshUntil = $freshUntilRaw;
        } elseif (is_string($freshUntilRaw) === true && is_numeric($freshUntilRaw) === true) {
            $freshUntil = (int) $freshUntilRaw;
        }

        if ($freshUntil <= 0) {
            return;
        }

        if ($freshUntil + self::DEFAULT_STALE_TTL <= $now) {
            @unlink($cacheFile);
        }
    }

    /**
     * Reads fresh_until from the trailing bytes of a cache file without
     * loading the whole payload. Returns null when the field is absent or
     * malformed, signalling the caller to fall back to a full decode.
     */
    private function readFreshUntilTail(string $cacheFile): ?int
    {
        $handle = @fopen($cacheFile, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            $size = filesize($cacheFile);
            $tail = false;
            if ($size !== false && $size > 128 && fseek($handle, -128, SEEK_END) === 0) {
                $tail = fread($handle, 128);
            }
            if ($tail === false) {
                $tail = stream_get_contents($handle);
            }
            if ($tail === false) {
                return null;
            }
            return $this->extractFreshUntil($tail);
        } finally {
            fclose($handle);
        }
    }

    private function isOwnLockFile(string $filename): bool
    {
        return preg_match('/^[0-9a-f]{32}\.lock$/', $filename) === 1;
    }

    private function isOwnCacheFile(string $filename): bool
    {
        return preg_match('/^[0-9a-f]{64}\.cache$/', $filename) === 1;
    }

    private function isOwnTempFile(string $filename): bool
    {
        return preg_match('/^[0-9a-f]{64}\.cache\.[0-9a-f]{16}\.tmp$/', $filename) === 1;
    }

    /**
     * Cross-process single-flight lock built on flock().
     *
     * The kernel releases the lock when the holding process dies, so a
     * crashed worker can never block a feed, and a live holder can never
     * have its lease stolen — the unlink/recreate reclaim race is gone by
     * construction. The expiry written into the file is diagnostic only;
     * ownership is the open handle. Lock files are intentionally never
     * deleted (unlinking flock files races with concurrent openers).
     *
     * Reentrant within this instance: a second acquire of a key already held
     * here returns true without reopening the handle.
     */
    public function acquireLock(string $key, int $ttlSeconds): bool
    {
        if (isset($this->lockHandles[$key]) === true) {
            return true;
        }

        $lockFile = $this->path . md5($key) . '.lock';
        $handle = @fopen($lockFile, 'cb');
        if ($handle === false) {
            $this->logger->warning(sprintf('Cannot open lock file "%s": proceeding without single-flight', $lockFile));
            return true;
        }

        if (flock($handle, \LOCK_EX | \LOCK_NB) === false) {
            fclose($handle);
            return false;
        }

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) (time() + max(1, $ttlSeconds)));
        @chmod($lockFile, 0600);

        $this->lockHandles[$key] = $handle;
        return true;
    }

    public function releaseLock(string $key): void
    {
        $handle = $this->lockHandles[$key] ?? null;
        if (is_resource($handle) === false) {
            return;
        }
        unset($this->lockHandles[$key]);

        flock($handle, \LOCK_UN);
        fclose($handle);
    }

    private function createCacheFile(string $key): string
    {
        // Use AbstractCache::createCacheKey() for consistent SHA-256 hashing
        return $this->path . bin2hex($this->createCacheKey($key)) . '.cache';
    }

    /**
     * Extracts fresh_until from the tail of an encoded cache blob without
     * re-decoding the full payload. The wrapper always serializes fresh_until
     * as the last key, so an anchored match on the final bytes is sufficient.
     * Returns null when the field is absent, null-valued, or malformed.
     */
    private function extractFreshUntil(string $data): ?int
    {
        $pos = strrpos($data, '"fresh_until"');
        if ($pos === false) {
            return null;
        }

        $tail = substr($data, $pos);
        if (preg_match('/^"fresh_until"\s*:\s*(\d+)\s*}\s*$/', $tail, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }
}
