<?php

declare(strict_types=1);

namespace RSSBridge;

/**
 * Centralized path resolution for RSS-Bridge directories.
 *
 * This class provides absolute paths to critical directories used throughout
 * the application, ensuring consistent path resolution regardless of where
 * the code is called from.
 */
final class Paths
{
    private function __construct()
    {
    }

    /**
     * Returns the absolute path to the cache directory.
     *
     * This is the directory where file-based caches store their data.
     */
    public static function getCache(): string
    {
        return dirname(__DIR__) . '/cache/';
    }
}
