<?php

declare(strict_types=1);

use RSSBridge\Caches\CacheFactory;
use RSSBridge\Caches\CacheInterface;
use RSSBridge\Container;
use RSSBridge\Configuration;
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
    if (Configuration::getConfig('system', 'env') === 'dev') {
        $logger->addHandler(new ErrorLogHandler(Logger::DEBUG));
    } else {
        $logger->addHandler(new ErrorLogHandler(Logger::INFO));
    }

    $file_path  = Configuration::getConfig('logging', 'file_path');
    $file_level = Configuration::getConfig('logging', 'file_level');
    if ($file_path && $file_level) {
        $level = array_flip(Logger::LEVEL_NAMES)[strtoupper($file_level)];
        $logger->addHandler(new StreamHandler($file_path, $level));
    }

    return $logger;
};

$container['cache'] = function ($c) {
    $cacheFactory = $c['cache_factory'];
    $cache = $cacheFactory->create(Configuration::getConfig('cache', 'type'));
    return $cache;
};

$container['bridge_metadata_cache'] = function ($c) {
    return new BridgeMetadataCache(
        $c['cache'],
        [
            __DIR__ . '/../bridges',
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
