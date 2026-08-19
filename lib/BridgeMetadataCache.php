<?php

declare(strict_types=1);

use RSSBridge\Caches\CacheInterface;

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

    public function getAll(BridgeFactory $factory, SafeBridgeLoader $loader): array
    {
        $cacheKey = $this->buildCacheKey();
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null && is_array($cached)) {
            return $cached;
        }

        $metadata = $this->buildMetadata($factory, $loader);
        $this->cache->set($cacheKey, $metadata, self::DEFAULT_TTL);

        return $metadata;
    }

    public function get(string $bridgeClassName, BridgeFactory $factory, SafeBridgeLoader $loader): ?array
    {
        $all = $this->getAll($factory, $loader);
        return $all[$bridgeClassName] ?? null;
    }

    public function rebuild(BridgeFactory $factory, SafeBridgeLoader $loader): array
    {
        $cacheKey = $this->buildCacheKey();
        $metadata = $this->buildMetadata($factory, $loader);
        $this->cache->set($cacheKey, $metadata, self::DEFAULT_TTL);
        return $metadata;
    }

    public function invalidate(): void
    {
        $cacheKey = $this->buildCacheKey();
        if (method_exists($this->cache, 'delete')) {
            $this->cache->delete($cacheKey);
        }
    }

    public function isFresh(): bool
    {
        $cacheKey = $this->buildCacheKey();
        $cached = $this->cache->get($cacheKey);
        return $cached !== null && is_array($cached);
    }

    public function getCurrentHash(): string
    {
        return $this->calculateBridgesHash();
    }

    private function buildCacheKey(): string
    {
        return self::CACHE_PREFIX . '_' . $this->calculateBridgesHash();
    }

    private function calculateBridgesHash(): string
    {
        if ($this->cachedHash !== null) {
            return $this->cachedHash;
        }

        $mtimes = [];

        foreach ($this->bridgesDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $files = glob($dir . '/*Bridge.php');
            if ($files === false) {
                continue;
            }

            foreach ($files as $file) {
                $relativeName = basename($dir) . '/' . basename($file);
                $mtimes[] = $relativeName . ':' . filemtime($file);
            }
        }

        if ($mtimes === []) {
            $this->cachedHash = 'empty';
            return $this->cachedHash;
        }

        sort($mtimes);
        $this->cachedHash = md5(implode('|', $mtimes));
        return $this->cachedHash;
    }

    private function buildMetadata(BridgeFactory $factory, SafeBridgeLoader $loader): array
    {
        $metadata = [];
        $classNames = $factory->getBridgeClassNames();

        foreach ($classNames as $className) {
            if (!$factory->isEnabled($className)) {
                continue;
            }

            $bridge = $loader->createSafely($className);

            if ($loader->isBridgeBroken($bridge)) {
                continue;
            }

            $metadata[$className] = $this->extractMetadata($bridge);
        }

        return $metadata;
    }

    private function extractMetadata(BridgeAbstract $bridge): array
    {
        $uri = $bridge->getURI();
        $domain = '';

        if (!empty($uri)) {
            if (!preg_match('#^https?://#', $uri)) {
                $uri = 'https://' . $uri;
            }
            $parsed = parse_url($uri);
            if ($parsed && isset($parsed['host'])) {
                $domain = strtolower($parsed['host']);
                if (strpos($domain, 'www.') === 0) {
                    $domain = substr($domain, 4);
                }
            }
        }

        return [
            'name' => $bridge->getName(),
            'short_name' => $bridge->getShortName(),
            'uri' => $bridge->getURI(),
            'icon' => $bridge->getIcon(),
            'description' => $bridge->getDescription(),
            'maintainer' => $bridge->getMaintainer(),
            'donation_uri' => $bridge->getDonationURI(),
            'cache_timeout' => $bridge->getCacheTimeout(),
            'parameters' => $bridge->getParameters(),
            'domain' => $domain,
        ];
    }
}
