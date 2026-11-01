<?php

declare(strict_types=1);

namespace RSSBridge;

/**
 * Configuration module for RSS-Bridge.
 *
 * This class implements a configuration module for RSS-Bridge.
 *
 * NOTE: Timezone configuration was removed from this class. The TZ is now
 * managed system-wide by /bin/load-env at container startup, which applies
 * the timezone to PHP runtime, libc, and shell environments simultaneously.
 *
 * NOTE: Admin contact information (email, telegram) is managed by /bin/load-env
 * and accessed directly from environment variables in the web interface renderer.
 *
 * NOTE: Environment mode (prod/dev) is managed by APP_ENV environment variable
 * set by /bin/load-env and is no longer handled by this class.
 *
 * NOTE: Bridge filtering (enabled/disabled bridges) is now managed by /bin/load-env
 * via ENABLED_BRIDGES and DISABLED_BRIDGES environment variables.
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

    public static function loadConfiguration(array $customConfig = [], array $env = []): void
    {
        $defaultConfigPath = __DIR__ . '/../config.default.ini.php';
        if (file_exists($defaultConfigPath) === false) {
            throwServerException('The default configuration file is missing');
        }

        $config = parse_ini_file($defaultConfigPath, true, INI_SCANNER_TYPED);
        if ($config === false) {
            throwServerException('Error parsing ini config');
        }

        foreach ($config as $header => $section) {
            if (is_array($section) === false) {
                continue;
            }
            foreach ($section as $key => $value) {
                self::setConfig((string) $header, (string) $key, $value);
            }
        }

        foreach ($customConfig as $header => $section) {
            if (is_array($section) === false) {
                continue;
            }
            foreach ($section as $key => $value) {
                self::setConfig((string) $header, (string) $key, $value);
            }
        }

        foreach ($env as $envName => $envValue) {
            $nameParts = explode('_', (string) $envName);
            if (($nameParts[0] ?? '') === 'RSSBRIDGE') {
                if (count($nameParts) < 3) {
                    // Invalid env name
                    continue;
                }

                // The variable is named $header but it's actually the section in config.ini.php
                $header = $nameParts[1];

                // Recombine the key if it had multiple underscores
                $key = implode('_', array_slice($nameParts, 2));
                $key = strtolower($key);

                if (is_string($envValue) === true && ($envValue === 'true' || $envValue === 'false')) {
                    $envValue = filter_var($envValue, FILTER_VALIDATE_BOOLEAN);
                }

                self::setConfig($header, $key, $envValue);
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

        $errorOutput = self::getConfig('error', 'output');
        if (is_string($errorOutput) === false) {
            self::throwConfigError('error', 'output', 'Is not a valid String');
        }
        if (in_array($errorOutput, ['feed', 'http', 'none'], true) === false) {
            self::throwConfigError('error', 'output', 'Invalid output');
        }

        $reportLimit = self::getConfig('error', 'report_limit');
        if (is_numeric($reportLimit) === false || (int) $reportLimit < 1) {
            self::throwConfigError('error', 'report_limit', 'Value is invalid');
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
