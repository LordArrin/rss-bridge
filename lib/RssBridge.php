<?php

declare(strict_types=1);

namespace RSSBridge;

use RSSBridge\Actions\ActionInterface;
use RSSBridge\Actions\ConnectivityAction;
use RSSBridge\Actions\DisplayAction;
use RSSBridge\Actions\FrontpageAction;
use RSSBridge\Actions\HealthAction;
use RSSBridge\Actions\ListAction;
use RSSBridge\Container;
use RSSBridge\Http\Request;
use RSSBridge\Http\Response;
use RSSBridge\Middlewares\BasicAuthMiddleware;
use RSSBridge\Middlewares\CacheMiddleware;
use RSSBridge\Middlewares\ExceptionMiddleware;
use RSSBridge\Middlewares\MaintenanceMiddleware;
use RSSBridge\Middlewares\SecurityMiddleware;
use RSSBridge\Middlewares\TokenAuthenticationMiddleware;

final class RssBridge
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function main(Request $request): Response
    {
        $action = $request->get('action', 'frontpage');

        $actionClass = match ($action) {
            'connectivity' => ConnectivityAction::class,
            'display' => DisplayAction::class,
            'frontpage' => FrontpageAction::class,
            'health' => HealthAction::class,
            'list' => ListAction::class,
            default => FrontpageAction::class,
        };

        /** @var \RSSBridge\Actions\ActionInterface $actionHandler */
        $actionHandler = $this->container[$actionClass];

        // Build middleware stack (order matters!)
        $middlewares = [
            SecurityMiddleware::class,
            ExceptionMiddleware::class,
            MaintenanceMiddleware::class,
            BasicAuthMiddleware::class,
            TokenAuthenticationMiddleware::class,
            CacheMiddleware::class,
        ];

        // The action receives the very same Request object that flows
        // through the middleware chain (see processMiddlewares), so
        // attributes set by the action (e.g. the resolved cache TTL in
        // DisplayAction) are visible to middlewares like CacheMiddleware
        // when it decorates the response on the way back out.
        return $this->processMiddlewares($middlewares, $request, function (Request $sharedRequest) use ($actionHandler): Response {
            return $actionHandler($sharedRequest);
        });
    }

    private function processMiddlewares(
        array $middlewareClasses,
        Request $request,
        callable $handler
    ): Response {
        $stack = $handler;

        // Build middleware stack in reverse order (last middleware executes first)
        foreach (array_reverse($middlewareClasses) as $middlewareClass) {
            $middleware = $this->container[$middlewareClass];
            $stack = function (Request $request) use ($middleware, $stack): Response {
                return $middleware($request, $stack);
            };
        }

        return $stack($request);
    }
}
