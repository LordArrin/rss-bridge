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

    private const REQUIRED_CONFIG_KEYS = [
        'tgws' => 'socks_url',
        'flaresolverr' => 'url',
        'trawl' => 'url',
    ];

    public static function fromProfile(string $profileName): ProxyInterface
    {
        $profileName = strtolower($profileName);

        if (isset(self::$instances[$profileName]) === true) {
            return self::$instances[$profileName];
        }

        $config = self::loadProfileConfigFromEnv($profileName);
        $requiredKey = self::REQUIRED_CONFIG_KEYS[$profileName] ?? null;
        $isConfigured = $requiredKey === null ? $config !== [] : ($config[$requiredKey] ?? '') !== '';

        if ($profileName !== 'direct' && $isConfigured === false) {
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
        if ($proxy instanceof ProxyInterface === false) {
            throw new \RuntimeException("Proxy class '{$className}' does not implement ProxyInterface.");
        }

        self::$instances[$profileName] = $proxy;

        return $proxy;
    }

    /**
     * Creates a proxy instance directly by type name.
     * Kept for backward compatibility and direct instantiation.
     *
     * @param array<string, mixed> $config
     */
    public static function create(string $type, array $config = []): ProxyInterface
    {
        $type = strtolower($type);
        $className = __NAMESPACE__ . '\\' . (self::CLASS_MAP[$type] ?? ucfirst($type) . 'Proxy');

        if (class_exists($className) === false) {
            throw new \RuntimeException("Proxy class not found: {$className}");
        }

        $proxy = new $className($config);
        if ($proxy instanceof ProxyInterface === false) {
            throw new \RuntimeException("Proxy class '{$className}' does not implement ProxyInterface.");
        }

        return $proxy;
    }

    public static function safeFromProfile(string $profileName): ProxyInterface
    {
        $profileName = strtolower($profileName);

        try {
            return self::fromProfile($profileName);
        } catch (\Exception $e) {
            if ($profileName === 'direct') {
                return self::create('Direct', []);
            }

            global $container;
            if (isset($container['logger']) === true) {
                $container['logger']->error(
                    "Failed to load proxy profile '{$profileName}': {$e->getMessage()}"
                );
            }
            throw $e;
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

        $env = getenv();

        foreach ($env as $key => $value) {
            if (str_starts_with($key, $prefix) === false) {
                continue;
            }

            $configKey = strtolower(substr($key, $prefixLen));

            if ($configKey === 'type') {
                continue;
            }

            if ($value === '') {
                continue;
            }

            $config[$configKey] = $value;
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
/**
 * Discovers all proxy profiles configured via PROXY_PROFILE_<NAME>_* environment
 * variables. Profiles with a known required key are reported only when that key
 * is non-empty; unknown profiles are reported when any non-empty key exists.
 * The always-available 'direct' profile is excluded.
 *
 * @return list<string> Lowercased profile names, sorted alphabetically.
 */
    public static function getConfiguredProfiles(): array
    {
        $env = getenv();

        /** @var array<string, array<string, string>> $candidates */
        $candidates = [];

        foreach ($env as $key => $value) {
            if (str_starts_with($key, 'PROXY_PROFILE_') === false) {
                continue;
            }

            $rest = substr($key, strlen('PROXY_PROFILE_'));
            $separatorPos = strpos($rest, '_');
            if ($separatorPos === false || $separatorPos === 0) {
                continue;
            }

            $profileName = strtolower(substr($rest, 0, $separatorPos));
            $configKey = strtolower(substr($rest, $separatorPos + 1));

            if ($configKey === '' || $configKey === 'type' || $value === '') {
                continue;
            }

            $candidates[$profileName][$configKey] = $value;
        }

        $profiles = [];
        foreach ($candidates as $profileName => $config) {
            if ($profileName === 'direct') {
                continue;
            }

            $requiredKey = self::REQUIRED_CONFIG_KEYS[$profileName] ?? null;
            $isConfigured = $requiredKey === null ? $config !== [] : ($config[$requiredKey] ?? '') !== '';

            if ($isConfigured === true) {
                $profiles[] = $profileName;
            }
        }

        sort($profiles);
        return $profiles;
    }
}
