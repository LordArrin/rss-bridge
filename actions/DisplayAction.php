<?php

declare(strict_types=1);

namespace RSSBridge\Actions;

use ClientException;
use HttpException;
use Json;
use Logger;
use RSSBridge\BridgeAbstract;
use RSSBridge\BridgeFactory;
use RSSBridge\Caches\CacheInterface;
use RSSBridge\Configuration;
use RSSBridge\FeedItem;
use RSSBridge\Formats\FormatFactory;
use RSSBridge\SafeBridgeLoader;
use RateLimitException;
use Request;
use Response;

final class DisplayAction implements ActionInterface
{
    private CacheInterface $cache;
    private Logger $logger;
    private BridgeFactory $bridgeFactory;
    private SafeBridgeLoader $safeLoader;

    public function __construct(
        CacheInterface $cache,
        Logger $logger,
        BridgeFactory $bridgeFactory,
        SafeBridgeLoader $safeLoader
    ) {
        $this->cache = $cache;
        $this->logger = $logger;
        $this->bridgeFactory = $bridgeFactory;
        $this->safeLoader = $safeLoader;
    }

    public function __invoke(Request $request): Response
    {
        $bridgeName = $request->get('bridge');
        $format = $request->get('format');
        $noproxy = $request->get('_noproxy');

        if ($bridgeName === false || $bridgeName === null || $bridgeName === '') {
            return new Response(render(__DIR__ . '/../templates/error.html.php', ['message' => 'Missing bridge name parameter']), 400);
        }

        $bridgeClassName = $this->bridgeFactory->createBridgeClassName($bridgeName);
        if ($bridgeClassName === false || $bridgeClassName === null || $bridgeClassName === '') {
            return new Response(render(__DIR__ . '/../templates/error.html.php', ['message' => 'Bridge not found']), 404);
        }

        if ($format === false || $format === null || $format === '') {
            return new Response(render(__DIR__ . '/../templates/error.html.php', ['message' => 'You must specify a format']), 400);
        }

        if ($this->bridgeFactory->isEnabled($bridgeClassName) === false) {
            return new Response(render(__DIR__ . '/../templates/error.html.php', ['message' => 'This bridge is not whitelisted']), 400);
        }

        if (
            Configuration::getConfig('proxy', 'url') === true
            && Configuration::getConfig('proxy', 'by_bridge') === true
            && ($noproxy === true || $noproxy === 'on' || $noproxy === '1' || $noproxy === 1)
        ) {
            define('NOPROXY', true);
        }

        $cacheKey = 'http_' . json_encode($request->toArray());

        $bridge = $this->safeLoader->createSafely($bridgeClassName);

        $response = $this->createResponse($request, $bridge, $format);

        if ($response->getCode() === 200) {
            $ttl = $request->get('_cache_timeout');
            if (Configuration::getConfig('cache', 'custom_timeout') === true && isset($ttl) === true) {
                $ttl = (int) $ttl;
            } else {
                $ttl = $bridge->getCacheTimeout();
            }
            $this->cache->set($cacheKey, $response, $ttl);
        }

        return $response;
    }

    private function createResponse(Request $request, BridgeAbstract $bridge, string $format): Response
    {
        $items = [];

        try {
            $bridge->loadConfiguration();

            $remove = [
                'token',
                'action',
                'bridge',
                'format',
                '_noproxy',
                '_cache_timeout',
                '_error_time',
                '_',
            ];

            $requestArray = $request->toArray();
            $input = array_diff_key($requestArray, array_fill_keys($remove, ''));
            $bridge->setInput($input);
            $bridge->collectData();
            $items = $bridge->getItems();
        } catch (\Throwable $e) {
            if ($e instanceof ClientException) {
                $this->logger->debug(sprintf('Exception in DisplayAction(%s): %s', $bridge->getShortName(), create_sane_exception_message($e)));
            } elseif ($e instanceof RateLimitException) {
                $this->logger->debug(sprintf('Exception in DisplayAction(%s): %s', $bridge->getShortName(), create_sane_exception_message($e)));
                return new Response(render(__DIR__ . '/../templates/exception.html.php', ['e' => $e]), 429);
            } elseif ($e instanceof HttpException) {
                if (in_array($e->getCode(), [429, 503], true) === true) {
                    $this->logger->debug(sprintf('Exception in DisplayAction(%s): %s', $bridge->getShortName(), create_sane_exception_message($e)));
                    return new Response(render(__DIR__ . '/../templates/exception.html.php', ['e' => $e]), $e->getCode());
                }
            } else {
                $this->logger->error(sprintf('Exception in DisplayAction(%s)', $bridge->getShortName()), ['e' => $e]);
            }

            $errorOutput = Configuration::getConfig('error', 'output');
            $reportLimit = Configuration::getConfig('error', 'report_limit');
            $errorCount = 1;

            if ($reportLimit > 1) {
                $errorCount = $this->logBridgeError($bridge->getName(), $e->getCode());
            }

            if ($errorCount >= $reportLimit) {
                if ($errorOutput === 'feed') {
                    $items = [$this->createFeedItemFromException($e, $bridge)];
                } elseif ($errorOutput === 'http') {
                    return new Response(render(__DIR__ . '/../templates/exception.html.php', ['e' => $e]), 500);
                }
            }
        }

        $formatFactory = new FormatFactory();
        $formatObj = $formatFactory->create($format);

        $formatObj->setItems($items);
        $formatObj->setFeed($bridge->getFeed());
        $now = time();
        $formatObj->setLastModified($now);

        $headers = [
            'last-modified' => gmdate('D, d M Y H:i:s ', $now) . 'GMT',
            'content-type'  => $formatObj->getMimeType() . '; charset=UTF-8',
        ];

        $body = $formatObj->render();

        ini_set('mbstring.substitute_character', 'none');
        $body = mb_convert_encoding($body, 'UTF-8', 'UTF-8');

        return new Response($body, 200, $headers);
    }

    private function createFeedItemFromException(\Throwable $e, BridgeAbstract $bridge): array
    {
        $uniqueIdentifier = urlencode((string)(int)(time() / 86400));
        $title = sprintf('Bridge returned error %s! (%s)', $e->getCode(), $uniqueIdentifier);

        $item = [
            'title' => $title,
            'uri' => get_current_url(),
            'timestamp' => time(),
            'uid' => $bridge->getName() . '_' . $uniqueIdentifier,
            'content' => render_template(__DIR__ . '/../templates/bridge-error.html.php', [
                'error' => render_template(__DIR__ . '/../templates/exception.html.php', ['e' => $e]),
                'searchUrl' => self::createGithubSearchUrl($bridge),
                'issueUrl' => self::createGithubIssueUrl($bridge, $e),
                'maintainer' => $bridge->getMaintainer(),
            ]),
        ];

        return $item;
    }

    private function logBridgeError(string $bridgeName, int $code): int
    {
        $cacheKey = 'error_reporting_' . $bridgeName . '_' . $code;
        $report = $this->cache->get($cacheKey);

        if ($report !== false && $report !== null && $report !== '') {
            $report = Json::decode($report);
            $report['time'] = time();
            $report['count']++;
        } else {
            $report = [
                'error' => $code,
                'time' => time(),
                'count' => 1,
            ];
        }

        $ttl = 86400 * 5;
        $this->cache->set($cacheKey, Json::encode($report), $ttl);

        return $report['count'];
    }

    private static function createGithubIssueUrl(BridgeAbstract $bridge, \Throwable $e): string
    {
        $maintainer = $bridge->getMaintainer();
        if (str_contains($maintainer, ',') === true) {
            $maintainers = explode(',', $maintainer);
        } else {
            $maintainers = [$maintainer];
        }
        $maintainers = array_map('trim', $maintainers);

        $phpVer = phpversion();
        if ($phpVer === false || $phpVer === '') {
            $phpVer = 'Unknown';
        }

        $queryString = $_SERVER['QUERY_STRING'] ?? '';
        $query = [
            'title' => $bridge->getName() . ' failed with: ' . $e->getMessage(),
            'body' => sprintf(
                "```\n%s\n\n%s\n\nQuery string: %s\nVersion: %s\nOs: %s\nPHP version: %s\n```\nMaintainer: @%s",
                create_sane_exception_message($e),
                implode("\n", trace_to_call_points(trace_from_exception($e))),
                $queryString,
                Configuration::getVersion(),
                PHP_OS_FAMILY,
                $phpVer,
                implode(', @', $maintainers),
            ),
            'labels' => 'Bridge-Broken',
            'assignee' => $maintainer[0],
        ];

        return 'https://github.com/LordArrin/rss-bridge/issues/new?' . http_build_query($query);
    }

    private static function createGithubSearchUrl(BridgeAbstract $bridge): string
    {
        return sprintf(
            'https://github.com/LordArrin/rss-bridge/issues?q=%s',
            urlencode('is:issue is:open ' . $bridge->getName())
        );
    }
}
