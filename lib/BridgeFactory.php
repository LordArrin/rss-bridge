<?php

declare(strict_types=1);

use RSSBridge\Caches\CacheInterface;

final class BridgeFactory
{
    private CacheInterface $cache;
    private Logger $logger;

    /**
     * Array of all available bridge class names (FQCN or legacy global name).
     * For legacy bridges: 'TelegramBridge'
     * For PSR-4 bridges: 'RSSBridge\Bridges\TelegramBridge'
     *
     * @var string[]
     */
    private array $bridgeClassNames = [];

    /**
     * Map from lowercase short name to FQCN for fast lookup.
     * Example: ['telegrambridge' => 'RSSBridge\Bridges\TelegramBridge']
     *
     * @var array<string, string>
     */
    private array $shortNameMap = [];

    /** @var string[] */
    private array $enabledBridges = [];

    /** @var string[] */
    private array $missingEnabledBridges = [];

    public function __construct(CacheInterface $cache, Logger $logger)
    {
        $this->cache = $cache;
        $this->logger = $logger;

        $this->scanBridges();
        $this->loadEnabledBridges();
    }

    /**
     * Scan bridge directories and populate bridgeClassNames + shortNameMap.
     */
    private function scanBridges(): void
    {
        $this->bridgeClassNames = [];
        $this->shortNameMap = [];

        // 1. Scan legacy bridges (global namespace, in bridges/ directory)
        $legacyDir = __DIR__ . '/../bridges/';
        if (is_dir($legacyDir)) {
            foreach (scandir($legacyDir) as $file) {
                if (preg_match('/^([^.]+Bridge)\.php$/U', $file, $m)) {
                    $shortName = $m[1];
                    $this->bridgeClassNames[] = $shortName;
                    $this->shortNameMap[strtolower($shortName)] = $shortName;
                }
            }
        }

        // 2. Scan new PSR-4 bridges (in bridges-v2/ directory).
        // PSR-4 bridges take precedence over legacy bridges with the same short name.
        $v2Dir = __DIR__ . '/../bridges-v2/';
        if (is_dir($v2Dir)) {
            foreach (scandir($v2Dir) as $file) {
                if (preg_match('/^([^.]+Bridge)\.php$/U', $file, $m)) {
                    $shortName = $m[1];
                    $fqcn = 'RSSBridge\\Bridges\\' . $shortName;

                    // Remove legacy version if exists (PSR-4 has priority)
                    $lowerShortName = strtolower($shortName);
                    if (isset($this->shortNameMap[$lowerShortName])) {
                        $legacyFqcn = $this->shortNameMap[$lowerShortName];
                        $index = array_search($legacyFqcn, $this->bridgeClassNames, true);
                        if ($index !== false) {
                            unset($this->bridgeClassNames[$index]);
                            $this->bridgeClassNames = array_values($this->bridgeClassNames);
                        }
                        $this->logger->info(sprintf(
                            'PSR-4 bridge "%s" overrides legacy bridge with the same name',
                            $shortName
                        ));
                    }

                    $this->bridgeClassNames[] = $fqcn;
                    $this->shortNameMap[$lowerShortName] = $fqcn;
                }
            }
        }

        sort($this->bridgeClassNames);
    }

    /**
     * Load enabled bridges from configuration.
     */
    private function loadEnabledBridges(): void
    {
        $enabledBridges = Configuration::getConfig('system', 'enabled_bridges');
        if ($enabledBridges === null) {
            throw new \Exception('No bridges are enabled...');
        }

        foreach ($enabledBridges as $enabledBridge) {
            if ($enabledBridge === '*') {
                $this->enabledBridges = $this->bridgeClassNames;
                break;
            }

            $bridgeClassName = $this->createBridgeClassName($enabledBridge);
            if ($bridgeClassName) {
                $this->enabledBridges[] = $bridgeClassName;
            } else {
                $this->missingEnabledBridges[] = $enabledBridge;
                $this->logger->info(sprintf('Bridge not found: %s', $enabledBridge));
            }
        }
    }

    /**
     * Create a bridge instance by class name or short name.
     */
    public function create(string $name): BridgeAbstract
    {
        // Try to use as FQCN directly first
        if (class_exists($name)) {
            return new $name($this->cache, $this->logger);
        }

        // Otherwise resolve through short name
        $resolved = $this->createBridgeClassName($name);
        if ($resolved === null) {
            throw new \Exception(sprintf('Bridge class not found: %s', $name));
        }

        return new $resolved($this->cache, $this->logger);
    }

    public function isEnabled(string $bridgeName): bool
    {
        return in_array($bridgeName, $this->enabledBridges, true);
    }

    /**
     * Resolve a bridge class name from short name, legacy name, or FQCN.
     * Returns the full class name (FQCN or legacy global name), or null if not found.
     */
    public function createBridgeClassName(string $bridgeName): ?string
    {
        $name = self::normalizeBridgeName($bridgeName);
        $nameLower = strtolower($name);

        // Fast path: short name map lookup
        if (isset($this->shortNameMap[$nameLower])) {
            return $this->shortNameMap[$nameLower];
        }

        // Fallback: try as FQCN directly (e.g. 'RSSBridge\Bridges\FooBridge')
        if (class_exists($bridgeName)) {
            return $bridgeName;
        }

        return null;
    }

    /**
     * Normalize a bridge name to its canonical short form (e.g. 'TelegramBridge').
     * Strips namespace and file extension, adds 'Bridge' suffix if missing.
     */
    public static function normalizeBridgeName(string $name): string
    {
        // Strip namespace if present
        if (str_contains($name, '\\')) {
            $parts = explode('\\', $name);
            $name = end($parts);
        }

        // Strip .php extension if present
        if (preg_match('/(.+)(?:\.php)$/i', $name, $matches)) {
            $name = $matches[1];
        }

        // Add 'Bridge' suffix if missing
        if (!preg_match('/Bridge$/i', $name)) {
            $name = sprintf('%sBridge', $name);
        }

        return $name;
    }

    /**
     * Extract short class name from FQCN or return as-is for legacy names.
     * Example: 'RSSBridge\Bridges\TelegramBridge' -> 'TelegramBridge'
     * Example: 'TelegramBridge' -> 'TelegramBridge'
     */
    public function getShortClassName(string $className): string
    {
        if (str_contains($className, '\\')) {
            $parts = explode('\\', $className);
            return end($parts);
        }
        return $className;
    }

    /**
     * @return string[]
     */
    public function getBridgeClassNames(): array
    {
        return $this->bridgeClassNames;
    }

    /**
     * @return string[]
     */
    public function getMissingEnabledBridges(): array
    {
        return $this->missingEnabledBridges;
    }
}
