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
    private const BROKEN_RECHECK_TTL = 3600;
    private const FINGERPRINT_TTL = 5;

    private CacheInterface $cache;

    /**
     * @var list<string>
     */
    private array $bridgesDirs;
    private ?string $cachedHash = null;

    /**
     * @param list<string> $bridgesDirs
     */
    public function __construct(CacheInterface $cache, array $bridgesDirs)
    {
        $this->cache = $cache;
        $this->bridgesDirs = $bridgesDirs;
    }

/**
 * Returns metadata for all bridges, using cache when available.
 *
 * Cached entries that contain broken bridges are revalidated after
 * BROKEN_RECHECK_TTL seconds, so a transient load failure cannot hide a
 * bridge for the full DEFAULT_TTL. Entries without broken bridges are
 * served for the full TTL (file changes invalidate the key via hash).
 *
 * @param BridgeFactory $factory Factory for resolving bridge class names
 * @param SafeBridgeLoader $loader Loader for safely instantiating bridges
 * @return array<string, array{
 *     name: string,
 *     uri: string,
 *     description: string,
 *     parameters: array<string, array<string, mixed>>,
 *     domain: string,
 *     short_name: string,
 *     maintainer: string,
 *     cache_timeout: int
 * }> Metadata indexed by bridge class name
 */
    public function getAll(BridgeFactory $factory, SafeBridgeLoader $loader): array
    {
        $cacheKey = $this->buildCacheKey();
        $cached = $this->cache->get($cacheKey);

        if (
            $cached !== null
            && is_array($cached) === true
            && isset($cached['metadata']) === true
            && is_array($cached['metadata']) === true
        ) {
            $brokenBridges = $cached['broken_bridges'] ?? [];
            $hasBroken = is_array($brokenBridges) === true && $brokenBridges !== [];

            $checkedAtRaw = $cached['broken_checked_at'] ?? 0;
            $checkedAt = is_int($checkedAtRaw) === true ? $checkedAtRaw : 0;

            if ($hasBroken === true && (time() - $checkedAt) >= self::BROKEN_RECHECK_TTL) {
                $result = $this->buildMetadata($factory, $loader);
                $this->cache->set($cacheKey, $result, self::DEFAULT_TTL);
                return $result['metadata'];
            }

            if ($hasBroken === true) {
                foreach ($brokenBridges as $bridgeName => $errorInfo) {
                    if (
                        is_string($bridgeName) === true
                        && is_array($errorInfo) === true
                        && isset($errorInfo['message'], $errorInfo['file'], $errorInfo['line'], $errorInfo['class']) === true
                        && is_string($errorInfo['message']) === true
                        && is_string($errorInfo['file']) === true
                        && is_int($errorInfo['line']) === true
                        && is_string($errorInfo['class']) === true
                    ) {
                        $loader->restoreBrokenBridge($bridgeName, $errorInfo);
                    }
                }
            }

            /** @var array<string, array{name: string, uri: string, description: string, parameters: array<string, array<string, mixed>>, domain: string, short_name: string, maintainer: string, cache_timeout: int}> $metadata */
            $metadata = $cached['metadata'];
            return $metadata;
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
     * @return array{
     *     name: string,
     *     uri: string,
     *     description: string,
     *     parameters: array<string, array<string, mixed>>,
     *     domain: string,
     *     short_name: string,
     *     maintainer: string,
     *     cache_timeout: int
     * }|null Metadata array or null if not found
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
     * @return array<string, array{
     *     name: string,
     *     uri: string,
     *     description: string,
     *     parameters: array<string, array<string, mixed>>,
     *     domain: string,
     *     short_name: string,
     *     maintainer: string,
     *     cache_timeout: int
     * }> Fresh metadata indexed by bridge class name
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
 * @return array{
 *     metadata: array<string, array{
 *         name: string,
 *         uri: string,
 *         description: string,
 *         parameters: array<string, array<string, mixed>>,
 *         domain: string,
 *         short_name: string,
 *         maintainer: string,
 *         cache_timeout: int
 *     }>,
 *     broken_bridges: array<string, array{message: string, file: string, line: int, class: string}>,
 *     broken_checked_at: int
 * }
 */
    private function buildMetadata(BridgeFactory $factory, SafeBridgeLoader $loader): array
    {
        $metadata = [];

        foreach ($this->bridgesDirs as $dir) {
            if (is_dir($dir) === false) {
                continue;
            }

            $files = scandir($dir);
            if ($files === false) {
                continue;
            }

            foreach ($files as $file) {
                if (preg_match('/^([^.]+Bridge)\.php$/U', $file, $m) !== 1) {
                    continue;
                }

                $shortName = $m[1];

                if (str_contains($dir, 'bridges-v2') === true) {
                    $className = 'RSSBridge\\Bridges\\' . $shortName;
                } else {
                    $className = $shortName;
                }

                if ($factory->isEnabled($className) === false) {
                    continue;
                }

                $bridge = $loader->createSafely($className);

                if ($loader->isBridgeBroken($bridge) === true) {
                    continue;
                }

                $metadata[$className] = [
                'name' => $bridge->getName(),
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
        'broken_checked_at' => time(),
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
        if ($url === '') {
            return '';
        }

        $domain = parse_url($url, PHP_URL_HOST);
        if (is_string($domain) === true && str_starts_with($domain, 'www.') === true) {
            $domain = substr($domain, 4);
        }

        return is_string($domain) === true && $domain !== '' ? $domain : '';
    }

    /**
     * Builds the cache key from bridge directories, their files, and the
     * env-driven bridge filters.
     *
     * The filters (ENABLED_BRIDGES / DISABLED_BRIDGES) change the visible bridge
     * set without touching any file, so they MUST participate in the key —
     * otherwise the frontpage keeps a stale bridge set for up to DEFAULT_TTL.
     * File name + mtime + size are hashed so renames and same-second edits
     * invalidate the entry too.
     *
     * @return string Unique cache key
     */
    private function buildCacheKey(): string
    {
        if ($this->cachedHash === null) {
            $this->cachedHash = $this->computeDirectoryHash();
        }

        return self::CACHE_PREFIX . '_' . $this->cachedHash;
    }

    private function computeDirectoryHash(): string
    {
        $apcuAvailable = function_exists('apcu_enabled') === true && apcu_enabled() === true;
        $apcuKey = 'rssbridge_bridge_fingerprint_' . md5(implode('|', $this->bridgesDirs));

        if ($apcuAvailable === true) {
            $cached = apcu_fetch($apcuKey, $success);
            if ($success === true && is_string($cached) === true) {
                return $cached;
            }
        }

        $hashParts = [];

        $enabled = getenv('ENABLED_BRIDGES');
        $disabled = getenv('DISABLED_BRIDGES');
        $hashParts[] = 'enabled=' . ($enabled === false ? '' : $enabled);
        $hashParts[] = 'disabled=' . ($disabled === false ? '' : $disabled);

        foreach ($this->bridgesDirs as $dir) {
            if (is_dir($dir) === false) {
                continue;
            }
            $hashParts[] = $dir;
            $files = scandir($dir);
            if ($files === false) {
                continue;
            }
            foreach ($files as $file) {
                if (preg_match('/^[^.]+Bridge\.php$/U', $file) !== 1) {
                    continue;
                }
                $filepath = $dir . '/' . $file;
                $mtime = filemtime($filepath);
                $size = filesize($filepath);
                $hashParts[] = $file
                    . ':' . ($mtime === false ? 0 : $mtime)
                    . ':' . ($size === false ? 0 : $size);
            }
        }

        $hash = md5(implode('|', $hashParts));

        if ($apcuAvailable === true) {
            apcu_store($apcuKey, $hash, self::FINGERPRINT_TTL);
        }

        return $hash;
    }

    /**
     * @return array<string, array{message: string, file: string, line: int, class: string}>|null
     */
    public function getCachedBrokenBridges(): ?array
    {
        $cached = $this->cache->get($this->buildCacheKey());

        if (
            is_array($cached) === false
            || isset($cached['metadata']) === false
            || is_array($cached['metadata']) === false
        ) {
            return null;
        }

        $brokenRaw = $cached['broken_bridges'] ?? [];
        if (is_array($brokenRaw) === false) {
            return null;
        }

        $broken = [];
        foreach ($brokenRaw as $bridgeName => $errorInfo) {
            if (
                is_string($bridgeName) === true
                && is_array($errorInfo) === true
                && isset($errorInfo['message'], $errorInfo['file'], $errorInfo['line'], $errorInfo['class']) === true
                && is_string($errorInfo['message']) === true
                && is_string($errorInfo['file']) === true
                && is_int($errorInfo['line']) === true
                && is_string($errorInfo['class']) === true
            ) {
                $broken[$bridgeName] = [
                    'message' => $errorInfo['message'],
                    'file' => $errorInfo['file'],
                    'line' => $errorInfo['line'],
                    'class' => $errorInfo['class'],
                ];
            }
        }

        return $broken;
    }
}
