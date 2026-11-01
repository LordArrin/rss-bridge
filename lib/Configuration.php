<?php

declare(strict_types=1);

namespace RSSBridge;

/**
 * Configuration module for RSS-Bridge.
 *
 * This class implements a configuration module for RSS-Bridge.
 * It is strictly responsible for loading and validating application-level
 * settings from INI files (config.default.ini.php and config.ini.php).
 *
 * NOTE: System-level configurations (Timezone, APP_ENV, Admin contacts,
 * PHP-FPM, Memcached, HTTP client settings, Bridge filtering, and Error
 * reporting settings) are now managed exclusively by /bin/load-env at
 * container startup.
 *
 * NOTE: Application version is read directly from the /app/VERSION file
 * generated during docker build, without using this class.
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
        // Load default configuration (always present)
        $defaultConfigPath = __DIR__ . '/../config.default.ini.php';
        if (file_exists($defaultConfigPath) === false) {
            throwServerException('The default configuration file is missing');
        }

        $config = parse_ini_file($defaultConfigPath, true, INI_SCANNER_TYPED);
        if ($config === false) {
            throwServerException('Error parsing default config');
        }

        foreach ($config as $header => $section) {
            if (is_array($section) === false) {
                continue;
            }
            foreach ($section as $key => $value) {
                self::setConfig((string) $header, (string) $key, $value);
            }
        }

        // Load user configuration (optional)
        $userConfigPath = __DIR__ . '/../config.ini.php';
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

        if (is_string(self::getConfig('cache', 'type')) === false) {
            self::throwConfigError('cache', 'type', 'Is not a valid string');
        }

        if (is_bool(self::getConfig('cache', 'custom_timeout')) === false) {
            self::throwConfigError('cache', 'custom_timeout', 'Is not a valid Boolean');
        }

        if (is_bool(self::getConfig('authentication', 'enable')) === false) {
            self::throwConfigError('authentication', 'enable', 'Is not a valid Boolean');
        }

        if (is_string(self::getConfig('authentication', 'username')) === false) {
            self::throwConfigError('authentication', 'username', 'Is not a valid string');
        }

        if (is_string(self::getConfig('authentication', 'password')) === false) {
            self::throwConfigError('authentication', 'password', 'Is not a valid string');
        }

        if (is_string(self::getConfig('authentication', 'password_hash')) === false) {
            self::throwConfigError('authentication', 'password_hash', 'Is not a valid string');
        }
    }

    public static function getConfig(string $section, string $key, mixed $default = null): mixed
    {
        if (self::$config === []) {
            throwServerException('Config has not been loaded');
        }
        return self::$config[strtolower($section)][strtolower($key)] ?? $default;
    }

    /**
     * @internal Please avoid usage
     */
    public static function setConfig(string $section, string $key, mixed $value): void
    {
        self::$config[strtolower($section)][strtolower($key)] = $value;
    }

    private static function throwConfigError(string $section, string $key, string $message = ''): never
    {
        throwServerException(sprintf('Config [%s] => [%s] is invalid. %s', $section, $key, $message));
    }

    /**
     * Returns the absolute path to the cache directory.
     *
     * This is the directory where file-based caches store their data.
     */
    public static function getPathCache(): string
    {
        return dirname(__DIR__) . '/cache/';
    }

    /**
     * Returns the absolute path to the caches library directory.
     *
     * This directory contains cache implementation classes.
     */
    public static function getPathLibCaches(): string
    {
        return dirname(__DIR__) . '/caches/';
    }
}
