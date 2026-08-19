<?php

declare(strict_types=1);

namespace RSSBridge\Caches;

/**
 * File-based cache storage with security hardening.
 * Each cache entry is stored as a separate file with serialized data.
 */
final class FileCache implements CacheInterface
{
    private const ALLOWED_CLASSES = [
        'stdClass',
        'DateTime',
        'DateTimeImmutable',
    ];

    private readonly string $path;
    private readonly bool $enablePurge;
    private readonly \Logger $logger;

    public function __construct(\Logger $logger, array $config = [])
    {
        $this->logger = $logger;

        $default = [
            'path'         => null,
            'enable_purge' => true,
        ];

        $config = array_merge($default, $config);

        if (!$config['path']) {
            throw new \Exception('The FileCache needs a path value');
        }

        $this->path = rtrim((string) $config['path'], '/') . '/';
        $this->enablePurge = (bool) $config['enable_purge'];

        if (!is_dir($this->path)) {
            throw new \Exception(sprintf('The FileCache path does not exist: %s', $this->path));
        }

        if (!is_writable($this->path)) {
            throw new \Exception(sprintf('The FileCache path is not writable: %s', $this->path));
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $cacheFile = $this->createCacheFile($key);

        if (!file_exists($cacheFile)) {
            return $default;
        }

        $data = file_get_contents($cacheFile);
        if ($data === false) {
            return $default;
        }

        $item = unserialize($data, ['allowed_classes' => self::ALLOWED_CLASSES]);

        if ($item === false) {
            $this->logger->warning(sprintf('Failed to unserialize: %s', $cacheFile));
            $this->delete($key);
            return $default;
        }

        $expiration = $item['expiration'] ?? time();

        if ($expiration === 0 || $expiration > time()) {
            return $item['value'];
        }

        $this->delete($key);
        return $default;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        if ($ttl === 0) {
            return;
        }

        $item = [
            'value'      => $value,
            'expiration' => $ttl === null ? 0 : time() + $ttl,
        ];

        $cacheFile = $this->createCacheFile($key);
        $bytes = file_put_contents($cacheFile, serialize($item), LOCK_EX);

        if ($bytes === false) {
            $this->logger->warning(sprintf('Failed to write to: %s', $cacheFile));
        }
    }

    public function delete(string $key): void
    {
        $cacheFile = $this->createCacheFile($key);
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
    }

    public function clear(): void
    {
        foreach (scandir($this->path) as $filename) {
            if ($this->isExcludedFile($filename)) {
                continue;
            }

            $cacheFile = $this->path . $filename;
            if (is_file($cacheFile)) {
                unlink($cacheFile);
            }
        }
    }

    public function prune(): void
    {
        if (!$this->enablePurge) {
            return;
        }

        $now = time();

        foreach (scandir($this->path) as $filename) {
            if ($this->isExcludedFile($filename)) {
                continue;
            }

            $cacheFile = $this->path . $filename;
            if (!is_file($cacheFile)) {
                continue;
            }

            $data = file_get_contents($cacheFile);
            if ($data === false) {
                unlink($cacheFile);
                continue;
            }

            $item = unserialize($data, ['allowed_classes' => self::ALLOWED_CLASSES]);

            if ($item === false) {
                unlink($cacheFile);
                continue;
            }

            $expiration = $item['expiration'] ?? time();

            if ($expiration !== 0 && $expiration <= $now) {
                unlink($cacheFile);
            }
        }
    }

    private function createCacheFile(string $key): string
    {
        return $this->path . hash('sha256', $key) . '.cache';
    }

    private function isExcludedFile(string $filename): bool
    {
        return in_array($filename, ['.', '..', '.gitkeep'], true);
    }

    public function getConfig(): array
    {
        return [
            'path'         => $this->path,
            'enable_purge' => $this->enablePurge,
        ];
    }
}
