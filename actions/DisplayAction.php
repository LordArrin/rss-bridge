<?php

declare(strict_types=1);

namespace RSSBridge\Actions;

use RSSBridge\BridgeAbstract;
use RSSBridge\BridgeFactory;
use RSSBridge\Caches\CacheInterface;
use RSSBridge\FeedItem;
use RSSBridge\Formats\FormatFactory;
use RSSBridge\Formats\FormatInterface;
use RSSBridge\Http\FeedDeadline;
use RSSBridge\Http\Request;
use RSSBridge\Http\Response;
use RSSBridge\Logger\Logger;
use RSSBridge\Middlewares\CacheMiddleware;
use RSSBridge\SafeBridgeLoader;
use RSSBridge\Utils\Json;
use RSSBridge\Utils\Url;

/**
 * Renders a bridge feed for the requested format.
 *
 * Serving strategy (FreshRSS-friendly):
 *  - fresh cached data is rendered without contacting upstream;
 *  - warm regeneration and single-flight waits are bounded by FEED_TIMEOUT,
 *    falling back to stale data when the budget is exhausted;
 *  - cold generation runs under the hard FPM budget so the cache is populated
 *    even if the client already disconnected;
 *  - transient failures arm a server-side backoff marker (request attributes
 *    consumed by CacheMiddleware) instead of advertising Retry-After, which
 *    FreshRSS would turn into a domain-wide cooldown.
 *
 * Cache bookkeeping: a stored response carries its full TTL together with the
 * generation stamp of the bridge data it was rendered from, and it expires
 * together with that data. CacheMiddleware turns the pair into "remaining
 * max-age plus Age", so a reader never extends its own cache past the moment
 * this instance regenerates the feed.
 */
final class DisplayAction implements ActionInterface
{
    private CacheInterface $cache;
    private Logger $logger;
    private BridgeFactory $bridgeFactory;
    private SafeBridgeLoader $safeLoader;

    /**
     * Lease for data regeneration. Must stay above the hard FPM budget
     * (PHP_FPM_REQUEST_TERMINATE_TIMEOUT minus its reserve), otherwise a slow
     * generation outlives its own lease and a second worker starts a duplicate
     * upstream fetch.
     */
    private const DATA_LOCK_TTL = 180;
    private const DATA_WAIT_MS = 10000;
    private const DATA_POLL_MS = 200;
    private const BACKOFF_FLOOR = 120;
    private const BROKEN_BRIDGE_BACKOFF = 600;
    private const MAX_CACHE_TIMEOUT = 86400;

    /** IMF-fixdate (RFC 9110) */
    private const HTTP_DATE_FORMAT = 'D, d M Y H:i:s \G\M\T';

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

    /**
     * Handles a display request: validates parameters, resolves the bridge and
     * format, and returns a rendered feed (fresh, stale, or a per-feed error).
     */
    public function __invoke(Request $request): Response
    {
        $bridgeName = $request->get('bridge');
        $format = $request->get('format');

        if ($bridgeName === null || $bridgeName === '') {
            return new Response(render(__DIR__ . '/../templates/error.html.php', ['message' => 'Missing bridge name parameter']), 400);
        }

        if (is_string($bridgeName) === false) {
            return new Response(render(__DIR__ . '/../templates/error.html.php', ['message' => 'Invalid bridge name parameter']), 400);
        }

        $bridgeClassName = $this->bridgeFactory->createBridgeClassName($bridgeName);

        if ($bridgeClassName === null) {
            return new Response(render(__DIR__ . '/../templates/error.html.php', ['message' => 'Bridge not found']), 404);
        }

        if ($format === null || $format === '') {
            return new Response(render(__DIR__ . '/../templates/error.html.php', ['message' => 'You must specify a format']), 400);
        }

        if (is_string($format) === false) {
            return new Response(render(__DIR__ . '/../templates/error.html.php', ['message' => 'Invalid format parameter']), 400);
        }

        try {
            $formatObj = (new FormatFactory())->create($format);
        } catch (\InvalidArgumentException $e) {
            return new Response(render(__DIR__ . '/../templates/error.html.php', ['message' => $e->getMessage()]), 400);
        }

        if ($this->bridgeFactory->isEnabled($bridgeClassName) === false) {
            return new Response(render(__DIR__ . '/../templates/error.html.php', ['message' => 'Bridge is not enabled!']), 400);
        }

        $cacheKey = $request->getCacheKey();

        $bridge = $this->safeLoader->createSafely($bridgeClassName);

        if ($this->safeLoader->isBridgeBroken($bridge) === true) {
            $message = sprintf('The bridge "%s" failed to load: %s', $bridgeName, $bridge->getDescription());
            $this->logger->error(sprintf('Broken bridge stub returned for "%s"', $bridgeName));
            $request->withAttribute('_bridge_backoff', self::BROKEN_BRIDGE_BACKOFF);
            $request->withAttribute('_bridge_failure_status', 500);
            return new Response(render(__DIR__ . '/../templates/error.html.php', ['message' => $message]), 500);
        }

        $ttl = $bridge->getCacheTimeout();
        $ttlRaw = $request->get('_cache_timeout');
        $customTimeoutEnabled = filter_var(getenv('CACHE_CUSTOM_TIMEOUT'), FILTER_VALIDATE_BOOLEAN);

        if ($customTimeoutEnabled === true) {
            if (is_int($ttlRaw) === true) {
                $ttl = min($ttlRaw, self::MAX_CACHE_TIMEOUT);
            } elseif (is_string($ttlRaw) === true && is_numeric($ttlRaw) === true) {
                $ttl = min((int) $ttlRaw, self::MAX_CACHE_TIMEOUT);
            }
        }

        $ttl = max(0, $ttl);

        $request = $request->withAttribute('_resolved_cache_timeout', $ttl);

        $response = $this->createResponse($request, $bridge, $formatObj, $ttl);

        if (
            $response->getCode() === 200
            && $ttl > 0
            && $request->getAttribute('_bridge_error') !== true
            && $request->getAttribute('_bridge_stale_data') !== true
        ) {
            $generatedRaw = $request->getAttribute('_bridge_data_generated');
            $generated = (is_int($generatedRaw) === true && $generatedRaw > 0) ? $generatedRaw : time();
            $storageTtl = max(1, $ttl - max(0, time() - $generated));

            $response = $response
            ->withHeader(CacheMiddleware::CACHE_TTL_HEADER, (string) $ttl)
            ->withHeader(CacheMiddleware::CACHE_GENERATED_HEADER, (string) $generated);

            $this->cache->set($cacheKey, $response, $storageTtl);
        }

        return $response;
    }

    /**
     * Builds the feed response from cached or freshly collected bridge data.
     *
     * Warm regeneration and single-flight waits are bounded by the client
     * budget (FEED_TIMEOUT); on exhaustion the stale payload is served. Cold
     * generation (no stale fallback) runs under the hard FPM budget so the
     * cache is populated even if the client already timed out. Transient
     * upstream failures arm a server-side backoff marker; permanent 4xx are
     * left to the middleware negative cache.
     *
     * Publishes _bridge_data_generated so the response layer can align the
     * response lifetime with the lifetime of the data it was rendered from.
     */
    private function createResponse(Request $request, BridgeAbstract $bridge, FormatInterface $formatObj, int $ttl): Response
    {
        $items = [];
        $feed = [];
        $dataKey = $request->getDataCacheKey();
        $payload = null;
        $stalePayload = null;
        $dataLockAcquired = false;
        $dataGenerated = 0;

        if ($ttl > 0) {
            $entry = $this->cache->getWithStale($dataKey);
            $payload = $this->normalizeDataPayload($entry['fresh'] ?? null);
            $stalePayload = $this->normalizeDataPayload($entry['stale'] ?? null);

            if ($payload === null) {
                if ($this->cache->acquireLock($dataKey, self::DATA_LOCK_TTL) === true) {
                    $dataLockAcquired = true;
                } else {
                    $waited = $this->waitForFreshData($dataKey);
                    $payload = $waited['payload'];
                    $dataLockAcquired = $waited['lock'];
                }
            }
        }

        if ($payload === null && $dataLockAcquired === false && $ttl > 0) {
            if ($stalePayload !== null) {
                $this->logger->warning(sprintf('Single-flight wait exhausted for %s; serving stale data', $bridge->getShortName()));
                $request->withAttribute('_bridge_error', true);
                $request->withAttribute('_bridge_stale_data', true);
                $request->withAttribute('_bridge_backoff', self::BACKOFF_FLOOR);
                $request->withAttribute('_bridge_failure_status', 502);
                return $this->renderFeed($formatObj, $stalePayload['items'], $stalePayload['feed'], $stalePayload['generated']);
            }

            $request->withAttribute('_bridge_backoff', self::BACKOFF_FLOOR);
            $request->withAttribute('_bridge_failure_status', 504);
            return new Response(render(__DIR__ . '/../templates/error.html.php', [
                'message' => 'The feed is being generated by another request and did not become ready in time. Please retry shortly.',
            ]), 504);
        }

        if ($payload === null && $stalePayload === null) {
            FeedDeadline::useHardBudget();
            ignore_user_abort(true);
        }

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

            if ($payload !== null) {
                $items = $payload['items'];
                $feed = $payload['feed'];
                $dataGenerated = $payload['generated'];
            } else {
                $bridge->collectData();
                $items = $bridge->getItems();
                $feed = $bridge->getFeed();
                $dataGenerated = time();

                if ($ttl > 0) {
                    $normalizedItems = [];
                    foreach ($items as $item) {
                        if ($item instanceof FeedItem) {
                            $normalizedItems[] = $item->toArray();
                        } elseif (is_array($item) === true) {
                            $normalizedItems[] = $item;
                        }
                    }
                    $this->cache->set($dataKey, [
                        'items' => $normalizedItems,
                        'feed' => $feed,
                        'generated' => $dataGenerated,
                    ], $ttl);
                }
            }
        } catch (\Throwable $e) {
            $request->withAttribute('_bridge_error', true);
            [$statusCode, $headers] = \httpStatusFromException($e);

            $isPermanentClient = $e instanceof \HttpException
                && ($e instanceof \RateLimitException) === false
                && $statusCode >= 400 && $statusCode <= 499;

            if ($isPermanentClient === false) {
                $retryAfter = ($e instanceof \HttpException) ? $e->retryAfter : null;
                $backoff = max(self::BACKOFF_FLOOR, min(3600, $retryAfter ?? self::BACKOFF_FLOOR));
                $request->withAttribute('_bridge_backoff', $backoff);
                $request->withAttribute('_bridge_failure_status', $statusCode);
            }

            if ($stalePayload !== null && $isPermanentClient === false) {
                $this->logger->warning(sprintf(
                    'Serving stale bridge data for %s after upstream failure: %s',
                    $bridge->getShortName(),
                    $e->getMessage()
                ));
                $request->withAttribute('_bridge_stale_data', true);
                $items = $stalePayload['items'];
                $feed = $stalePayload['feed'];
                $dataGenerated = $stalePayload['generated'];
            } else {
                $feed = $bridge->getFeed();

                if ($e instanceof \HttpException) {
                    $this->logger->debug(sprintf('Exception in DisplayAction(%s): %s', $bridge->getShortName(), Url::createSaneExceptionMessage($e)));
                    $rawCode = $e->getCode();
                    if ($rawCode >= 400 && $rawCode <= 599) {
                        return new Response(render(__DIR__ . '/../templates/exception.html.php', ['e' => $e]), $statusCode, $headers);
                    }
                } else {
                    $this->logger->error(sprintf('Exception in DisplayAction(%s)', $bridge->getShortName()), ['e' => $e]);
                }

                $errorOutputRaw = getenv('ERROR_OUTPUT');
                $errorOutput = (is_string($errorOutputRaw) === true && $errorOutputRaw !== '') ? $errorOutputRaw : 'http';

                $reportLimitRaw = getenv('ERROR_REPORT_LIMIT');
                $reportLimit = 1;

                if (is_string($reportLimitRaw) === true && is_numeric($reportLimitRaw) === true) {
                    $reportLimit = max(1, (int) $reportLimitRaw);
                }

                $errorCount = 1;

                if ($reportLimit > 1) {
                    $errorCount = $this->logBridgeError($bridge->getName(), (int) $e->getCode());
                }

                if ($errorOutput === 'feed') {
                    if ($errorCount <= $reportLimit) {
                        $items = [$this->createFeedItemFromException($e, $bridge)];
                    }
                } elseif ($errorOutput === 'http') {
                    return new Response(render(__DIR__ . '/../templates/exception.html.php', ['e' => $e]), $statusCode, $headers);
                }
            }
        } finally {
            if ($dataLockAcquired === true) {
                $this->cache->releaseLock($dataKey);
            }
        }

        $request->withAttribute('_bridge_data_generated', $dataGenerated);

        return $this->renderFeed($formatObj, $items, $feed, $dataGenerated);
    }

    /**
     * Renders items/feed into the final 200 response with validators.
     *
     * Last-Modified is derived from the newest item timestamp. When no item
     * carries a timestamp, the data generation time is used instead of the
     * render time: the body then stays byte-identical across renders of the
     * same cached payload, which keeps the ETag stable and enables 304
     * responses. Without any anchor at all it falls back to now.
     *
     * @param list<FeedItem|array<string, mixed>> $items
     * @param array<string, mixed> $feed
     */
    private function renderFeed(FormatInterface $formatObj, array $items, array $feed, int $dataGenerated = 0): Response
    {
        $formatObj->setItems($items);
        $formatObj->setFeed($feed);

        $now = time();
        $lastModifiedTimestamp = 0;

        foreach ($formatObj->getItems() as $feedItem) {
            $itemTimestamp = $feedItem->getTimestamp();
            if ($itemTimestamp !== null && $itemTimestamp > $lastModifiedTimestamp) {
                $lastModifiedTimestamp = $itemTimestamp;
            }
        }

        if ($lastModifiedTimestamp === 0 && $dataGenerated > 0) {
            $lastModifiedTimestamp = $dataGenerated;
        }

        if ($lastModifiedTimestamp === 0 || $lastModifiedTimestamp > $now) {
            $lastModifiedTimestamp = $now;
        }

        $formatObj->setLastModified($lastModifiedTimestamp);

        $body = $formatObj->render();

        $previousSubstitute = ini_get('mbstring.substitute_character');
        ini_set('mbstring.substitute_character', 'none');

        try {
            $body = mb_convert_encoding($body, 'UTF-8', 'UTF-8');
        } finally {
            if (is_string($previousSubstitute) === true) {
                ini_set('mbstring.substitute_character', $previousSubstitute);
            }
        }

        $etag = '"' . md5($body) . '"';

        $headers = [
            'last-modified' => gmdate(self::HTTP_DATE_FORMAT, $lastModifiedTimestamp),
            'etag' => $etag,
            'content-type' => $formatObj->getMimeType() . '; charset=UTF-8',
        ];

        return new Response($body, 200, $headers);
    }

    /**
     * Waits for a concurrent regeneration to publish fresh data, bounded by
     * the client budget. Never falls back to uncoordinated regeneration.
     *
     * @return array{payload: array{items: list<array<string, mixed>>, feed: array<string, mixed>, generated: int}|null, lock: bool}
     */
    private function waitForFreshData(string $dataKey): array
    {
        $budgetMs = (int) min(self::DATA_WAIT_MS, FeedDeadline::clientRemaining() * 1000);
        $waited = 0;
        while ($waited < $budgetMs) {
            usleep(self::DATA_POLL_MS * 1000);
            $waited += self::DATA_POLL_MS;

            $entry = $this->cache->getWithStale($dataKey);
            $payload = $this->normalizeDataPayload($entry['fresh'] ?? null);
            if ($payload !== null) {
                return ['payload' => $payload, 'lock' => false];
            }

            if ($this->cache->acquireLock($dataKey, self::DATA_LOCK_TTL) === true) {
                return ['payload' => null, 'lock' => true];
            }
        }

        return ['payload' => null, 'lock' => false];
    }

    /**
     * Validates a cached data payload. Entries written before the generation
     * stamp existed report generated = 0 and keep the previous render-time
     * behaviour until they are regenerated.
     *
     * @return array{items: list<array<string, mixed>>, feed: array<string, mixed>, generated: int}|null
     */
    private function normalizeDataPayload(mixed $value): ?array
    {
        if (is_array($value) === false) {
            return null;
        }

        $itemsRaw = $value['items'] ?? null;
        $feedRaw = $value['feed'] ?? null;

        if (is_array($itemsRaw) === false || is_array($feedRaw) === false) {
            return null;
        }

        $items = [];
        foreach ($itemsRaw as $item) {
            if (is_array($item) === false) {
                return null;
            }

            /** @var array<string, mixed> $item */
            $items[] = $item;
        }

        $generatedRaw = $value['generated'] ?? null;
        $generated = (is_int($generatedRaw) === true && $generatedRaw > 0) ? $generatedRaw : 0;

        /** @var array<string, mixed> $feedRaw */
        return ['items' => $items, 'feed' => $feedRaw, 'generated' => $generated];
    }

    /**
     * @return array<string, mixed>
     */
    private function createFeedItemFromException(\Throwable $e, BridgeAbstract $bridge): array
    {
        $uniqueIdentifier = urlencode((string) (int) (time() / 86400));

        $title = sprintf('Bridge returned error %s! (%s)', $e->getCode(), $uniqueIdentifier);

        $item = [
            'title' => $title,
            'uri' => Url::getCanonicalUrl(),
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

        $stored = $this->cache->get($cacheKey);

        $report = null;
        $count = 1;

        if (is_string($stored) === true && $stored !== '') {
            try {
                $decoded = Json::decode($stored);

                if (is_array($decoded) === true && isset($decoded['count']) === true && is_int($decoded['count']) === true) {
                    $report = $decoded;
                    $count = $decoded['count'];
                }
            } catch (\JsonException $e) {
                $report = null;
            }
        }

        if ($report !== null) {
            $count++;
            $report['time'] = time();
            $report['count'] = $count;
        } else {
            $report = [
                'error' => $code,
                'time' => time(),
                'count' => 1,
            ];
        }

        $ttl = 86400 * 5;

        $this->cache->set($cacheKey, Json::encode($report), $ttl);

        return $count;
    }

    private static function createGithubIssueUrl(BridgeAbstract $bridge, \Throwable $e): string
    {
        $maintainer = $bridge->getMaintainer();

        if (str_contains($maintainer, ',') === true) {
            $maintainers = explode(',', $maintainer);
        } else {
            $maintainers = [$bridge->getMaintainer()];
        }

        $maintainers = array_map('trim', $maintainers);

        $phpVer = phpversion();

        $queryParams = [];

        $queryStringRaw = $_SERVER['QUERY_STRING'] ?? '';

        if (is_string($queryStringRaw) === true && $queryStringRaw !== '') {
            parse_str($queryStringRaw, $queryParams);
        }

        unset($queryParams['token'], $queryParams['_'], $queryParams['_error_time']);

        $queryString = http_build_query($queryParams);

        $query = [
            'title' => $bridge->getName() . ' failed with: ' . $e->getMessage(),
            'body' => sprintf(
                "```\n%s\n\n%s\n\nQuery string: %s\nVersion: %s\nOs: %s\nPHP version: %s```\nMaintainer: @%s",
                Url::createSaneExceptionMessage($e),
                implode("\n", Url::traceToCallPoints(Url::traceFromException($e))),
                $queryString,
                get_rssbridge_version(),
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
