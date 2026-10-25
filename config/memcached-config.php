#!/usr/bin/env php
<?php

declare(strict_types=1);

// Memcached startup configuration resolver.
//
// Architecture:
// - Cache type is determined from config.ini.php (or config.default.ini.php)
// - Internal memcached settings come from environment variables (MEMCACHED_*),
//   already normalized to canonical form by /bin/load-env
// - External memcached settings (host/port) come from config.ini.php [MemcachedCache]
// - The Unix socket path is fixed and intentionally NOT user-configurable:
//   the server and the PHP client must agree on it, changing it breaks the cache
// - item_size_limit is computed dynamically as min(32M, 49% of memory)
//   to satisfy memcached's hard safety rule: "Cannot set item size limit
//   higher than 1/2 of memory max"

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

$mc = $config['MemcachedCache'] ?? [];

// Determine cache type from config.ini.php
$type = (isset($mc['type']) === true && $mc['type'] === 'external') ? 'external' : 'internal';

// Common output
printf("MEMCACHED_TYPE=%s\n", escapeshellarg($type));

if ($type === 'internal') {
    // Internal mode: read tuning settings from environment variables
    $socketPath = '/var/run/memcached/memcached.sock';

    $envMemory = getenv('MEMCACHED_MEMORY');
    $memory = ($envMemory !== false && $envMemory !== '') ? $envMemory : '512m';

    $envConnections = getenv('MEMCACHED_CONNECTIONS');
    $maxConnections = ($envConnections !== false && $envConnections !== '') ? (int)$envConnections : 1024;

    $envThreads = getenv('MEMCACHED_THREADS');
    $threads = ($envThreads !== false && $envThreads !== '') ? (int)$envThreads : 4;

    // Compute item_size_limit dynamically to satisfy memcached's 49% rule:
    // "Cannot set item size limit higher than 1/2 of memory max"
    // We use 45% to leave some headroom (32M is the safe upper cap).
    $itemSizeLimitBytes = 32 * 1048576;

    $matchResult = preg_match('/^(\d+)([mMgG]?)$/', $memory, $memMatch);
    if ($matchResult === 1) {
        $memValue = (int)$memMatch[1];
        $rawSuffix = $memMatch[2] ?? '';
        $memSuffix = $rawSuffix !== '' ? strtolower($rawSuffix) : 'm';

        $memBytes = match ($memSuffix) {
            'm' => $memValue * 1048576,
            'g' => $memValue * 1073741824,
            default => $memValue * 1048576,
        };

        $safeItemBytes = (int)floor($memBytes * 0.45);
        if ($safeItemBytes > 0 && $safeItemBytes < $itemSizeLimitBytes) {
            $itemSizeLimitBytes = $safeItemBytes;
        }
    }
    // Round down to whole megabytes for readability (memcached accepts this)
    $itemSizeLimitMb = max(1, (int)floor($itemSizeLimitBytes / 1048576));
    $itemSizeLimit = $itemSizeLimitMb . 'M';

    // Server tuning flags (fixed defaults, not user-configurable)
    $modern = 'true';
    $prealloc = 'true';
    $lockMemory = 'false';
    $hashPower = 16;
    $backlog = 1024;
    $idleTimeout = 0;
    $verbosity = 0;

    printf("MEMCACHED_SOCKET_PATH=%s\n", escapeshellarg($socketPath));
    printf("MEMCACHED_MEMORY=%s\n", escapeshellarg($memory));
    printf("MEMCACHED_MAX_CONNECTIONS=%d\n", $maxConnections);
    printf("MEMCACHED_THREADS=%d\n", $threads);
    printf("MEMCACHED_ITEM_SIZE_LIMIT=%s\n", escapeshellarg($itemSizeLimit));
    printf("MEMCACHED_MODERN=%s\n", $modern);
    printf("MEMCACHED_PREALLOC=%s\n", $prealloc);
    printf("MEMCACHED_LOCK_MEMORY=%s\n", $lockMemory);
    printf("MEMCACHED_HASH_POWER=%d\n", $hashPower);
    printf("MEMCACHED_BACKLOG=%d\n", $backlog);
    printf("MEMCACHED_IDLE_TIMEOUT=%d\n", $idleTimeout);
    printf("MEMCACHED_VERBOSITY=%d\n", $verbosity);
} else {
    // External mode: read settings from config.ini.php
    $host = (isset($mc['host']) === true) ? trim($mc['host']) : '127.0.0.1';
    $port = (isset($mc['port']) === true) ? (int)$mc['port'] : 11211;

    printf("MEMCACHED_HOST=%s\n", escapeshellarg($host));
    printf("MEMCACHED_PORT=%d\n", $port);
}
