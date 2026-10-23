<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\Exceptions\ClientException;
use RSSBridge\BridgeAbstract;

use function RSSBridge\Exceptions\throwServerException;

final class ItchBridge extends BridgeAbstract
{
    public const NAME = 'Itch.io Devlog';
    public const URI = 'https://itch.io/';
    public const DESCRIPTION = 'Returns devlog posts from Itch.io developers or games. Supports fetching full article content and private devlogs via authentication token.';
    public const MAINTAINER = 'LordArrin';
    public const CACHE_TIMEOUT = 3600;

    public const CONFIGURATION = [
        'token' => [
            'required' => false,
        ],
    ];

    private const CSS = [
        'img' => 'display: block; float: none; clear: both; max-width: 1600px; width: auto; height: auto; margin: 16px 0 16px 0; padding: 0;',
        'text' => 'text-align: left;',
    ];

    private const CACHE_PREFIX_YT_THUMBNAIL = 'yt_thumb_';
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY_US = 1000000;

    private const TIMEOUT_DEFAULT = 20;
    private const CONNECT_TIMEOUT_DEFAULT = 10;

    private const TIMEOUT_FLARESOLVERR = 75;
    private const CONNECT_TIMEOUT_FLARESOLVERR = 45;

    private string $proxyProfile = 'none';

    public const PARAMETERS = [
        [
            'url' => [
                'name' => 'Game URL',
                'type' => 'text',
                'required' => true,
                'exampleValue' => 'https://sad-crab.itch.io/innocent-witches',
                'title' => 'Full game URL - e.g. https://sad-crab.itch.io/innocent-witches',
            ],
            'token' => [
                'name' => 'Itch.io Token',
                'type' => 'text',
                'required' => false,
                'title' => 'Optional. The value of the "itchio" cookie from your browser. Required for private devlogs and bypassing 18+ gates. Leave empty to use server default',
            ],
            'fetch_full' => [
                'name' => 'Fetch full content',
                'type' => 'checkbox',
                'defaultValue' => false,
                'title' => 'If checked, the bridge will fetch and parse the full article page for each post instead of using the short RSS preview.',
            ],
            'limit' => [
                'name' => 'Limit',
                'type' => 'number',
                'defaultValue' => 10,
                'title' => 'Maximum number of items to return.',
            ]
        ],
        'global' => [
            'proxy_profile' => [
                'name' => 'Proxy profile',
                'type' => 'list',
                'values' => [
                    'None (fastest, no proxy)' => 'none',
                    //'FlareSolverr (slower, bypasses Cloudflare)' => 'flaresolverr', // disabled for now
                    'Trawl' => 'trawl',
                ],
                'required' => true,
                'defaultValue' => 'none',
            ],
        ],
    ];

    private function resolveToken(): string
    {
        $input = $this->getInput('token');
        if ($input !== null && $input !== '') {
            return (string) $input;
        }

        $option = $this->getOption('token');
        if ($option !== null && $option !== '') {
            return (string) $option;
        }

        return '';
    }

    private function buildProxyOptions(): array
    {
        $options = [
            'cache_ttl' => 3600,
        ];

        $token = $this->resolveToken();
        if ($token !== '') {
            $options['cookies'] = [
                ['name' => 'itchio', 'value' => $token, 'domain' => '.itch.io'],
                ['name' => 'itchio_age', 'value' => '1', 'domain' => '.itch.io'],
                ['name' => 'itchio_key', 'value' => $token, 'domain' => '.itch.io'],
            ];
        }

        if ($this->proxyProfile === 'flaresolverr') {
            $options['timeout'] = self::TIMEOUT_FLARESOLVERR;
            $options['connect_timeout'] = self::CONNECT_TIMEOUT_FLARESOLVERR;
        }

        return $options;
    }

    private function fetchWithRetry(string $url, array $options, string $context = ''): string
    {
        $lastException = null;

        $curlOptions = [];
        if ($this->proxyProfile === 'flaresolverr') {
            $curlOptions = [
                CURLOPT_TIMEOUT => self::TIMEOUT_FLARESOLVERR,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_FLARESOLVERR,
            ];
        } else {
            $curlOptions = [
                CURLOPT_TIMEOUT => self::TIMEOUT_DEFAULT,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_DEFAULT,
            ];
        }

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $html = '';

                if ($this->proxyProfile === 'none') {
                    $headers = [];
                    $token = $this->resolveToken();
                    if ($token !== '') {
                        $headers = [
                            'Cookie: itchio=' . $token . '; itchio_age=1; itchio_key=' . $token,
                        ];
                    }
                    $html = getContents($url, $headers, $curlOptions);
                } else {
                    $html = getProtectedContents($url, $this->proxyProfile, $options);
                }

                if ($html === '') {
                    throw new \Exception('Received empty HTML response');
                }

                if ($attempt > 1) {
                    $this->logger->info(sprintf(
                        'ItchBridge: %s succeeded on attempt %d/%d for %s via %s',
                        $context,
                        $attempt,
                        self::MAX_RETRIES,
                        $url,
                        $this->proxyProfile
                    ));
                }

                return $html;
            } catch (\Throwable $e) {
                $lastException = $e;

                $this->logger->warning(sprintf(
                    'ItchBridge: %s attempt %d/%d failed for %s via %s: %s',
                    $context,
                    $attempt,
                    self::MAX_RETRIES,
                    $url,
                    $this->proxyProfile,
                    $e->getMessage()
                ));

                if ($attempt < self::MAX_RETRIES) {
                    $delay = self::RETRY_DELAY_US * (2 ** ($attempt - 1));
                    usleep($delay);
                }
            }
        }

        throw $lastException;
    }

    private function checkYouTubeThumbnail(string $videoId): string
    {
        $cacheKey = self::CACHE_PREFIX_YT_THUMBNAIL . $videoId;
        $cached = $this->loadCacheValue($cacheKey);

        if (is_string($cached) === true) {
            return $cached;
        }

        $maxResUrl = "https://img.youtube.com/vi/{$videoId}/maxresdefault.jpg";
        $fallbackUrl = "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg";
        $thumbnailUrl = $fallbackUrl;

        try {
            $curlOptions = [
                CURLOPT_RANGE => 'bytes=0-0',
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
            ];

            $response = getContents($maxResUrl, [], $curlOptions, true);

            if ($response instanceof \RSSBridge\Http\Response) {
                $code = $response->getCode();
                if ($code >= 200 && $code < 400) {
                    $thumbnailUrl = $maxResUrl;
                }
            }
        } catch (\Exception $e) {
            // Fallback to hqdefault on error
        }

        $this->saveCacheValue($cacheKey, $thumbnailUrl, 604800);

        return $thumbnailUrl;
    }

    private function processYouTubeIframes(string $html): string
    {
        return (string)preg_replace_callback(
            '/<iframe[^>]*src=["\'](?:https?:)?\/\/(?:www\.)?youtube\.com\/embed\/([a-zA-Z0-9_-]+)[^"\']*["\'][^>]*>/i',
            function (array $matches): string {
                $videoId = $matches[1];
                $thumbnailUrl = $this->checkYouTubeThumbnail($videoId);
                $videoUrl = "https://www.youtube.com/watch?v={$videoId}";

                $escapedVideoUrl = htmlspecialchars($videoUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $escapedThumbnailUrl = htmlspecialchars($thumbnailUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                $linkOpen = '<a href="' . $escapedVideoUrl . '" target="_blank" rel="noopener">';
                $img = '<img src="' . $escapedThumbnailUrl . '" style="' . self::CSS['img'] . '" alt="YouTube Video" />';
                $linkClose = '</a>';
                $linkStyle = 'display: block; text-align: center; margin: 0 0 16px 0;';
                $textLink = '<a href="' . $escapedVideoUrl . '" target="_blank" rel="noopener" style="' . $linkStyle . '">Watch on YouTube</a>';

                return '<p>' . $linkOpen . $img . $linkClose . $textLink . '</p>';
            },
            $html
        );
    }

    private function processImages(string $html): string
    {
        if ($html === '') {
            return '';
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString('<div>' . $html . '</div>');
        libxml_clear_errors();

        $wrapper = $dom->querySelector('div');
        if ($wrapper === null) {
            return $html;
        }

        $this->limitImageSize($wrapper);
        $this->alignTextLeft($wrapper);

        return $wrapper->innerHTML ?? $html;
    }

    private function limitImageSize(\Dom\Node $node): void
    {
        if ($node instanceof \Dom\Element === false && $node instanceof \Dom\HTMLDocument === false) {
            return;
        }

        foreach ($node->querySelectorAll('img') as $img) {
            if ($img instanceof \Dom\Element === true) {
                $img->removeAttribute('width');
                $img->removeAttribute('height');
                $img->removeAttribute('align');
                $img->setAttribute('style', self::CSS['img']);
            }
        }
    }

    private function alignTextLeft(\Dom\Node $node): void
    {
        if ($node instanceof \Dom\Element === false && $node instanceof \Dom\HTMLDocument === false) {
            return;
        }

        foreach ($node->querySelectorAll('p, div, h1, h2, h3, h4, h5, h6') as $element) {
            if ($element instanceof \Dom\Element === false) {
                continue;
            }

            $style = $element->getAttribute('style') ?? '';
            if ($style !== '') {
                $newStyle = preg_replace('/text-align\s*:\s*center\s*;?/i', '', $style);
                if (is_string($newStyle) === true && $newStyle !== $style) {
                    $newStyle = trim($newStyle);
                    if ($newStyle === '') {
                        $element->removeAttribute('style');
                    } else {
                        $element->setAttribute('style', $newStyle);
                    }
                }
            }

            $element->setAttribute('align', 'left');
        }
    }

    private function fetchFullArticleContent(string $link, array $options): string
    {
        try {
            $html = $this->fetchWithRetry($link, $options, 'article');

            if ($html === '') {
                return '';
            }

            libxml_use_internal_errors(true);
            $dom = \Dom\HTMLDocument::createFromString($html);
            libxml_clear_errors();

            $selectors = [
                '.post_body.user_formatted.base_widget.object_text_widget_widget',
                '.post_body.user_formatted',
                '.post_body',
                '#post_body',
                'article .body',
                'main .post_content',
                'section.body',
            ];

            foreach ($selectors as $selector) {
                $postBody = $dom->querySelector($selector);
                if ($postBody !== null) {
                    return $postBody->innerHTML ?? '';
                }
            }

            return '';
        } catch (\Exception $e) {
            $this->logger->warning('ItchBridge: Failed to fetch full article: ' . $e->getMessage());
            return '';
        }
    }

    public function collectData(): void
    {
        $url = (string) $this->getInput('url');
        $limit = (int) ($this->getInput('limit') ?? 10);
        $fetchFull = (bool) $this->getInput('fetch_full');

        $proxyInput = (string) $this->getInput('proxy_profile');
        $this->proxyProfile = $proxyInput !== '' ? $proxyInput : 'none';

        if (preg_match('/^https?:\/\/([a-z0-9-]+)\.itch\.io(?:\/([a-z0-9-]+))?/', $url, $matches) === 0) {
            throw new ClientException('Invalid Itch.io URL. Please provide a valid developer or game URL.');
        }

        $developer = $matches[1];
        $game = $matches[2] ?? null;

        $rssUrl = $game !== null ? "https://{$developer}.itch.io/{$game}/devlog.rss" : "https://{$developer}.itch.io/devlog.rss";

        $options = $this->buildProxyOptions();

        try {
            $xmlString = $this->fetchWithRetry($rssUrl, $options, 'RSS feed');
        } catch (\Throwable $e) {
            throwServerException('Failed to fetch RSS feed from Itch.io: ' . $e->getMessage());
        }

        if ($xmlString === '') {
            throwServerException('Failed to fetch RSS feed from Itch.io. The feed might be empty or the developer/game does not exist.');
        }

        libxml_use_internal_errors(true);
        $rss = simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NOCDATA);
        libxml_clear_errors();

        if ($rss === false || isset($rss->channel->item) === false) {
            throwServerException('Failed to parse Itch.io RSS feed.');
        }

        $count = 0;
        foreach ($rss->channel->item as $item) {
            if ($count >= $limit) {
                break;
            }

            $title = (string)$item->title;
            $link = (string)$item->link;
            $description = (string)$item->description;
            $pubDate = strtotime((string)$item->pubDate);

            $content = $description;

            if ($fetchFull === true && $link !== '') {
                $fullContent = $this->fetchFullArticleContent($link, $options);
                if ($fullContent !== '') {
                    $content = $fullContent;
                }
            }

            $content = $this->processYouTubeIframes($content);
            $content = $this->processImages($content);

            $this->items[] = [
                'title' => $title,
                'uri' => $link,
                'content' => $content,
                'timestamp' => $pubDate !== false ? $pubDate : time(),
                'uid' => hash('sha256', $link),
            ];

            $count++;
        }
    }
}
