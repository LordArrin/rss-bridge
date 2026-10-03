<?php

declare(strict_types=1);

namespace RSSBridge\Caches;

use RSSBridge\Configuration;

/**
 * Factory for creating cache instances based on configuration.
 */
final class CacheFactory
{
    private \Logger $logger;

    /**
     * Map cache names to PSR-4 classes.
     *
     * @var array<string, class-string<CacheInterface>>
     */
    private const PSR4_CLASSES = [
        'array'     => ArrayCache::class,
        'file'      => FileCache::class,
        'memcached' => MemcachedCache::class,
        'null'      => NullCache::class,
        'sqlite'    => SQLiteCache::class,
    ];

    public function __construct(\Logger $logger)
    {
        $this->logger = $logger;
    }

    public function create(?string $name = null): CacheInterface
    {
        $name = $this->normalizeName($name);

        if (isset(self::PSR4_CLASSES[$name]) === false) {
            throw new \InvalidArgumentException(sprintf('Invalid cache name: "%s"', $name));
        }

        return $this->createPsr4Cache($name);
    }

    private function normalizeName(?string $name): string
    {
        if ($name === null) {
            $name = Configuration::getConfig('cache', 'type') ?? 'file';
        }

        if ((bool) preg_match('/(.+)(?:\.php)$/', $name, $matches) === true) {
            $name = $matches[1];
        }

        if ((bool) preg_match('/(.+)(?:Cache)$/i', $name, $matches) === true) {
            $name = $matches[1];
        }

        return strtolower($name);
    }

    private function createPsr4Cache(string $name): CacheInterface
    {
        switch ($name) {
            case 'array':
                return new ArrayCache();

            case 'null':
                return new NullCache();

            case 'file':
                $configuredPath = Configuration::getConfig('FileCache', 'path');
                $path = Configuration::getPathCache();

                if ((bool) $configuredPath === true) {
                    $path = $configuredPath;
                }

                return new FileCache($this->logger, [
                    'path'         => $path,
                    'enable_purge' => Configuration::getConfig('FileCache', 'enable_purge'),
                ]);

            case 'sqlite':
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

            case 'memcached':
                if (extension_loaded('memcached') === false) {
                    throw new \Exception('"memcached" extension not loaded. Please check "php.ini"');
                }

                $type = Configuration::getConfig('MemcachedCache', 'type') ?? 'internal';

                if ($type === 'external') {
                    return $this->createMemcachedExternal();
                }

                return $this->createMemcachedInternal();

            default:
                throw new \InvalidArgumentException(sprintf('Unknown cache type: %s', $name));
        }
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
                // Never let the client chunk exceed the server's -I limit,
                // otherwise every chunk write would fail with E2BIG and the
                // cache would silently stop storing anything.
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
                $itemSizeLimit = $parsed + 65536; // restore safety margin -> effective per-item cap
            }
        }

        // Legacy/explicit item_size_limit (e.g. "32M") also accepted here;
        // MemcachedCache clamps it to the 1 MB protocol hard cap internally.
        if ($itemSizeLimit === null) {
            $legacyRaw = $this->readMemcachedSetting('item_size_limit');
            $itemSizeLimit = MemcachedCache::parseSize($legacyRaw);
        }

        $maxChunksRaw = $this->readMemcachedSetting('client_max_chunks');
        $maxChunks = ($maxChunksRaw !== null && ctype_digit($maxChunksRaw) === true) ? (int) $maxChunksRaw : null;

        return [$itemSizeLimit, $maxChunks];
    }

    /**
     * Create internal Memcached instance (Unix socket).
     */
    private function createMemcachedInternal(): MemcachedCache
    {
        $socketPath = Configuration::getConfig('MemcachedCache', 'socket_path');

        if (empty($socketPath) === true) {
            throw new \Exception('"socket_path" param is not set for MemcachedCache (internal mode)');
        }

        if (file_exists($socketPath) === false) {
            throw new \Exception(sprintf('Memcached socket does not exist: %s', $socketPath));
        }

        [$itemSizeLimit, $maxChunks] = $this->memcachedClientOptions();

        // Pass empty string as host, socket path as fourth parameter
        return new MemcachedCache($this->logger, '', 0, $socketPath, $itemSizeLimit, $maxChunks);
    }

    /**
     * Create external Memcached instance (TCP).
     */
    private function createMemcachedExternal(): MemcachedCache
    {
        $host = Configuration::getConfig('MemcachedCache', 'host');
        $port = Configuration::getConfig('MemcachedCache', 'port');

        if (empty($host) === true) {
            throw new \Exception('"host" param is not set for MemcachedCache (external mode)');
        }
        if (empty($port) === true) {
            throw new \Exception('"port" param is not set for MemcachedCache (external mode)');
        }

        $port = (string) $port;
        if (ctype_digit($port) === false) {
            throw new \Exception('"port" param is invalid for MemcachedCache');
        }

        $portInt = intval($port);
        if ($portInt < 1 || $portInt > 65535) {
            throw new \Exception('"port" param is invalid for MemcachedCache');
        }

        [$itemSizeLimit, $maxChunks] = $this->memcachedClientOptions();

        return new MemcachedCache($this->logger, (string) $host, $portInt, '', $itemSizeLimit, $maxChunks);
    }
}
