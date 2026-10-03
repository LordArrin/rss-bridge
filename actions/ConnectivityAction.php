<?php

declare(strict_types=1);

namespace RSSBridge\Actions;

use Json;
use Logger;
use Request;
use Response;
use RSSBridge\BridgeFactory;
use RSSBridge\Configuration;
use RSSBridge\SafeBridgeLoader;

final class ConnectivityAction implements ActionInterface
{
    private BridgeFactory $bridgeFactory;
    private SafeBridgeLoader $safeLoader;
    private Logger $logger;

    public function __construct(
        BridgeFactory $bridgeFactory,
        SafeBridgeLoader $safeLoader,
        Logger $logger
    ) {
        $this->bridgeFactory = $bridgeFactory;
        $this->safeLoader = $safeLoader;
        $this->logger = $logger;
    }

    public function __invoke(Request $request): Response
    {
        if (Configuration::getConfig('system', 'env') !== 'dev') {
            return new Response('This action is only available in dev environment!', 403);
        }

        $bridgeName = $request->get('bridge');
        if ($bridgeName === false || $bridgeName === null || $bridgeName === '') {
            return new Response(render_template('connectivity.html.php'));
        }

        $bridgeClassName = $this->bridgeFactory->createBridgeClassName((string) $bridgeName);
        if ($bridgeClassName === false) {
            // This endpoint is consumed by static/connectivity.js via fetch(),
            // so answer with JSON instead of a plain-text body or an
            // uncaught exception (which would render a stack trace page).
            return $this->jsonError(404, 'Bridge not found: ' . $bridgeName);
        }

        if ($this->bridgeFactory->isEnabled($bridgeClassName) === false) {
            return $this->jsonError(403, 'Bridge is not whitelisted: ' . $bridgeClassName);
        }

        return $this->reportBridgeConnectivity($bridgeClassName);
    }

    private function jsonError(int $code, string $message): Response
    {
        return new Response(
            Json::encode([
                'successful' => false,
                'http_code'  => null,
                'error'      => $message,
            ]),
            $code,
            ['content-type' => 'application/json']
        );
    }

    private function reportBridgeConnectivity(string $bridgeClassName): Response
    {
        $bridge = $this->safeLoader->createSafely($bridgeClassName);

        if ($this->safeLoader->isBridgeBroken($bridge) === true) {
            $brokenInfo = $this->safeLoader->getBrokenBridges()[$bridgeClassName] ?? ['message' => 'Unknown error'];
            return new Response(Json::encode([
                'bridge'     => $bridgeClassName,
                'successful' => false,
                'http_code'  => null,
                'error'      => 'Bridge is invalid: ' . $brokenInfo['message']
            ]), 200, ['content-type' => 'application/json']);
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
        } catch (\Throwable $e) {
            // Do not swallow the failure silently: log it and surface the
            // reason in the JSON report. Catching \Throwable on purpose -
            // connection problems may also raise \Error (e.g. type errors
            // inside the HTTP client), which \Exception would miss.
            $this->logger->info(sprintf(
                'Connectivity check failed for %s (%s): %s',
                $bridgeClassName,
                $bridge::URI,
                $e->getMessage()
            ));
            $result['error'] = $e->getMessage();
        }

        return new Response(Json::encode($result), 200, ['content-type' => 'application/json']);
    }
}
