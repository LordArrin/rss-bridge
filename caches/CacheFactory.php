<?php

declare(strict_types=1);

namespace RSSBridge\Caches;

use RSSBridge\Logger\Logger;
use RSSBridge\Configuration;

/**
 * Factory for creating cache instances based on configuration.
 *
 * This class centralizes the logic for instantiating different cache
 * implementations with their specific configuration requirements.
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
            $name = Configuration::getConfig('cache', 'type') ?? 'file';
        }

        // Strip common suffixes
        if ((bool) preg_match('/(.+)(?:\.php|Cache)$/i', $name, $matches) === true) {
            $name = $matches[1];
        }

        return strtolower($name);
    }

    private function createFileCache(): FileCache
    {
        $configuredPath = Configuration::getConfig('FileCache', 'path');
        $path = Configuration::getPathCache();

        if ((bool) $configuredPath === true) {
            $path = $configuredPath;
        }

        return new FileCache($this->logger, [
            'path'         => $path,
            'enable_purge' => Configuration::getConfig('FileCache', 'enable_purge'),
        ]);
    }

    private function createSQLiteCache(): SQLiteCache
    {
        if (extension_loaded('sqlite3') === false) {
            throw new \Exception('"sqlite3" extension not loaded. Please check "php.ini"');
        }

        $file = Configuration::getConfig('SQLiteCache', 'file');
        if ((bool) $file === false) {
            throw new \Exception('Configuration for SQLiteCache missing.');
        }

        return new SQLiteCache($this->logger, [
            'file'         => $file,
            'timeout'      => Configuration::getConfig('SQLiteCache', 'timeout'),
            'enable_purge' => Configuration::getConfig('SQLiteCache', 'enable_purge'),
        ]);
    }

    private function createMemcachedCache(): MemcachedCache
    {
        if (extension_loaded('memcached') === false) {
            throw new \Exception('"memcached" extension not loaded. Please check "php.ini"');
        }

        $type = Configuration::getConfig('MemcachedCache', 'type');
        if (($type ?? '') === '') {
            $type = $this->readMemcachedSetting('type') ?? 'internal';
        }

        if ($type === 'external') {
            return $this->createMemcachedExternal();
        }

        return $this->createMemcachedInternal();
    }

    /**
     * Read a memcached client setting, falling back to the consolidated
     * defaults file /config/memcached.conf when not present in config.ini.php.
     */
    private function readMemcachedSetting(string $key): ?string
    {
        $value = Configuration::getConfig('MemcachedCache', $key);
        if ($value !== null && trim((string) $value) !== '') {
            return trim((string) $value);
        }

        static $defaults = null;
        if ($defaults === null) {
            $defaults = [];
            $file = '/config/memcached.conf';
            if (is_readable($file) === true) {
                // '#' comments are not understood by PHP's INI parser: convert them to ';'
                $raw = (string) file_get_contents($file);
                $parsed = parse_ini_string(preg_replace('/^(\h*)#.*$/m', '$1;', $raw), true, INI_SCANNER_RAW);
                if (is_array($parsed) === true) {
                    foreach ($parsed as $section => $values) {
                        if (is_array($values) === true && array_key_exists('value', $values) === true) {
                            $defaults[$section] = trim((string) $values['value']);
                        }
                    }
                }
            }
        }

        return $defaults[$key] ?? null;
    }

    /**
     * Resolve chunking parameters for the Memcached client from
     * config.ini.php ([MemcachedCache]) or /config/memcached.conf defaults.
     *
     * @return array{?int, ?int} [itemSizeLimitBytes, maxChunks]
     */
    private function memcachedClientOptions(): array
    {
        $itemSizeLimit = null;

        $chunkSizeRaw = $this->readMemcachedSetting('client_chunk_size');
        if ($chunkSizeRaw !== null) {
            $parsed = MemcachedCache::parseSize($chunkSizeRaw);
            if ($parsed !== null) {
                $serverLimitRaw = $this->readMemcachedSetting('item_size_limit');
                $serverLimit = MemcachedCache::parseSize($serverLimitRaw);
                if ($serverLimit !== null && $serverLimit > 0 && $parsed > $serverLimit) {
                    $this->logger->warning(sprintf(
                        'Memcached client_chunk_size (%d) exceeds server item_size_limit (%d); clamping.',
                        $parsed,
                        $serverLimit
                    ));
                    $parsed = $serverLimit;
                }
                $itemSizeLimit = $parsed + 65536;
            }
        }

        if ($itemSizeLimit === null) {
            $legacyRaw = $this->readMemcachedSetting('item_size_limit');
            $itemSizeLimit = MemcachedCache::parseSize($legacyRaw);
        }

        $maxChunksRaw = $this->readMemcachedSetting('client_max_chunks');
        $maxChunks = ($maxChunksRaw !== null && ctype_digit($maxChunksRaw) === true) ? (int) $maxChunksRaw : null;

        return [$itemSizeLimit, $maxChunks];
    }

    private function createMemcachedInternal(): MemcachedCache
    {
        $socketPath = Configuration::getConfig('MemcachedCache', 'socket_path');

        if (($socketPath ?? '') === '') {
            $socketPath = $this->readMemcachedSetting('socket_path');
        }

        if (($socketPath ?? '') === '') {
            throw new \Exception('"socket_path" param is not set for MemcachedCache (internal mode)');
        }

        if (file_exists($socketPath) === false) {
            throw new \Exception(sprintf('Memcached socket does not exist: %s', $socketPath));
        }

        [$itemSizeLimit, $maxChunks] = $this->memcachedClientOptions();

        return new MemcachedCache($this->logger, '', 0, $socketPath, $itemSizeLimit, $maxChunks);
    }

    private function createMemcachedExternal(): MemcachedCache
    {
        $host = Configuration::getConfig('MemcachedCache', 'host');
        $port = Configuration::getConfig('MemcachedCache', 'port');

        if (($host ?? '') === '') {
            throw new \Exception('"host" param is not set for MemcachedCache (external mode)');
        }
        if (($port ?? '') === '') {
            throw new \Exception('"port" param is not set for MemcachedCache (external mode)');
        }

        $port = (string) $port;
        if (ctype_digit($port) === false) {
            throw new \Exception('"port" param is invalid for MemcachedCache');
        }

        $portInt = (int) $port;
        if ($portInt < 1 || $portInt > 65535) {
            throw new \Exception('"port" param is invalid for MemcachedCache');
        }

        [$itemSizeLimit, $maxChunks] = $this->memcachedClientOptions();

        return new MemcachedCache($this->logger, (string) $host, $portInt, '', $itemSizeLimit, $maxChunks);
    }
}
