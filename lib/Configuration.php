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
    private static bool $loaded = false;

    private function __construct()
    {
    }

    public static function loadConfiguration(): void
    {
        $defaultConfigPath = __DIR__ . '/../.bridges.default';
        if (file_exists($defaultConfigPath) === false) {
            throwServerException('The default bridges configuration file (.bridges.default) is missing');
        }

        $config = parse_ini_file($defaultConfigPath, true, INI_SCANNER_RAW);
        if ($config === false) {
            throwServerException('Error parsing default bridges config');
        }
        self::importConfig($config);

        $userConfigPath = __DIR__ . '/../.bridges';
        if (file_exists($userConfigPath) === true) {
            $userConfig = parse_ini_file($userConfigPath, true, INI_SCANNER_RAW);
            if ($userConfig === false) {
                error_log('RSS-Bridge: failed to parse user bridges config (.bridges); bridge-specific options may be missing');
            } else {
                self::importConfig($userConfig);
            }
        }

        self::$loaded = true;
    }

    public static function getConfig(string $section, string $key, mixed $default = null): mixed
    {
        if (self::$loaded === false) {
            throwServerException('Bridge config has not been loaded');
        }
        return self::$config[strtolower($section)][strtolower($key)] ?? $default;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function importConfig(array $config): void
    {
        foreach ($config as $header => $section) {
            if (is_array($section) === false) {
                continue;
            }
            foreach ($section as $key => $value) {
                self::setConfig((string) $header, (string) $key, self::coerceValue($value));
            }
        }
    }

    /**
     * Only explicit boolean/null keywords are coerced; everything else stays a
     * string (so bridges cast numeric config values themselves). This preserves
     * secrets like "007" or "123456" that INI_SCANNER_TYPED would turn into int.
     */
    private static function coerceValue(mixed $value): mixed
    {
        if (is_string($value) === false) {
            return $value;
        }

        $len = strlen($value);
        if ($len >= 2 && (($value[0] === '"' && $value[$len - 1] === '"') || ($value[0] === "'" && $value[$len - 1] === "'"))) {
            return substr($value, 1, -1);
        }

        $lower = strtolower(trim($value));
        if ($lower === 'true' || $lower === 'yes' || $lower === 'on') {
            return true;
        }
        if ($lower === 'false' || $lower === 'no' || $lower === 'off') {
            return false;
        }
        if ($lower === 'null') {
            return null;
        }
        return $value;
    }

    /**
     * @internal Used only by loadConfiguration() to populate the config array
     */
    private static function setConfig(string $section, string $key, mixed $value): void
    {
        self::$config[strtolower($section)][strtolower($key)] = $value;
    }
}
