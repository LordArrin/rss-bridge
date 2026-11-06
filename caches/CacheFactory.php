<?php

declare(strict_types=1);

namespace RSSBridge\Caches;

use RSSBridge\Logger\Logger;
use RSSBridge\Paths;

/**
 * Factory for creating cache instances based on environment configuration.
 *
 * This class centralizes the logic for instantiating different cache
 * implementations. All configuration is now read directly from environment
 * variables, which are validated and normalized by /bin/load-env at startup.
 */
final class CacheFactory
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function create(?string $name = null): CacheInterface
    {
        $name = $this->normalizeName($name);

        return match ($name) {
            'array'     => new ArrayCache(),
            'null'      => new NullCache(),
            'file'      => $this->createFileCache(),
            'sqlite'    => $this->createSQLiteCache(),
            'memcached' => $this->createMemcachedCache(),
            default     => throw new \InvalidArgumentException(sprintf('Invalid cache name: "%s"', $name)),
        };
    }

    private function normalizeName(?string $name): string
    {
        if ($name === null) {
            $name = $this->getEnv('CACHE_TYPE', 'file');
        }

        // Strip common suffixes
        if (preg_match('/(.+)(?:\.php|Cache)$/i', $name, $matches) === 1) {
            $name = $matches[1];
        }

        return strtolower($name);
    }

    private function createFileCache(): FileCache
    {
        // Use centralized path resolution
        $defaultPath = Paths::getCache();
        $path = $this->getEnv('CACHE_FILE_PATH', $defaultPath);
        $enablePurge = filter_var($this->getEnv('CACHE_FILE_PURGE', 'true'), FILTER_VALIDATE_BOOLEAN);

        return new FileCache($this->logger, [
            'path'         => $path,
            'enable_purge' => $enablePurge,
        ]);
    }

    private function createSQLiteCache(): SQLiteCache
    {
        if (extension_loaded('sqlite3') === false) {
            throw new \Exception('"sqlite3" extension not loaded. Please check "php.ini"');
        }

        $file = $this->getEnv('CACHE_SQLITE_FILE', Paths::getCache() . 'cache.sqlite');
        $timeout = (int) $this->getEnv('CACHE_SQLITE_TIMEOUT', '5000');
        $enablePurge = filter_var($this->getEnv('CACHE_SQLITE_PURGE', 'true'), FILTER_VALIDATE_BOOLEAN);

        return new SQLiteCache($this->logger, [
            'file'         => $file,
            'timeout'      => $timeout,
            'enable_purge' => $enablePurge,
        ]);
    }

    private function createMemcachedCache(): MemcachedCache
    {
        if (extension_loaded('memcached') === false) {
            throw new \Exception('"memcached" extension not loaded. Please check "php.ini"');
        }

        $type = $this->getEnv('CACHE_MEMCACHED_TYPE', 'internal');

        if ($type === 'external') {
            return $this->createMemcachedExternal();
        }

        return $this->createMemcachedInternal();
    }

    /**
     * Resolve chunking parameters for the Memcached client from environment.
     * The MEMCACHED_ITEM_SIZE_LIMIT is pre-calculated by config/memcached-config.php
     * to safely satisfy memcached's 49% memory rule.
     *
     * @return array{?int, ?int} [itemSizeLimitBytes, maxChunks]
     */
    private function memcachedClientOptions(): array
    {
        $itemSizeLimitRaw = $this->getEnv('MEMCACHED_ITEM_SIZE_LIMIT', '32M');
        $itemSizeLimit = MemcachedCache::parseSize($itemSizeLimitRaw);

        $maxChunksRaw = $this->getEnv('MEMCACHED_MAX_CHUNKS', '');
        $maxChunks = ($maxChunksRaw !== '' && ctype_digit($maxChunksRaw) === true) ? (int) $maxChunksRaw : null;

        return [$itemSizeLimit, $maxChunks];
    }

    private function createMemcachedInternal(): MemcachedCache
    {
        // Socket path is fixed and managed by the container/supervisor
        $socketPath = $this->getEnv('MEMCACHED_SOCKET_PATH', '/var/run/memcached/memcached.sock');

        if (file_exists($socketPath) === false) {
            throw new \Exception(sprintf('Memcached socket does not exist: %s', $socketPath));
        }

        [$itemSizeLimit, $maxChunks] = $this->memcachedClientOptions();

        return new MemcachedCache($this->logger, '', 0, $socketPath, $itemSizeLimit, $maxChunks);
    }

    private function createMemcachedExternal(): MemcachedCache
    {
        $host = $this->getEnv('CACHE_MEMCACHED_HOST', '127.0.0.1');
        $portStr = $this->getEnv('CACHE_MEMCACHED_PORT', '11211');

        if (ctype_digit($portStr) === false) {
            throw new \Exception('"port" param is invalid for MemcachedCache');
        }

        $portInt = (int) $portStr;
        if ($portInt < 1 || $portInt > 65535) {
            throw new \Exception('"port" param is invalid for MemcachedCache');
        }

        [$itemSizeLimit, $maxChunks] = $this->memcachedClientOptions();

        return new MemcachedCache($this->logger, $host, $portInt, '', $itemSizeLimit, $maxChunks);
    }

    /**
     * Safe environment variable reader with fallback.
     *
     * @param string $key The environment variable name.
     * @param string $default The default value if the variable is missing or empty.
     * @return string The validated environment value or the default.
     */
    private function getEnv(string $key, string $default = ''): string
    {
        $value = getenv($key);
        return ($value !== false && $value !== '') ? (string) $value : $default;
    }
}
