<?php

declare(strict_types=1);

namespace RSSBridge;

/**
 * Configuration module for RSS-Bridge.
 *
 * This class is strictly responsible for loading bridge-specific settings
 * (e.g., API keys, feature flags) from .bridges.default and .bridges files.
 *
 * NOTE: System-level configurations (Timezone, APP_ENV, Admin contacts,
 * PHP-FPM, Memcached, HTTP client settings, Bridge filtering, Error
 * reporting settings, Cache settings, Authentication, Proxy profiles,
 * and System Messages) are now managed exclusively by /bin/load-env at
 * container startup via environment variables.
 *
 * NOTE: Application version is read directly from the /app/VERSION file
 * generated during docker build, without using this class.
 *
 * NOTE: Directory paths are provided by the Paths class.
 */
final class Configuration
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private static array $config = [];

    private function __construct()
    {
    }

    public static function loadConfiguration(): void
    {
        // 1. Load default bridge configuration (baked into the image)
        $defaultConfigPath = __DIR__ . '/../.bridges.default';
        if (file_exists($defaultConfigPath) === false) {
            throwServerException('The default bridges configuration file (.bridges.default) is missing');
        }

        $config = parse_ini_file($defaultConfigPath, true, INI_SCANNER_TYPED);
        if ($config === false) {
            throwServerException('Error parsing default bridges config');
        }

        foreach ($config as $header => $section) {
            if (is_array($section) === false) {
                continue;
            }
            foreach ($section as $key => $value) {
                self::setConfig((string) $header, (string) $key, $value);
            }
        }

        // 2. Load user bridge configuration (optional, copied to /app/ by docker-entrypoint.sh
        // to ensure correct read permissions for the nginx PHP-FPM user)
        $userConfigPath = __DIR__ . '/../.bridges';
        if (file_exists($userConfigPath) === true) {
            $userConfig = parse_ini_file($userConfigPath, true, INI_SCANNER_TYPED);
            if ($userConfig !== false) {
                foreach ($userConfig as $header => $section) {
                    if (is_array($section) === false) {
                        continue;
                    }
                    foreach ($section as $key => $value) {
                        self::setConfig((string) $header, (string) $key, $value);
                    }
                }
            }
        }
    }

    public static function getConfig(string $section, string $key, mixed $default = null): mixed
    {
        if (self::$config === []) {
            throwServerException('Bridge config has not been loaded');
        }
        return self::$config[strtolower($section)][strtolower($key)] ?? $default;
    }

    /**
     * @internal Used only by loadConfiguration() to populate the config array
     */
    private static function setConfig(string $section, string $key, mixed $value): void
    {
        self::$config[strtolower($section)][strtolower($key)] = $value;
    }
}
