<?php

declare(strict_types=1);

namespace RSSBridge\Actions;

use RSSBridge\Http\HttpException;
use RSSBridge\Utils\Json;
use RSSBridge\Utils\Url;
use RSSBridge\Logger\Logger;
use RSSBridge\BridgeAbstract;
use RSSBridge\BridgeFactory;
use RSSBridge\Caches\CacheInterface;
use RSSBridge\Configuration;
use RSSBridge\Exceptions\ClientException;
use RSSBridge\Exceptions\RateLimitException;
use RSSBridge\FeedItem;
use RSSBridge\Formats\FormatFactory;
use RSSBridge\SafeBridgeLoader;
use RSSBridge\Http\Request;
use RSSBridge\Http\Response;

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

        if ($bridgeName === null || $bridgeName === '') {
            return new Response(render(__DIR__ . '/../templates/error.html.php', ['message' => 'Missing bridge name parameter']), 400);
        }

        $bridgeClassName = $this->bridgeFactory->createBridgeClassName($bridgeName);
        if ($bridgeClassName === null) {
            return new Response(render(__DIR__ . '/../templates/error.html.php', ['message' => 'Bridge not found']), 404);
        }

        if ($format === null || $format === '') {
            return new Response(render(__DIR__ . '/../templates/error.html.php', ['message' => 'You must specify a format']), 400);
        }

        if ($this->bridgeFactory->isEnabled($bridgeClassName) === false) {
            return new Response(render(__DIR__ . '/../templates/error.html.php', ['message' => 'This bridge is not whitelisted']), 400);
        }

        $params = $request->toArray();
        unset($params['token'], $params['action']);
        ksort($params);
        $cacheKey = 'http_' . json_encode($params);

        $bridge = $this->safeLoader->createSafely($bridgeClassName);

        if ($this->safeLoader->isBridgeBroken($bridge) === true) {
            $message = sprintf('The bridge "%s" failed to load: %s', $bridgeName, $bridge->getDescription());
            $this->logger->error(sprintf('Broken bridge stub returned for "%s"', $bridgeName));
            return new Response(render(__DIR__ . '/../templates/error.html.php', ['message' => $message]), 500);
        }

        $ttl = $request->get('_cache_timeout');
        if (Configuration::getConfig('cache', 'custom_timeout') === true && isset($ttl) === true) {
            $ttl = (int) $ttl;
            $ttl = max(1, min($ttl, 86400));
        } else {
            $ttl = $bridge->getCacheTimeout();
        }
        $request = $request->withAttribute('_resolved_cache_timeout', (int) $ttl);

        $response = $this->createResponse($request, $bridge, $format);

        if ($response->getCode() === 200) {
            if ((int) $ttl > 0) {
                $this->cache->set($cacheKey, $response, (int) $ttl);
            }
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
                $this->logger->debug(sprintf('Exception in DisplayAction(%s): %s', $bridge->getShortName(), Url::createSaneExceptionMessage($e)));
            } elseif ($e instanceof RateLimitException) {
                $this->logger->debug(sprintf('Exception in DisplayAction(%s): %s', $bridge->getShortName(), Url::createSaneExceptionMessage($e)));
                return new Response(
                    render(__DIR__ . '/../templates/exception.html.php', ['e' => $e]),
                    429,
                    self::rateLimitHeaders($e)
                );
            } elseif ($e instanceof HttpException) {
                $code = $e->getCode();
                if ($code >= 400 && $code <= 599) {
                    $this->logger->debug(sprintf('Exception in DisplayAction(%s): %s', $bridge->getShortName(), Url::createSaneExceptionMessage($e)));

                    $headers = [];
                    if ($e->retryAfter !== null && in_array($code, [429, 503], true) === true) {
                        $headers['retry-after'] = (string) $e->retryAfter;
                    }

                    return new Response(render(__DIR__ . '/../templates/exception.html.php', ['e' => $e]), $code, $headers);
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

        $body = $formatObj->render();

        ini_set('mbstring.substitute_character', 'none');
        $body = mb_convert_encoding($body, 'UTF-8', 'UTF-8');

        $lastModifiedTimestamp = $now;
        $etag = self::createETag($body);
        $previousEntry = $this->cache->getWithStale($this->createCacheKey($request))['stale'] ?? null;
        if ($previousEntry instanceof Response && $previousEntry->getCode() === 200) {
            $previousEtag = $previousEntry->getHeader('etag');
            $sameContent = $previousEtag === $etag
                || ($previousEtag === null && $previousEntry->getBody() === $body);
            if ($sameContent === true) {
                $previousLastModified = strtotime((string) $previousEntry->getHeader('last-modified'));
                if ($previousLastModified !== false && $previousLastModified < $now) {
                    $lastModifiedTimestamp = $previousLastModified;
                    $formatObj->setLastModified($previousLastModified);
                    $body = mb_convert_encoding($formatObj->render(), 'UTF-8', 'UTF-8');
                    $etag = $previousEtag;
                }
            }
        }

        $headers = [
            'last-modified' => gmdate('D, d M Y H:i:s ', $lastModifiedTimestamp) . 'GMT',
            'etag' => $etag,
            'content-type'  => $formatObj->getMimeType() . '; charset=UTF-8',
        ];

        return new Response($body, 200, $headers);
    }

    /**
     * Builds the headers for a 429 response. Rate-limiting readers
     * back off when Retry-After is present; the
     * default window matches the negative-caching TTL in CacheMiddleware so
     * what the reader sees and what we actually cache stay in sync.
     *
     * @return array<string,string>
     */
    private static function rateLimitHeaders(\Throwable $e): array
    {
        $retryAfter = 3600;
        if ($e instanceof HttpException && $e->retryAfter !== null) {
            $retryAfter = max(60, min(86400, $e->retryAfter));
        }
        return ['retry-after' => (string) $retryAfter];
    }

    /**
     * Builds a strong ETag (RFC 9110 section 8.8.3) from the response body:
     * md5 keeps the tag short and fixed-size regardless of feed length.
     */
    private static function createETag(string $body): string
    {
        return '"' . md5($body) . '"';
    }

    /**
     * Cache key for the request parameters. Must stay in sync with the key
     * built in __invoke() and with CacheMiddleware::createCacheKey().
     */
    private function createCacheKey(Request $request): string
    {
        $params = $request->toArray();
        unset($params['token'], $params['action']);
        ksort($params);
        return 'http_' . json_encode($params);
    }

    private function createFeedItemFromException(\Throwable $e, BridgeAbstract $bridge): array
    {
        $uniqueIdentifier = urlencode((string)(int)(time() / 86400));
        $title = sprintf('Bridge returned error %s! (%s)', $e->getCode(), $uniqueIdentifier);

        $item = [
            'title' => $title,
            'uri' => Url::getCurrentUrl(),
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
                Url::createSaneExceptionMessage($e),
                implode("\n", Url::traceToCallPoints(Url::traceFromException($e))),
                $queryString,
                Configuration::getVersion(),
                PHP_OS_FAMILY,
                $phpVer,
                implode(', @', $maintainers),
            ),
            'labels' => 'Bridge-Broken',
            'assignee' => $maintainers[0] ?? 'unknown',
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
