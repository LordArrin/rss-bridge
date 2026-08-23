<?php

declare(strict_types=1);

namespace RSSBridge\Proxies;

use Configuration;

final class ProxyFactory
{
    private static array $instances = [];

    public static function fromProfile(string $profileName): ProxyInterface
    {
        if (isset(self::$instances[$profileName]) === true) {
            return self::$instances[$profileName];
        }

        $section = 'proxy_profile_' . $profileName;
        $type = Configuration::getConfig($section, 'type');

        if ((bool) $type === false) {
            global $container;
            if (isset($container['logger']) === true) {
                $container['logger']->error(
                    "Proxy profile '{$profileName}' not found: section [{$section}] has no 'type' in config.ini.php"
                );
            }
            throw new \RuntimeException(
                "Proxy profile '{$profileName}' not found in config.ini.php. Expected section [{$section}] with 'type' parameter."
            );
        }

        $config = self::loadProfileConfig($section);

        global $container;
        if (isset($container['logger']) === true) {
            $container['logger']->info(sprintf(
                "Loading proxy profile '%s': type=%s, config_keys=[%s]",
                $profileName,
                $type,
                implode(', ', array_keys($config))
            ));
        }

        $proxy = self::create($type, $config);

        self::$instances[$profileName] = $proxy;

        return $proxy;
    }

    public static function create(string $type, array $config = []): ProxyInterface
    {
        // Build fully qualified class name
        $className = __NAMESPACE__ . '\\' . ucfirst($type) . 'Proxy';

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

    private static function loadProfileConfig(string $section): array
    {
        $config = [];

        $keys = [
            'url', 'session_name',
            'socks_url', 'socks_user', 'socks_pass',
            'connect_timeout', 'request_timeout', 'retries'
        ];

        foreach ($keys as $key) {
            $value = Configuration::getConfig($section, $key);
            if ($value !== null) {
                $config[$key] = $value;
            }
        }

        return $config;
    }
}
