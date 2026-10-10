<?php

declare(strict_types=1);

namespace RSSBridge\Caches;

/**
 * File-based cache storage with security hardening.
 * Each cache entry is stored as a separate file with serialized data.
 *
 * Implements Stale-while-revalidate: entries are kept beyond their fresh TTL
 * (up to 7 additional days) so that stale data can be served when upstream fails.
 *
 * Storage format per file:
 *   serialized(['value' => $actualData, 'fresh_until' => int, 'expires_at' => int])
 */
final class FileCache implements CacheInterface
{
    /**
     * How long stale data is retained after fresh TTL expires (7 days).
     */
    private const DEFAULT_STALE_TTL = 604800;

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

        $item = unserialize($data, ['allowed_classes' => true]);
        if ($item === false || is_array($item) === false) {
            $this->logger->warning(sprintf('Failed to unserialize cache file: %s', $cacheFile));
            $this->delete($key);
            return ['fresh' => null, 'stale' => null];
        }

        // Handle legacy format (old structure without fresh_until)
        if (array_key_exists('fresh_until', $item) === false) {
            $value = $item['value'] ?? $item;
            return ['fresh' => $value, 'stale' => $value];
        }

        $now = time();
        $expiresAt = (int) ($item['expires_at'] ?? 0);

        // Hard-expired: data is too old even for stale serving
        if ($expiresAt !== 0 && $expiresAt <= $now) {
            $this->delete($key);
            return ['fresh' => null, 'stale' => null];
        }

        $value = $item['value'] ?? null;
        $freshUntil = (int) ($item['fresh_until'] ?? 0);
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

        $cacheFile = $this->createCacheFile($key);
        $now = time();

        $freshUntil = $ttl === null ? 0 : $now + $ttl;
        $expiresAt = $ttl === null ? 0 : $freshUntil + self::DEFAULT_STALE_TTL;

        $item = [
            'value'       => $value,
            'fresh_until' => $freshUntil,
            'expires_at'  => $expiresAt,
        ];

        $data = serialize($item);

        try {
            file_put_contents($cacheFile, $data, LOCK_EX);
        } catch (\Exception $e) {
            $this->logger->warning(create_sane_exception_message($e));
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

            $item = unserialize($data, ['allowed_classes' => true]);
            if ($item === false || is_array($item) === false) {
                unlink($cacheFile);
                continue;
            }

            // Handle legacy format: use 'expiration' key if present
            $expiresAt = (int) ($item['expires_at'] ?? $item['expiration'] ?? 0);

            if ($expiresAt !== 0 && $expiresAt <= $now) {
                unlink($cacheFile);
            }
        }
    }

    private function createCacheFile(string $key): string
    {
        return $this->path . md5($key) . '.cache';
    }

    private function isExcludedFile(string $filename): bool
    {
        return in_array($filename, ['.', '..', '.gitkeep', '.htaccess'], true);
    }
}
