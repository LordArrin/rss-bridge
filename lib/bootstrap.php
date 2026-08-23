<?php

if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

const PATH_LIB_CACHES = __DIR__ . '/../caches/';
const PATH_CACHE = __DIR__ . '/../cache/';

// Legacy autoloader for old bridges in global namespace.
// Note: classes in lib/ are now handled by Composer PSR-4 once they have namespace RSSBridge;
spl_autoload_register(function ($className) {
    // Skip namespaced classes - Composer handles those
    if (str_contains($className, '\\')) {
        return;
    }

    $folders = [
        __DIR__ . '/../bridges/',
        __DIR__ . '/../lib/',
    ];

    foreach ($folders as $folder) {
        $file = $folder . $className . '.php';
        if (is_file($file)) {
            require $file;
            return;
        }
    }
}, true, false);