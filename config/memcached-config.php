#!/usr/bin/env php
<?php

/**
 * Memcached startup configuration resolver.
 *
 * Architecture:
 * - Cache type and connection mode are read directly from environment variables
 *   (CACHE_TYPE, CACHE_MEMCACHED_TYPE), normalized by /bin/load-env.
 * - Internal memcached tuning settings come from MEMCACHED_* environment variables.
 * - External memcached settings come from CACHE_MEMCACHED_HOST and CACHE_MEMCACHED_PORT.
 * - The Unix socket path is fixed and intentionally NOT user-configurable:
 *   the server and the PHP client must agree on it.
 * - item_size_limit is computed dynamically as min(32M, 45% of memory)
 *   to satisfy memcached's hard safety rule: "Cannot set item size limit
 *   higher than 1/2 of memory max".
 *
 * Usage:
 *   eval "$(php /app/config/memcached-config.php)"
 */

declare(strict_types=1);

// Read normalized environment variables (set by /bin/load-env)
$cacheTypeRaw = getenv('CACHE_TYPE');
$cacheType = ($cacheTypeRaw !== false && $cacheTypeRaw !== '') ? $cacheTypeRaw : 'memcached';

$memcachedTypeRaw = getenv('CACHE_MEMCACHED_TYPE');
$memcachedType = ($memcachedTypeRaw !== false && $memcachedTypeRaw !== '') ? $memcachedTypeRaw : 'internal';

// If caching is disabled or uses a different backend, output safe defaults
// and exit cleanly so the calling shell script doesn't fail.
if ($cacheType !== 'memcached') {
    printf("MEMCACHED_TYPE=none\n");
    exit(0);
}

printf("MEMCACHED_TYPE=%s\n", escapeshellarg($memcachedType));

if ($memcachedType === 'internal') {
    // Fixed Unix socket path (not user-configurable)
    $socketPath = '/var/run/memcached/memcached.sock';

    // Tuning settings (already validated and canonicalized by /bin/load-env)
    $memoryRaw = getenv('MEMCACHED_MEMORY');
    $memory = ($memoryRaw !== false && $memoryRaw !== '') ? $memoryRaw : '512m';

    $maxConnectionsRaw = getenv('MEMCACHED_CONNECTIONS');
    $maxConnections = ($maxConnectionsRaw !== false && $maxConnectionsRaw !== '') ? (int) $maxConnectionsRaw : 1024;

    $threadsRaw = getenv('MEMCACHED_THREADS');
    $threads = ($threadsRaw !== false && $threadsRaw !== '') ? (int) $threadsRaw : 4;

    // Compute item_size_limit dynamically to satisfy memcached's safety rule:
    // "Cannot set item size limit higher than 1/2 of memory max"
    // We use 45% to leave some headroom (32M is the safe upper cap).
    $itemSizeLimitBytes = 32 * 1048576;

    if (preg_match('/^(\d+)([mMgG]?)$/', $memory, $memMatch) === 1) {
        $memValue = (int) $memMatch[1];
        $memSuffixRaw = $memMatch[2];
        $memSuffix = strtolower($memSuffixRaw !== '' ? $memSuffixRaw : 'm');

        $memBytes = match ($memSuffix) {
            'm' => $memValue * 1048576,
            'g' => $memValue * 1073741824,
        };

        $safeItemBytes = (int) floor($memBytes * 0.45);
        if ($safeItemBytes > 0 && $safeItemBytes < $itemSizeLimitBytes) {
            $itemSizeLimitBytes = $safeItemBytes;
        }
    }

    // Round down to whole megabytes for readability (memcached accepts this)
    $itemSizeLimitMb = max(1, (int) floor($itemSizeLimitBytes / 1048576));
    $itemSizeLimit = $itemSizeLimitMb . 'M';

    // Server tuning flags (fixed safe defaults)
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
    // External mode: read settings from environment variables
    $hostRaw = getenv('CACHE_MEMCACHED_HOST');
    $host = ($hostRaw !== false && $hostRaw !== '') ? $hostRaw : '127.0.0.1';

    $portRaw = getenv('CACHE_MEMCACHED_PORT');
    $port = ($portRaw !== false && $portRaw !== '') ? (int) $portRaw : 11211;

    printf("MEMCACHED_HOST=%s\n", escapeshellarg($host));
    printf("MEMCACHED_PORT=%d\n", $port);
}
