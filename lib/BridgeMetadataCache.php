<?php

declare(strict_types=1);

namespace RSSBridge;

use RSSBridge\Caches\CacheInterface;

/**
 * Cache for bridge metadata to improve page load performance.
 *
 * This class builds and caches metadata (name, description, parameters, etc.)
 * for all bridges, avoiding the need to instantiate every bridge on each request.
 *
 * It also caches the list of broken bridges that failed to load, so that
 * the frontend can display warnings even when metadata is served from cache.
 */
final class BridgeMetadataCache
{
    private const CACHE_PREFIX = 'bridge_metadata_v2';
    private const DEFAULT_TTL = 2592000;

    private CacheInterface $cache;
    private array $bridgesDirs;
    private ?string $cachedHash = null;

    public function __construct(CacheInterface $cache, array $bridgesDirs)
    {
        $this->cache = $cache;
        $this->bridgesDirs = $bridgesDirs;
    }

    /**
     * Returns metadata for all bridges, using cache when available.
     *
     * @param BridgeFactory $factory Factory for resolving bridge class names
     * @param SafeBridgeLoader $loader Loader for safely instantiating bridges
     * @return array<string, array> Metadata indexed by bridge class name
     */
    public function getAll(BridgeFactory $factory, SafeBridgeLoader $loader): array
    {
        $cacheKey = $this->buildCacheKey();
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null && is_array($cached) === true && isset($cached['metadata']) === true) {
            // Restore broken bridges list to the loader so FrontpageAction can access it
            if (isset($cached['broken_bridges']) === true) {
                foreach ($cached['broken_bridges'] as $bridgeName => $errorInfo) {
                    $loader->restoreBrokenBridge($bridgeName, $errorInfo);
                }
            }
            return $cached['metadata'];
        }

        $result = $this->buildMetadata($factory, $loader);
        $this->cache->set($cacheKey, $result, self::DEFAULT_TTL);

        return $result['metadata'];
    }

    /**
     * Returns metadata for a specific bridge.
     *
     * @param string $bridgeClassName Bridge class name
     * @param BridgeFactory $factory Factory for resolving bridge class names
     * @param SafeBridgeLoader $loader Loader for safely instantiating bridges
     * @return array|null Metadata array or null if not found
     */
    public function get(string $bridgeClassName, BridgeFactory $factory, SafeBridgeLoader $loader): ?array
    {
        $all = $this->getAll($factory, $loader);
        return $all[$bridgeClassName] ?? null;
    }

    /**
     * Rebuilds the cache and returns the fresh metadata.
     *
     * @param BridgeFactory $factory Factory for resolving bridge class names
     * @param SafeBridgeLoader $loader Loader for safely instantiating bridges
     * @return array<string, array> Fresh metadata indexed by bridge class name
     */
    public function rebuild(BridgeFactory $factory, SafeBridgeLoader $loader): array
    {
        $cacheKey = $this->buildCacheKey();
        $result = $this->buildMetadata($factory, $loader);
        $this->cache->set($cacheKey, $result, self::DEFAULT_TTL);
        return $result['metadata'];
    }

    /**
     * Checks whether the current cache entry is fresh (exists and matches current hash).
     *
     * @return bool True if cache is fresh, false otherwise
     */
    public function isFresh(): bool
    {
        $cacheKey = $this->buildCacheKey();
        $cached = $this->cache->get($cacheKey);
        return $cached !== null && is_array($cached) === true && isset($cached['metadata']) === true;
    }

    /**
     * Returns the current hash of bridge directories.
     *
     * This hash changes whenever any bridge file is modified, added, or removed.
     *
     * @return string MD5 hash of bridge directory contents
     */
    public function getCurrentHash(): string
    {
        $this->buildCacheKey();
        return $this->cachedHash ?? '';
    }

    /**
     * Builds metadata for all bridges in the configured directories.
     *
     * @param BridgeFactory $factory Factory for resolving bridge class names
     * @param SafeBridgeLoader $loader Loader for safely instantiating bridges
     * @return array{metadata: array<string, array>, broken_bridges: array<string, array>}
     */
    private function buildMetadata(BridgeFactory $factory, SafeBridgeLoader $loader): array
    {
        $metadata = [];

        foreach ($this->bridgesDirs as $dir) {
            if (is_dir($dir) === false) {
                continue;
            }

            foreach (scandir($dir) as $file) {
                if (preg_match('/^([^.]+Bridge)\.php$/U', $file, $m) !== 1) {
                    continue;
                }

                $shortName = $m[1];

                // Determine full class name based on directory
                if (str_contains($dir, 'bridges-v2') === true) {
                    $className = 'RSSBridge\\Bridges\\' . $shortName;
                } else {
                    $className = $shortName;
                }

                if ($factory->isEnabled($className) === false) {
                    continue;
                }

                $bridge = $loader->createSafely($className);

                // Skip broken bridges - they will be reported separately
                if ($loader->isBridgeBroken($bridge) === true) {
                    continue;
                }

                $metadata[$className] = [
                'name' => $className::NAME,
                'uri' => $bridge->getURI(),
                'description' => $bridge->getDescription(),
                'parameters' => $bridge->getParameters(),
                'domain' => $this->extractDomain($bridge->getURI()),
                'short_name' => $shortName,
                'maintainer' => $bridge->getMaintainer(),
                'cache_timeout' => $bridge->getCacheTimeout(),
                ];
            }
        }

        return [
            'metadata' => $metadata,
            'broken_bridges' => $loader->getBrokenBridges(),
        ];
    }

    /**
     * Extracts the domain from a URL for search functionality.
     *
     * @param string $url Bridge URI
     * @return string Domain name without www. prefix
     */
    private function extractDomain(string $url): string
    {
        if (empty($url) === true) {
            return '';
        }

        $domain = parse_url($url, PHP_URL_HOST);
        if ($domain !== null && $domain !== false && str_starts_with($domain, 'www.') === true) {
            $domain = substr($domain, 4);
        }

        return (empty($domain) === false) ? $domain : '';
    }

    /**
     * Builds the cache key based on bridge directories and their modification times.
     *
     * @return string Unique cache key
     */
    private function buildCacheKey(): string
    {
        if ($this->cachedHash === null) {
            $hashParts = [];
            foreach ($this->bridgesDirs as $dir) {
                if (is_dir($dir) === true) {
                    $hashParts[] = $dir;
                    foreach (scandir($dir) as $file) {
                        if ($file !== '.' && $file !== '..') {
                            $filepath = $dir . '/' . $file;
                            $hashParts[] = filemtime($filepath);
                        }
                    }
                }
            }
            $this->cachedHash = md5(implode('|', $hashParts));
        }

        return self::CACHE_PREFIX . '_' . $this->cachedHash;
    }
}
