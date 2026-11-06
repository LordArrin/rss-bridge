<?php

declare(strict_types=1);

namespace RSSBridge\Proxies;

final class ProxyFactory
{
    /**
     * @var array<string, ProxyInterface>
     */
    private static array $instances = [];

    /**
     * Map of profile names to their exact class names.
     * Required to handle acronyms like 'TgWS' correctly.
     */
    private const CLASS_MAP = [
        'direct' => 'DirectProxy',
        'tgws' => 'TgWSProxy',
        'flaresolverr' => 'FlareSolverrProxy',
        'trawl' => 'TrawlProxy',
    ];

    public static function fromProfile(string $profileName): ProxyInterface
    {
        $profileName = strtolower($profileName);

        if (isset(self::$instances[$profileName]) === true) {
            return self::$instances[$profileName];
        }

        $config = self::loadProfileConfigFromEnv($profileName);

        // If no configuration is found for this profile, it's not set up
        if ($config === []) {
            global $container;
            if (isset($container['logger']) === true) {
                $container['logger']->error(
                    "Proxy profile '{$profileName}' is not configured in environment variables."
                );
            }
            throw new \RuntimeException(
                "Proxy profile '{$profileName}' is not configured. Please set PROXY_PROFILE_" . strtoupper($profileName) . '_* variables.'
            );
        }

        $className = self::getClassNameFromProfile($profileName);

        global $container;
        if (isset($container['logger']) === true) {
            $container['logger']->info(sprintf(
                "Loading proxy profile '%s' (%s), config_keys=[%s]",
                $profileName,
                $className,
                implode(', ', array_keys($config))
            ));
        }

        $proxy = new $className($config);
        self::$instances[$profileName] = $proxy;

        return $proxy;
    }

    /**
     * Creates a proxy instance directly by type name.
     * Kept for backward compatibility and direct instantiation.
     */
    public static function create(string $type, array $config = []): ProxyInterface
    {
        $type = strtolower($type);
        $className = __NAMESPACE__ . '\\' . (self::CLASS_MAP[$type] ?? ucfirst($type) . 'Proxy');

        if (class_exists($className) === false) {
            throw new \RuntimeException("Proxy class not found: {$className}");
        }

        return new $className($config);
    }

    public static function safeFromProfile(string $profileName): ProxyInterface
    {
        try {
            return self::fromProfile($profileName);
        } catch (\Exception $e) {
            global $container;
            if (isset($container['logger']) === true) {
                $container['logger']->warning(
                    "Failed to load proxy profile '{$profileName}': {$e->getMessage()}. Falling back to DirectProxy."
                );
            }
            return self::create('Direct', []);
        }
    }

    /**
     * Dynamically loads configuration for a profile from environment variables.
     * Scans for variables starting with PROXY_PROFILE_<NAME>_.
     *
     * @return array<string, string>
     */
    private static function loadProfileConfigFromEnv(string $profileName): array
    {
        $config = [];
        $prefix = 'PROXY_PROFILE_' . strtoupper($profileName) . '_';
        $prefixLen = strlen($prefix);

        // Combine $_ENV and $_SERVER to ensure we catch variables regardless of PHP SAPI
        $env = array_merge($_ENV, $_SERVER);

        foreach ($env as $key => $value) {
            if (str_starts_with((string) $key, $prefix) === true) {
                $configKey = strtolower(substr((string) $key, $prefixLen));

                // Ignore legacy 'type' key if it somehow exists in the environment
                if ($configKey === 'type') {
                    continue;
                }

                $config[$configKey] = (string) $value;
            }
        }

        return $config;
    }

    /**
     * Resolves the fully qualified class name from the profile name.
     * E.g., 'tgws' -> 'RSSBridge\Proxies\TgWSProxy'
     */
    private static function getClassNameFromProfile(string $profileName): string
    {
        $profileName = strtolower($profileName);

        // Use explicit map for known proxies, fallback to ucfirst for custom ones
        $className = self::CLASS_MAP[$profileName] ?? (ucfirst($profileName) . 'Proxy');
        $fqcn = __NAMESPACE__ . '\\' . $className;

        if (class_exists($fqcn) === false) {
            throw new \RuntimeException("Proxy class not found for profile '{$profileName}': {$fqcn}");
        }

        return $fqcn;
    }
}
