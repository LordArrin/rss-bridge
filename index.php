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
    // Consider: ini_set('error_reporting', E_ALL & ~E_DEPRECATED);
    if ((error_reporting() & $code) === 0) {
        // Deprecation messages and other masked errors are typically ignored here
        return false;
    }
    if (getenv('APP_ENV') === 'DEV') {
        // For development
        throw new \ErrorException($message, 0, $code, $file, $line);
    }
    $text = sprintf(
        '%s at %s line %s',
        Url::sanitizeRoot($message),
        Url::sanitizeRoot($file),
        $line
    );
    $logger->warning($text);
    // todo: return false to prevent default error handler from running?
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
// System Messages (Notifications from ENV)
// ============================================================================

// Process single system message
$systemMessage = getenv('SYSTEM_MESSAGE');
if (is_string($systemMessage) === true && $systemMessage !== '') {
    $level = getenv('SYSTEM_MESSAGE_LEVEL');
    $level = is_string($level) ? $level : 'info';

    $titleRaw = getenv('SYSTEM_MESSAGE_TITLE');
    $title = is_string($titleRaw) && $titleRaw !== '' ? $titleRaw : null;

    $urlRaw = getenv('SYSTEM_MESSAGE_URL');
    $url = is_string($urlRaw) && $urlRaw !== '' ? $urlRaw : null;

    $links = [];
    if ($url !== null) {
        $links[] = ['label' => $title ?? 'More info', 'url' => $url];
    }

    switch ($level) {
        case 'warning':
            notify_warning($systemMessage, $title, null, [], $links);
            break;
        case 'error':
            notify_error($systemMessage, $title, null, [], $links);
            break;
        default:
            notify_info($systemMessage, $title, null, [], $links);
            break;
    }
}

// Process multiple system messages (JSON array)
$systemMessagesJson = getenv('SYSTEM_MESSAGES');
if (is_string($systemMessagesJson) === true && $systemMessagesJson !== '') {
    $messages = json_decode($systemMessagesJson, true);
    if (is_array($messages) === true) {
        foreach ($messages as $msg) {
            if (is_array($msg) === false || isset($msg['text']) === false || is_string($msg['text']) === false) {
                continue;
            }

            $text = $msg['text'];
            if ($text === '') {
                continue;
            }

            $level = isset($msg['level']) && is_string($msg['level']) ? $msg['level'] : 'info';
            $title = isset($msg['title']) && is_string($msg['title']) && $msg['title'] !== '' ? $msg['title'] : null;
            $url = isset($msg['url']) && is_string($msg['url']) && $msg['url'] !== '' ? $msg['url'] : null;

            $links = [];
            if ($url !== null) {
                $links[] = ['label' => $title ?? 'More info', 'url' => $url];
            }

            switch ($level) {
                case 'warning':
                    notify_warning($text, $title, null, [], $links);
                    break;
                case 'error':
                    notify_error($text, $title, null, [], $links);
                    break;
                default:
                    notify_info($text, $title, null, [], $links);
                    break;
            }
        }
    }
}

// ============================================================================
// Request Processing
// ============================================================================

$argv = $argv ?? null;
if (is_array($argv) === true) {
    parse_str(implode('&', array_slice($argv, 1)), $cliArgs);
    $request = Request::fromCli($cliArgs);
} else {
    $request = Request::fromGlobals();
}

$rssBridge = new RssBridge($container);

$response = $rssBridge->main($request);

$response->send();
