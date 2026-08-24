<?php

declare(strict_types=1);

if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

const PATH_LIB_CACHES = __DIR__ . '/../caches/';
const PATH_CACHE = __DIR__ . '/../cache/';

// Legacy autoloader for old bridges in global namespace.
spl_autoload_register(function ($className) {
    // Skip namespaced classes - Composer handles those
    if (str_contains($className, '\\')) {
        return;
    }

    // Skip classes that have already been migrated to Composer classmap.
    // This prevents "Cannot redeclare class" errors.
    $migratedClasses = [
        'Container',
        'ParameterValidator',
        'FeedParser',
        'FeedItem',
        'Configuration',
    ];

    if (in_array($className, $migratedClasses, true)) {
        return;
    }

    $folders = [
        __DIR__ . '/../bridges/',
        __DIR__ . '/../lib/',
    ];

    foreach ($folders as $folder) {
        $file = $folder . $className . '.php';
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
}, true, false);
