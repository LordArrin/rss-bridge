#!/usr/bin/env php
<?php

declare(strict_types=1);

$configFile = '/app/config.ini.php';

if (file_exists($configFile) === false) {
    fwrite(STDERR, "ERROR: Config file not found: {$configFile}\n");
    exit(1);
}

$config = parse_ini_file($configFile, true, INI_SCANNER_RAW);

if ($config === false) {
    fwrite(STDERR, "ERROR: Failed to parse config file\n");
    exit(1);
}

// Single source of truth for embedded memcached defaults.
// Values from [MemcachedCache] in config.ini.php override these.
$defaults = [];
$defaultsFile = __DIR__ . '/config/memcached.conf';
if (file_exists($defaultsFile)) {
    // parse_ini_file() treats '#' as a literal character, so strip full-line
    // comments before parsing the consolidated defaults file.
    $rawDefaults = (string)file_get_contents($defaultsFile);
    // Replace '#'-style comment lines with ';'-style ones, which PHP's INI
    // parser understands natively (keeps line structure intact for errors).
    $strippedDefaults = preg_replace('/^(\h*)#.*$/m', '$1;', $rawDefaults);
    $parsedDefaults = parse_ini_string($strippedDefaults, true, INI_SCANNER_RAW);
    if (is_array($parsedDefaults)) {
        foreach ($parsedDefaults as $section => $values) {
            if (is_array($values) && array_key_exists('value', $values)) {
                $defaults[$section] = trim((string)$values['value']);
            }
        }
    }
}

/**
 * Resolve a setting: user config > config/memcached.conf defaults > fallback.
 */
function mcSetting(array $mc, array $defaults, string $key, string $fallback): string
{
    $userValue = $mc[$key] ?? null;
    if ($userValue !== null && trim((string)$userValue) !== '') {
        return trim((string)$userValue);
    }
    return $defaults[$key] ?? $fallback;
}

$mc = $config['MemcachedCache'] ?? [];

$type = mcSetting($mc, $defaults, 'type', 'internal') === 'external' ? 'external' : 'internal';
$socketPath = mcSetting($mc, $defaults, 'socket_path', '/var/run/memcached/memcached.sock');
$host = mcSetting($mc, $defaults, 'host', '127.0.0.1');
$port = (int)mcSetting($mc, $defaults, 'port', '11211');

// Common output
printf("MEMCACHED_TYPE=%s\n", escapeshellarg($type));

if ($type === 'internal') {
    // Internal mode: emit socket path and server tuning parameters
    $memory = mcSetting($mc, $defaults, 'memory', '512m');
    $maxConnections = (int)mcSetting($mc, $defaults, 'connections', '1024');
    $threads = (int)mcSetting($mc, $defaults, 'threads', '4');
    $itemSizeLimit = mcSetting($mc, $defaults, 'item_size_limit', '32M');

    $modern = in_array(mcSetting($mc, $defaults, 'modern', 'true'), ['true', '1'], true);
    $prealloc = in_array(mcSetting($mc, $defaults, 'prealloc', 'true'), ['true', '1'], true);
    $lockMemory = in_array(mcSetting($mc, $defaults, 'lock_memory', 'false'), ['true', '1'], true);

    $hashPower = (int)mcSetting($mc, $defaults, 'hash_power', '16');
    $backlog = (int)mcSetting($mc, $defaults, 'backlog', '1024');
    $idleTimeout = (int)mcSetting($mc, $defaults, 'idle_timeout', '0');
    $verbosity = (int)mcSetting($mc, $defaults, 'verbosity', '0');

    printf("MEMCACHED_SOCKET_PATH=%s\n", escapeshellarg($socketPath));
    printf("MEMCACHED_MEMORY=%s\n", escapeshellarg($memory));
    printf("MEMCACHED_MAX_CONNECTIONS=%d\n", $maxConnections);
    printf("MEMCACHED_THREADS=%d\n", $threads);
    printf("MEMCACHED_ITEM_SIZE_LIMIT=%s\n", escapeshellarg($itemSizeLimit));
    printf("MEMCACHED_MODERN=%s\n", $modern === true ? 'true' : 'false');
    printf("MEMCACHED_PREALLOC=%s\n", $prealloc === true ? 'true' : 'false');
    printf("MEMCACHED_LOCK_MEMORY=%s\n", $lockMemory === true ? 'true' : 'false');
    printf("MEMCACHED_HASH_POWER=%d\n", $hashPower);
    printf("MEMCACHED_BACKLOG=%d\n", $backlog);
    printf("MEMCACHED_IDLE_TIMEOUT=%d\n", $idleTimeout);
    printf("MEMCACHED_VERBOSITY=%d\n", $verbosity);
} else {
    // External mode: only emit host and port
    printf("MEMCACHED_HOST=%s\n", escapeshellarg($host));
    printf("MEMCACHED_PORT=%d\n", $port);
}
