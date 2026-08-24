<?php

declare(strict_types=1);

namespace RSSBridge\Actions;

use BridgeFactory;
use Json;
use Request;
use Response;
use RSSBridge\Configuration;
use SafeBridgeLoader;

final class ConnectivityAction implements ActionInterface
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
        if ($bridgeName === false) {
            return new Response(render_template('connectivity.html.php'));
        }

        $bridgeClassName = $this->bridgeFactory->createBridgeClassName($bridgeName);
        if ($bridgeClassName === false) {
            return new Response('Bridge not found', 404);
        }

        return $this->reportBridgeConnectivity($bridgeClassName);
    }

    private function reportBridgeConnectivity(string $bridgeClassName): Response
    {
        if ($this->bridgeFactory->isEnabled($bridgeClassName) === false) {
            throw new \Exception('Bridge is not whitelisted!');
        }

        $bridge = $this->safeLoader->createSafely($bridgeClassName);

        if ($this->safeLoader->isBridgeBroken($bridge) === true) {
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
            if (in_array($result['http_code'], [200], true) === true) {
                $result['successful'] = true;
            }
        } catch (\Exception $e) {
        }

        return new Response(Json::encode($result), 200, ['content-type' => 'text/json']);
    }
}
