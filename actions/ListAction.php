<?php

class ListAction implements ActionInterface
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
            // Using a secure bridge loader
            $bridge = $this->safeLoader->createSafely($bridgeClassName);

            // Check to see if the bridge is broken.
            if ($this->safeLoader->isBridgeBroken($bridge)) {
                // Skipping broken bridges
                continue;
            }

            $list->bridges[$bridgeClassName] = [
                'status'        => $this->bridgeFactory->isEnabled($bridgeClassName) ? 'active' : 'inactive',
                'uri'           => $bridge->getURI(),
                'donationUri'   => $bridge->getDonationURI(),
                'name'          => $bridge->getName(),
                'icon'          => $bridge->getIcon(),
                'parameters'    => $bridge->getParameters(),
                'maintainer'    => $bridge->getMaintainer(),
                'description'   => $bridge->getDescription()
            ];
        }
        $list->total = count($list->bridges);
        return new Response(Json::encode($list), 200, ['content-type' => 'application/json']);
    }
}