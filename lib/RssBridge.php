<?php

declare(strict_types=1);

use RSSBridge\Actions\ConnectivityAction;
use RSSBridge\Actions\DisplayAction;
use RSSBridge\Actions\FrontpageAction;
use RSSBridge\Actions\HealthAction;
use RSSBridge\Actions\ListAction;

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

        $actionClass = match($action) {
            'connectivity' => ConnectivityAction::class,
            'display' => DisplayAction::class,
            'frontpage' => FrontpageAction::class,
            'health' => HealthAction::class,
            'list' => ListAction::class,
            default => FrontpageAction::class,
        };

        /** @var ActionInterface $actionHandler */
        $actionHandler = $this->container[$actionClass];

        return $actionHandler($request);
    }
}
