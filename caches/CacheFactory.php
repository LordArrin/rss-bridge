<?php

declare(strict_types=1);

namespace RSSBridge\Caches;

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

        if (!isset(self::PSR4_CLASSES[$name])) {
            throw new \InvalidArgumentException(sprintf('Invalid cache name: "%s"', $name));
        }

        return $this->createPsr4Cache($name);
    }

    private function normalizeName(?string $name): string
    {
        if ($name === null) {
            $name = \Configuration::getConfig('cache', 'type') ?? 'file';
        }

        if (preg_match('/(.+)(?:\.php)$/', $name, $matches)) {
            $name = $matches[1];
        }

        if (preg_match('/(.+)(?:Cache)$/i', $name, $matches)) {
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
                $path = \Configuration::getConfig('FileCache', 'path') ?: PATH_CACHE;
                return new FileCache($this->logger, [
                    'path'         => $path,
                    'enable_purge' => \Configuration::getConfig('FileCache', 'enable_purge'),
                ]);

            case 'sqlite':
                if (!extension_loaded('sqlite3')) {
                    throw new \Exception('"sqlite3" extension not loaded. Please check "php.ini"');
                }

                $file = \Configuration::getConfig('SQLiteCache', 'file');
                if (!$file) {
                    throw new \Exception('Configuration for SQLiteCache missing.');
                }

                return new SQLiteCache($this->logger, [
                    'file'         => $file,
                    'timeout'      => \Configuration::getConfig('SQLiteCache', 'timeout'),
                    'enable_purge' => \Configuration::getConfig('SQLiteCache', 'enable_purge'),
                ]);

            case 'memcached':
                if (!extension_loaded('memcached')) {
                    throw new \Exception('"memcached" extension not loaded. Please check "php.ini"');
                }

                $host = \Configuration::getConfig('MemcachedCache', 'host');
                $port = \Configuration::getConfig('MemcachedCache', 'port');

                if (empty($host)) {
                    throw new \Exception('"host" param is not set for MemcachedCache');
                }
                if (empty($port)) {
                    throw new \Exception('"port" param is not set for MemcachedCache');
                }

                $port = (string) $port;
                if (!ctype_digit($port)) {
                    throw new \Exception('"port" param is invalid for MemcachedCache');
                }

                $portInt = intval($port);
                if ($portInt < 1 || $portInt > 65535) {
                    throw new \Exception('"port" param is invalid for MemcachedCache');
                }

                return new MemcachedCache($this->logger, (string) $host, $portInt);

            default:
                throw new \InvalidArgumentException(sprintf('Unknown cache type: %s', $name));
        }
    }
}