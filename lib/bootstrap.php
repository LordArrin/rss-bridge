<?php

if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

const PATH_LIB_CACHES = __DIR__ . '/../caches/';
const PATH_CACHE = __DIR__ . '/../cache/';

// Core library files (still loaded manually)
$files = [
    __DIR__ . '/../lib/html.php',
    __DIR__ . '/../lib/contents.php',
    __DIR__ . '/../lib/utils.php',
    __DIR__ . '/../lib/http.php',
    __DIR__ . '/../lib/logger.php',
    __DIR__ . '/../lib/url.php',
    __DIR__ . '/../lib/seotags.php',
];

foreach ($files as $file) {
    require_once $file;
}

// Legacy autoloader for old bridges in global namespace.
// Note: caches, parsedown, urljoin, and middlewares are now handled by Composer PSR-4 autoloader.
spl_autoload_register(function ($className) {
    // Skip namespaced classes - Composer handles those
    if (str_contains($className, '\\')) {
        return;
    }

    $folders = [
        __DIR__ . '/../actions/',
        __DIR__ . '/../bridges/',
        __DIR__ . '/../formats/',
        __DIR__ . '/../lib/',
        __DIR__ . '/../proxies/',
    ];

    foreach ($folders as $folder) {
        $file = $folder . $className . '.php';
        if (is_file($file)) {
            require $file;
            return;
        }
    }
}, true, false);