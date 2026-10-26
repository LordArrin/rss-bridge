<?php

declare(strict_types=1);

namespace RSSBridge;

/**
 * Configuration module for RSS-Bridge.
 *
 * This class implements a configuration module for RSS-Bridge.
 */
final class Configuration
{
    public const VERSION = '1.0.0'; // fallback

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

        if (file_exists(__DIR__ . '/../DEBUG') === true) {
            $debugContent = file_get_contents(__DIR__ . '/../DEBUG');
            if ($debugContent !== false) {
                $debug = trim($debugContent);
                if ($debug === '') {
                    self::setConfig('system', 'env', 'dev');
                    self::setConfig('cache', 'type', 'array');
                }
            }
        }

        if (file_exists(__DIR__ . '/../whitelist.txt') === true) {
            $whitelistContent = file_get_contents(__DIR__ . '/../whitelist.txt');
            if ($whitelistContent !== false) {
                $enabledBridges = trim($whitelistContent);
                if ($enabledBridges === '*') {
                    self::setConfig('system', 'enabled_bridges', ['*']);
                } else {
                    self::setConfig('system', 'enabled_bridges', array_filter(
                        array_map('trim', explode("\n", $enabledBridges)),
                        fn(string $v): bool => $v !== ''
                    ));
                }
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

                // Handle this specifically because it's an array
                if ($key === 'enabled_bridges' && is_string($envValue) === true) {
                    $envValue = explode(',', $envValue);
                    $envValue = array_map('trim', $envValue);
                }

                if (is_string($envValue) === true && ($envValue === 'true' || $envValue === 'false')) {
                    $envValue = filter_var($envValue, FILTER_VALIDATE_BOOLEAN);
                }

                self::setConfig($header, $key, $envValue);
            }
        }

        if (in_array(self::getConfig('system', 'env'), ['dev', 'prod'], true) === false) {
            self::throwConfigError('system', 'env', 'Must be dev or prod');
        }

        if (is_array(self::getConfig('system', 'enabled_bridges')) === false) {
            self::throwConfigError('system', 'enabled_bridges', 'Is not an array');
        }

        $timezone = self::getConfig('system', 'timezone');
        $timezoneList = timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC);

        if (is_string($timezone) === false || in_array($timezone, $timezoneList, true) === false) {
            self::throwConfigError('system', 'timezone', 'Is not a valid timezone');
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

        $email = self::getConfig('admin', 'email');
        if ($email !== null && $email !== '' && is_string($email) === true && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            self::throwConfigError('admin', 'email', 'Is not a valid email address');
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

    public static function getVersion(): string
    {
        // Single source of truth: ARG IMAGE_VERSION in the Dockerfile,
        // exported as ENV RSSBRIDGE_SYSTEM_VERSION into the image.
        $envVersion = getenv('RSSBRIDGE_SYSTEM_VERSION');
        if ($envVersion !== false && $envVersion !== '') {
            return (string) $envVersion;
        }

        return self::VERSION;
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
