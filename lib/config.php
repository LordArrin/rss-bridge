<?php

declare(strict_types=1);

use RSSBridge\Configuration;

$config = [];
if (file_exists(__DIR__ . '/../config.ini.php') === true) {
    $config = parse_ini_file(__DIR__ . '/../config.ini.php', true, INI_SCANNER_TYPED);
    if ($config === false) {
        http_response_code(500);
        exit("Error parsing config.ini.php\n");
    }
}
Configuration::loadConfiguration($config, getenv());
