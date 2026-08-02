<?php

declare(strict_types=1);

// Suppress deprecations (curl_close, $http_response_header, etc.)
// These are tracked separately via CI grep, not via PHPUnit failures.
error_reporting(E_ALL & ~E_DEPRECATED);

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/config.php';

// Ensure cache directory exists for tests
if (!is_dir(PATH_CACHE)) {
    mkdir(PATH_CACHE, 0755, true);
}