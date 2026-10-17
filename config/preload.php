<?php

declare(strict_types=1);

$baseDir = __DIR__;

$preloadFiles = [
    $baseDir . '/caches/CacheInterface.php',
    $baseDir . '/formats/FormatInterface.php',
    
    $baseDir . '/lib/exceptions.php',
    $baseDir . '/lib/logger.php',
    
    $baseDir . '/lib/BridgeAbstract.php',
    $baseDir . '/lib/FeedExpander.php',
    
    $baseDir . '/formats/FormatAbstract.php',
    
    $baseDir . '/caches/FileCache.php',
    $baseDir . '/caches/MemcachedCache.php',
    $baseDir . '/caches/SQLiteCache.php',
    $baseDir . '/caches/NullCache.php',
    $baseDir . '/caches/ArrayCache.php',
    
    $baseDir . '/formats/AtomFormat.php',
    $baseDir . '/formats/JsonFormat.php',
    $baseDir . '/formats/MrssFormat.php',
    $baseDir . '/formats/HtmlFormat.php',
    $baseDir . '/formats/PlaintextFormat.php',
    $baseDir . '/formats/SfeedFormat.php',
];

foreach ($preloadFiles as $file) {
    if (file_exists($file)) {
        opcache_compile_file($file);
    }
}

$bridgeDir = $baseDir . '/bridges-v2';
if (is_dir($bridgeDir)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($bridgeDir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            opcache_compile_file($file->getPathname());
        }
    }
}
