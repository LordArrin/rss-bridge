<?php

declare(strict_types=1);

use RSSBridge\Caches\CacheInterface;

final class BridgeMetadataCache
{
    private const CACHE_PREFIX = 'bridge_metadata_v1';
    private const DEFAULT_TTL = 86400;

    private CacheInterface $cache;
    private string $bridgesDir;

    public function __construct(CacheInterface $cache, string $bridgesDir)
    {
        $this->cache = $cache;
        $this->bridgesDir = $bridgesDir;
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

    public function invalidate(): void
    {
        $cacheKey = $this->buildCacheKey();
        if (method_exists($this->cache, 'delete')) {
            $this->cache->delete($cacheKey);
        }
    }

    private function buildCacheKey(): string
    {
        $hash = $this->calculateBridgesHash();
        return self::CACHE_PREFIX . '_' . $hash;
    }

    private function calculateBridgesHash(): string
    {
        $mtimes = [];

        if (!is_dir($this->bridgesDir)) {
            return 'empty';
        }

        $files = glob($this->bridgesDir . '/*Bridge.php');
        if ($files === false) {
            return 'empty';
        }

        foreach ($files as $file) {
            $mtimes[] = basename($file) . ':' . filemtime($file);
        }

        sort($mtimes);
        return md5(implode('|', $mtimes));
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

            // Use FQCN as key for unambiguous identification
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
