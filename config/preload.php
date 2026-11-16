<?php

declare(strict_types=1);

$baseDir = dirname(__DIR__);

$preloadFiles = [
    // Interfaces and Abstracts MUST be preloaded before their implementations
    $baseDir . '/caches/CacheInterface.php',
    $baseDir . '/caches/AbstractCache.php',
    $baseDir . '/formats/FormatInterface.php',
    $baseDir . '/formats/FormatAbstract.php',

    $baseDir . '/lib/exceptions.php',
    $baseDir . '/lib/logger.php',

    $baseDir . '/lib/BridgeAbstract.php',
    $baseDir . '/lib/FeedExpander.php',

    // Concrete Cache implementations (depend on AbstractCache)
    $baseDir . '/caches/FileCache.php',
    $baseDir . '/caches/MemcachedCache.php',
    $baseDir . '/caches/SQLiteCache.php',
    $baseDir . '/caches/NullCache.php',
    $baseDir . '/caches/ArrayCache.php',

    // Concrete Format implementations (depend on FormatAbstract)
    $baseDir . '/formats/AtomFormat.php',
    $baseDir . '/formats/JsonFormat.php',
    $baseDir . '/formats/MrssFormat.php',
    $baseDir . '/formats/HtmlFormat.php',
    $baseDir . '/formats/PlaintextFormat.php',
    $baseDir . '/formats/SfeedFormat.php',
];

foreach ($preloadFiles as $file) {
    if (file_exists($file) === true) {
        opcache_compile_file($file);
    }
}
