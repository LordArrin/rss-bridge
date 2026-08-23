<?php

declare(strict_types=1);

namespace RSSBridge\Actions;

use BridgeFactory;
use Request;
use Response;
use SafeBridgeLoader;

final class ListAction implements ActionInterface
{
    private BridgeFactory $bridgeFactory;
    private SafeBridgeLoader $safeLoader;

    public function __construct(
        BridgeFactory $bridgeFactory,
        SafeBridgeLoader $safeLoader
    ) {
        $this->bridgeFactory = $bridgeFactory;
        $this->safeLoader = $safeLoader;
    }

    public function __invoke(Request $request): Response
    {
        $list = new \stdClass();
        $list->bridges = [];
        $list->total = 0;

        foreach ($this->bridgeFactory->getBridgeClassNames() as $bridgeClassName) {
            $bridge = $this->safeLoader->createSafely($bridgeClassName);

            if ($this->safeLoader->isBridgeBroken($bridge) === true) {
                continue;
            }

            $status = 'inactive';
            if ($this->bridgeFactory->isEnabled($bridgeClassName) === true) {
                $status = 'active';
            }

            $list->bridges[$bridgeClassName] = [
                'status'      => $status,
                'uri'         => $bridge->getURI(),
                'name'        => $bridge->getName(),
                'icon'        => $bridge->getIcon(),
                'parameters'  => $bridge->getParameters(),
                'maintainer'  => $bridge->getMaintainer(),
                'description' => $bridge->getDescription()
            ];
        }

        $list->total = count($list->bridges);

        return new Response(Json::encode($list), 200, ['content-type' => 'application/json']);
    }
}
