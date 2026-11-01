<?php

declare(strict_types=1);

/**
 * Returns the application version from the /app/VERSION file.
 * This file is generated during docker build and is immutable at runtime.
 *
 * @return string The version string, or 'unknown' if the file cannot be read.
 */
function get_rssbridge_version(): string
{
    $versionFile = dirname(__DIR__) . '/VERSION';

    if (file_exists($versionFile) === true) {
        $content = file_get_contents($versionFile);
        if ($content !== false) {
            $version = trim($content);
            if ($version !== '') {
                return $version;
            }
        }
    }

    return 'unknown';
}
