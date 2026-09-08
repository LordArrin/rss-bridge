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

$mc = $config['MemcachedCache'] ?? [];

$type = ($mc['type'] ?? 'internal') === 'external' ? 'external' : 'internal';
$socketPath = $mc['socket_path'] ?? '/var/run/memcached/memcached.sock';
$host = $mc['host'] ?? '127.0.0.1';
$port = (int)($mc['port'] ?? 11211);

// Common output
printf("MEMCACHED_TYPE=%s\n", escapeshellarg($type));

if ($type === 'internal') {
    // Internal mode: emit socket path and server tuning parameters
    $memory = $mc['memory'] ?? '128m';
    $maxConnections = (int)($mc['max_connections'] ?? 1024);
    $threads = (int)($mc['threads'] ?? 4);
    $itemSizeLimit = $mc['item_size_limit'] ?? '1M';
    
    $modernValue = $mc['modern'] ?? '';
    $modern = in_array($modernValue, ['true', '1'], true);
    
    $preallocValue = $mc['prealloc'] ?? '';
    $prealloc = in_array($preallocValue, ['true', '1'], true);
    
    $lockMemoryValue = $mc['lock_memory'] ?? '';
    $lockMemory = in_array($lockMemoryValue, ['true', '1'], true);
    
    $hashPower = (int)($mc['hash_power'] ?? 16);
    $backlog = (int)($mc['backlog'] ?? 1024);
    $idleTimeout = (int)($mc['idle_timeout'] ?? 0);
    $verbosity = (int)($mc['verbosity'] ?? 0);

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
