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
    public const VERSION = '1.2.0';

    /**
     * @var array<string, array<string, mixed>>
     */
    private static array $config = [];

    private function __construct()
    {
    }

    public static function loadConfiguration(array $customConfig = [], array $env = []): void
    {
        if (file_exists(__DIR__ . '/../config.default.ini.php') === false) {
            throw new \Exception('The default configuration file is missing');
        }

        $config = parse_ini_file(__DIR__ . '/../config.default.ini.php', true, INI_SCANNER_TYPED);
        if ($config === false) {
            throw new \Exception('Error parsing ini config');
        }

        foreach ($config as $header => $section) {
            foreach ($section as $key => $value) {
                self::setConfig((string)$header, (string)$key, $value);
            }
        }

        foreach ($customConfig as $header => $section) {
            foreach ($section as $key => $value) {
                self::setConfig((string)$header, (string)$key, $value);
            }
        }

        if (file_exists(__DIR__ . '/../DEBUG') === true) {
            $debug = trim(file_get_contents(__DIR__ . '/../DEBUG'));
            if ($debug === '') {
                self::setConfig('system', 'env', 'dev');
                self::setConfig('cache', 'type', 'array');
            }
        }

        if (file_exists(__DIR__ . '/../whitelist.txt') === true) {
            $enabledBridges = trim(file_get_contents(__DIR__ . '/../whitelist.txt'));
            if ($enabledBridges === '*') {
                self::setConfig('system', 'enabled_bridges', ['*']);
            } else {
                self::setConfig('system', 'enabled_bridges', array_filter(array_map('trim', explode("\n", $enabledBridges))));
            }
        }

        foreach ($env as $envName => $envValue) {
            $nameParts = explode('_', (string)$envName);
            if ($nameParts[0] === 'RSSBRIDGE') {
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

                if ($envValue === 'true' || $envValue === 'false') {
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
        if ($timezoneList === false) {
            $timezoneList = [];
        }
        if (is_string($timezone) === false || in_array($timezone, $timezoneList, true) === false) {
            self::throwConfigError('system', 'timezone', 'Is not a valid timezone');
        }

        if (is_string(self::getConfig('proxy', 'url')) === false) {
            self::throwConfigError('proxy', 'url', 'Is not a valid string');
        }

        if (is_bool(self::getConfig('proxy', 'by_bridge')) === false) {
            self::throwConfigError('proxy', 'by_bridge', 'Is not a valid Boolean');
        }

        if (is_string(self::getConfig('proxy', 'name')) === false) {
            self::throwConfigError('proxy', 'name', 'Is not a valid string');
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

        $email = self::getConfig('admin', 'email');
        if (empty($email) === false && filter_var((string)$email, FILTER_VALIDATE_EMAIL) === false) {
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
        if (is_numeric($reportLimit) === false || (int)$reportLimit < 1) {
            self::throwConfigError('error', 'report_limit', 'Value is invalid');
        }
    }

    public static function getConfig(string $section, string $key, mixed $default = null): mixed
    {
        if (self::$config === []) {
            throw new \Exception('Config has not been loaded');
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
        $envVersion = getenv('RSSBRIDGE_SYSTEM_VERSION');
        $baseVersion = ($envVersion !== false && $envVersion !== '') ? $envVersion : self::VERSION;

        return (string)$baseVersion;
    }

    private static function throwConfigError(string $section, string $key, string $message = ''): never
    {
        http_response_code(500);
        echo "Config [$section] => [$key] is invalid. $message";
        exit(1);
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
