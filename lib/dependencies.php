<?php

declare(strict_types=1);

use RSSBridge\BridgeFactory;
use RSSBridge\Caches\CacheFactory;
use RSSBridge\Caches\CacheInterface;
use RSSBridge\Container;
use RSSBridge\Http\CurlHttpClient;
use RSSBridge\Logger\ErrorLogHandler;
use RSSBridge\Logger\Logger;
use RSSBridge\Logger\SimpleLogger;
use RSSBridge\Logger\StreamHandler;
use RSSBridge\SafeBridgeLoader;
use RSSBridge\BridgeMetadataCache;
use RSSBridge\Actions\ConnectivityAction;
use RSSBridge\Actions\DisplayAction;
use RSSBridge\Actions\FrontpageAction;
use RSSBridge\Actions\HealthAction;
use RSSBridge\Actions\ListAction;
use RSSBridge\Middlewares\BasicAuthMiddleware;
use RSSBridge\Middlewares\CacheMiddleware;
use RSSBridge\Middlewares\ExceptionMiddleware;
use RSSBridge\Middlewares\MaintenanceMiddleware;
use RSSBridge\Middlewares\SecurityMiddleware;
use RSSBridge\Middlewares\TokenAuthenticationMiddleware;

$container = new Container();

// === Actions ===

$container[ConnectivityAction::class] = function ($c) {
    return new ConnectivityAction($c['bridge_factory'], $c['safe_bridge_loader']);
};

$container[DisplayAction::class] = function ($c) {
    return new DisplayAction($c['cache'], $c['logger'], $c['bridge_factory'], $c['safe_bridge_loader']);
};

$container[FrontpageAction::class] = function ($c) {
    return new FrontpageAction(
        $c['bridge_factory'],
        $c['safe_bridge_loader'],
        $c['bridge_metadata_cache']
    );
};

$container[HealthAction::class] = function ($c) {
    return new HealthAction($c['safe_bridge_loader'], $c['cache']);
};

$container[ListAction::class] = function ($c) {
    return new ListAction($c['bridge_factory'], $c['safe_bridge_loader']);
};

// === Core Services ===

$container['bridge_factory'] = function ($c) {
    return new BridgeFactory($c['cache'], $c['logger']);
};

$container['safe_bridge_loader'] = function ($c) {
    return new SafeBridgeLoader($c['bridge_factory'], $c['logger'], $c['cache']);
};

$container['http_client'] = function () {
    return new CurlHttpClient();
};

$container['cache_factory'] = function ($c) {
    return new CacheFactory($c['logger']);
};

$container['logger'] = function () {
    $logger = new SimpleLogger('rssbridge');

    $appEnv = getenv('APP_ENV');
    if ($appEnv !== false && $appEnv === 'DEV') {
        $logger->addHandler(new ErrorLogHandler(Logger::DEBUG));
    } else {
        $logger->addHandler(new ErrorLogHandler(Logger::INFO));
    }

    // Reading logging config directly from environment with explicit checks
    $filePathRaw = getenv('LOG_FILE_PATH');
    $filePath = ($filePathRaw !== false && $filePathRaw !== '') ? (string) $filePathRaw : '';

    $fileLevelRaw = getenv('LOG_FILE_LEVEL');
    $fileLevel = ($fileLevelRaw !== false && $fileLevelRaw !== '') ? (string) $fileLevelRaw : '';

    if ($filePath !== '' && $fileLevel !== '') {
        $level = array_flip(Logger::LEVEL_NAMES)[strtoupper($fileLevel)] ?? Logger::INFO;
        $logger->addHandler(new StreamHandler($filePath, $level));
    }

    return $logger;
};

$container['cache'] = function ($c) {
    $cacheFactory = $c['cache_factory'];

    // CacheFactory::create() now handles reading CACHE_TYPE internally if null is passed.
    // We pass it explicitly here for clarity, falling back to null if not set.
    $cacheTypeRaw = getenv('CACHE_TYPE');
    $cacheType = ($cacheTypeRaw !== false && $cacheTypeRaw !== '') ? (string) $cacheTypeRaw : null;

    return $cacheFactory->create($cacheType);
};

$container['bridge_metadata_cache'] = function ($c) {
    return new BridgeMetadataCache(
        $c['cache'],
        [
            __DIR__ . '/../bridges-v2',
        ]
    );
};

// === Middlewares ===

$container[BasicAuthMiddleware::class] = function () {
    return new BasicAuthMiddleware();
};

$container[CacheMiddleware::class] = function ($c) {
    return new CacheMiddleware($c['cache']);
};

$container[ExceptionMiddleware::class] = function ($c) {
    return new ExceptionMiddleware($c['logger']);
};

$container[MaintenanceMiddleware::class] = function () {
    return new MaintenanceMiddleware();
};

$container[SecurityMiddleware::class] = function () {
    return new SecurityMiddleware();
};

$container[TokenAuthenticationMiddleware::class] = function () {
    return new TokenAuthenticationMiddleware();
};

return $container;
