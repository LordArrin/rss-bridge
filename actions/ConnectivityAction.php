<?php

/**
 * Checks if the website for a given bridge is reachable.
 */
class ConnectivityAction implements ActionInterface
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
        if (Configuration::getConfig('system', 'env') !== 'dev') {
            return new Response('This action is only available in dev environment!', 403);
        }

        $bridgeName = $request->get('bridge');
        if (!$bridgeName) {
            return new Response(render_template('connectivity.html.php'));
        }
        $bridgeClassName = $this->bridgeFactory->createBridgeClassName($bridgeName);
        if (!$bridgeClassName) {
            return new Response('Bridge not found', 404);
        }
        return $this->reportBridgeConnectivity($bridgeClassName);
    }

    private function reportBridgeConnectivity($bridgeClassName)
    {
        if (!$this->bridgeFactory->isEnabled($bridgeClassName)) {
            throw new \Exception('Bridge is not whitelisted!');
        }

        // Using a secure bridge loader
        $bridge = $this->safeLoader->createSafely($bridgeClassName);

        // If the bridge is broken, return an error instead of crashing.
        if ($this->safeLoader->isBridgeBroken($bridge)) {
            $brokenInfo = $this->safeLoader->getBrokenBridges()[$bridgeClassName] ?? ['message' => 'Unknown error'];
            return new Response(Json::encode([
                'bridge'     => $bridgeClassName,
                'successful' => false,
                'http_code'  => null,
                'error'      => 'Bridge is invalid: ' . $brokenInfo['message']
            ]), 200, ['content-type' => 'text/json']);
        }

        $curl_opts = [
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
        ];
        $result = [
            'bridge'        => $bridgeClassName,
            'successful'    => false,
            'http_code'     => null,
        ];
        try {
            $response = getContents($bridge::URI, [], $curl_opts, true);
            $result['http_code'] = $response->getCode();
            if (in_array($result['http_code'], [200])) {
                $result['successful'] = true;
            }
        } catch (\Exception $e) {
        }

        return new Response(Json::encode($result), 200, ['content-type' => 'text/json']);
    }
}