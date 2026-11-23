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
 *
 * Locking: rebuilds are single-flighted through a shared build lock. Only the
 * caller that actually acquired the lock releases it (in a finally block);
 * buildAndStore() itself never touches the lock, so a degraded lockless rebuild
 * or an explicit CLI rebuild cannot release a lease held by another worker.
 */
final class BridgeMetadataCache
{
    private const CACHE_PREFIX = 'bridge_metadata_v2';
    private const DEFAULT_TTL = 2592000;
    private const BROKEN_RECHECK_TTL = 3600;
    private const FINGERPRINT_TTL = 5;
    private const BUILD_LOCK_TTL = 120;
    private const BUILD_WAIT_MS = 5000;
    private const BUILD_POLL_MS = 100;
    private const APCU_METADATA_TTL = 60;
    private const BUILD_LOCK_SUFFIX = '_build';

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
     * Rebuilds are single-flighted: only one worker rebuilds at a time while
     * the others wait briefly and then serve the shared result (or degrade to
     * a local lockless rebuild). An APCu L1 keeps the frontpage alive during a
     * memcached outage and avoids re-fetching the large payload on every hit;
     * an L1 entry whose broken list is due for revalidation is skipped so the
     * L2 recheck path can run.
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
        $lockKey = $cacheKey . self::BUILD_LOCK_SUFFIX;
        $apcuAvailable = apcu_enabled();
        $apcuKey = 'rssbridge_metadata_' . $cacheKey;

        if ($apcuAvailable === true) {
            $local = apcu_fetch($apcuKey, $success);
            if ($success === true && $this->isValidResult($local) === true) {
                $localBroken = $local['broken_bridges'];
                $localCheckedAt = $local['broken_checked_at'];
                if ($localBroken === [] || (time() - $localCheckedAt) < self::BROKEN_RECHECK_TTL) {
                    $this->restoreBroken($local, $loader);
                    /** @var array<string, array{name: string, uri: string, description: string, parameters: array<string, array<string, mixed>>, domain: string, short_name: string, maintainer: string, cache_timeout: int}> $metadata */
                    $metadata = $local['metadata'];
                    return $metadata;
                }
            }
        }

        $cached = $this->cache->get($cacheKey);
        if ($this->isValidResult($cached) === true) {
            $brokenBridges = $cached['broken_bridges'];
            $hasBroken = $brokenBridges !== [];
            $checkedAt = $cached['broken_checked_at'];

            if ($hasBroken === true && (time() - $checkedAt) >= self::BROKEN_RECHECK_TTL) {
                if ($this->cache->acquireLock($lockKey, self::BUILD_LOCK_TTL) === true) {
                    try {
                        return $this->buildAndStore($cacheKey, $apcuKey, $apcuAvailable, $factory, $loader);
                    } finally {
                        $this->cache->releaseLock($lockKey);
                    }
                }
            }

            $this->restoreBroken($cached, $loader);
            if ($apcuAvailable === true) {
                apcu_store($apcuKey, $cached, self::APCU_METADATA_TTL);
            }
            /** @var array<string, array{name: string, uri: string, description: string, parameters: array<string, array<string, mixed>>, domain: string, short_name: string, maintainer: string, cache_timeout: int}> $metadata */
            $metadata = $cached['metadata'];
            return $metadata;
        }

        if ($this->cache->acquireLock($lockKey, self::BUILD_LOCK_TTL) === true) {
            try {
                return $this->buildAndStore($cacheKey, $apcuKey, $apcuAvailable, $factory, $loader);
            } finally {
                $this->cache->releaseLock($lockKey);
            }
        }

        $waited = 0;
        while ($waited < self::BUILD_WAIT_MS) {
            usleep(self::BUILD_POLL_MS * 1000);
            $waited += self::BUILD_POLL_MS;
            $cached = $this->cache->get($cacheKey);
            if ($this->isValidResult($cached) === true) {
                $this->restoreBroken($cached, $loader);
                if ($apcuAvailable === true) {
                    apcu_store($apcuKey, $cached, self::APCU_METADATA_TTL);
                }
                /** @var array<string, array{name: string, uri: string, description: string, parameters: array<string, array<string, mixed>>, domain: string, short_name: string, maintainer: string, cache_timeout: int}> $metadata */
                $metadata = $cached['metadata'];
                return $metadata;
            }
        }

        return $this->buildAndStore($cacheKey, $apcuKey, $apcuAvailable, $factory, $loader);
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
     * This is an explicit administrative rebuild (CLI warmup / --force) and
     * deliberately does not take the build lock: it must produce a result even
     * when a web worker holds the lease. It never releases a lock it does not
     * own.
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
        $apcuAvailable = apcu_enabled();
        return $this->buildAndStore($cacheKey, 'rssbridge_metadata_' . $cacheKey, $apcuAvailable, $factory, $loader);
    }

    /**
     * Checks whether the current cache entry is fresh: a structurally valid
     * result exists under the key derived from the current directory hash.
     *
     * @return bool True if cache is fresh, false otherwise
     */
    public function isFresh(): bool
    {
        $cached = $this->cache->get($this->buildCacheKey());
        return $this->isValidResult($cached);
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
        return $this->currentHash();
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
                if (preg_match('/^([^.]+Bridge)\.php$/', $file, $m) !== 1) {
                    continue;
                }

                $shortName = $m[1];

                if (str_contains($dir, 'bridges-v2') === true) {
                    $className = 'RSSBridge\Bridges\\' . $shortName;
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
        return self::CACHE_PREFIX . '_' . $this->currentHash();
    }

    /**
     * Memoised directory fingerprint for the lifetime of this instance.
     */
    private function currentHash(): string
    {
        if ($this->cachedHash === null) {
            $this->cachedHash = $this->computeDirectoryHash();
        }
        return $this->cachedHash;
    }

    private function computeDirectoryHash(): string
    {
        $enabled = getenv('ENABLED_BRIDGES');
        $disabled = getenv('DISABLED_BRIDGES');
        $enabled = $enabled === false ? '' : $enabled;
        $disabled = $disabled === false ? '' : $disabled;

        $apcuAvailable = apcu_enabled();
        $apcuKey = 'rssbridge_bridge_fingerprint_' . md5(implode('|', $this->bridgesDirs) . '|' . $enabled . '|' . $disabled);

        if ($apcuAvailable === true) {
            $cached = apcu_fetch($apcuKey, $success);
            if ($success === true && is_string($cached) === true) {
                return $cached;
            }
        }

        $hashParts = [];
        $hashParts[] = 'enabled=' . $enabled;
        $hashParts[] = 'disabled=' . $disabled;

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
                if (preg_match('/^[^.]+Bridge\.php$/', $file) !== 1) {
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
        if ($this->isValidResult($cached) === false) {
            return null;
        }
        return $this->normalizeBrokenBridges($cached['broken_bridges']);
    }

    /**
     * Builds and stores a fresh result. Does NOT acquire or release the build
     * lock; callers that hold the lease are responsible for releasing it.
     *
     * @return array<string, array{name: string, uri: string, description: string, parameters: array<string, array<string, mixed>>, domain: string, short_name: string, maintainer: string, cache_timeout: int}>
     */
    private function buildAndStore(string $cacheKey, string $apcuKey, bool $apcuAvailable, BridgeFactory $factory, SafeBridgeLoader $loader): array
    {
        $result = $this->buildMetadata($factory, $loader);
        $this->cache->set($cacheKey, $result, self::DEFAULT_TTL);
        if ($apcuAvailable === true) {
            apcu_store($apcuKey, $result, self::APCU_METADATA_TTL);
        }
        return $result['metadata'];
    }

    /**
     * @phpstan-assert-if-true array{metadata: array<string, mixed>, broken_bridges: array<string, mixed>, broken_checked_at: int} $cached
     */
    private function isValidResult(mixed $cached): bool
    {
        return is_array($cached) === true
            && isset($cached['metadata']) === true
            && is_array($cached['metadata']) === true
            && isset($cached['broken_bridges']) === true
            && is_array($cached['broken_bridges']) === true
            && isset($cached['broken_checked_at']) === true
            && is_int($cached['broken_checked_at']) === true;
    }

    /**
     * Validates and normalises a raw broken-bridges payload into the typed
     * shape consumed by SafeBridgeLoader and the health endpoint.
     *
     * @return array<string, array{message: string, file: string, line: int, class: string}>
     */
    private function normalizeBrokenBridges(mixed $raw): array
    {
        if (is_array($raw) === false) {
            return [];
        }

        $broken = [];
        foreach ($raw as $bridgeName => $errorInfo) {
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

    /**
     * @param array{metadata: array<string, mixed>, broken_bridges: array<string, mixed>, broken_checked_at: int} $result
     */
    private function restoreBroken(array $result, SafeBridgeLoader $loader): void
    {
        foreach ($this->normalizeBrokenBridges($result['broken_bridges']) as $bridgeName => $errorInfo) {
            $loader->restoreBrokenBridge($bridgeName, $errorInfo);
        }
    }
}
