<?php

declare(strict_types=1);

use RSSBridge\Configuration;
use RSSBridge\Http\Request;
use RSSBridge\Http\Response;
use RSSBridge\RssBridge;
use RSSBridge\Utils\Url;

require __DIR__ . '/vendor/autoload.php';
Configuration::loadConfiguration();

$container = require __DIR__ . '/lib/dependencies.php';

$logger = $container['logger'];

set_exception_handler(function (\Throwable $e) use ($logger) {
    $response = new Response(render(__DIR__ . '/templates/exception.html.php', ['e' => $e]), 500);
    $response->send();
    $logger->error('Uncaught Exception', ['e' => $e]);
});

set_error_handler(function ($code, $message, $file, $line) use ($logger) {
    if ((error_reporting() & $code) === 0) {
        return false;
    }
    if (getenv('APP_ENV') === 'DEV') {
        throw new \ErrorException($message, 0, $code, $file, $line);
    }
    $text = sprintf(
        '%s at %s line %s',
        Url::sanitizeRoot($message),
        Url::sanitizeRoot($file),
        $line
    );
    $logger->warning($text);
    return true;
});

// There might be some fatal errors which are not caught by set_error_handler() or \Throwable.
register_shutdown_function(function () use ($logger) {
    $error = error_get_last();
    if ($error !== null) {
        $message = sprintf(
            '(shutdown) %s: %s in %s line %s',
            $error['type'],
            Url::sanitizeRoot($error['message']),
            Url::sanitizeRoot($error['file']),
            $error['line']
        );
        $logger->error($message);
    }
});

// ============================================================================
// Request Processing
// ============================================================================

// Detect CLI by SAPI, never by $argv: with register_argc_argv=On (the PHP
// default) FPM populates $argv from the query string, which would turn every
// feed request into an empty frontpage request. CLI always has $argv.
if (PHP_SAPI === 'cli') {
    $cliArgs = [];
    $cliArgv = $argv ?? [];
    parse_str(implode('&', array_slice($cliArgv, 1)), $cliArgs);
    $request = Request::fromCli($cliArgs);
} else {
    $request = Request::fromGlobals();
}

$rssBridge = new RssBridge($container);

$response = $rssBridge->main($request);

$response->send();
