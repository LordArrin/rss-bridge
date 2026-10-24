<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

use function RSSBridge\Exceptions\throwServerException;

final class ThreeDNewsBridge extends BridgeAbstract
{
    public const NAME = '3DNews';
    public const URI = 'https://3dnews.ru/';
    public const DESCRIPTION = 'Latest news from 3DNews.ru with optional full article content';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    private const RSS_URLS = [
        'all' => 'https://3dnews.ru/news/rss/',
        'hardware' => 'https://3dnews.ru/hardware-news/rss',
        'software' => 'https://3dnews.ru/software-news/rss',
    ];

    private const IMG_STYLE = 'display: block; float: none; clear: both; max-width: 1600px; width: auto; height: auto; margin: 16px 0 16px 0; padding: 0;';

    private const LIMIT_PREVIEW_MAX = 20;
    private const LIMIT_FULL_MAX = 10;
    private const REQUEST_DELAY_MS = 1000;
    private const CACHE_PREFIX_YT_THUMBNAIL = 'yt_thumb_';

    private const REMOVE_SELECTORS = [
        '.caption',
        '.comment-warn',
        '.content-block-header.entry-meta',
        '.typo-hint',
        '.footer',
        '.footer-sub',
        '.rbxglob.relatedbox.content-block',
        '.denyOrgsWARN-footer',
        '.slider-track',
        '.ps-promo',
        '.sources_rel',
        '.button.print',
        '.related',
        '.js-related-slider',
        '.entry-header',
        '.entry-info',
        '[href="/competitions"]',
        '[href="/job"]',
        '#footer',
        '#btwLabel',
        '#currencyTicker',
        '#stripe',
        '#projects',
        '#right-sidebar',
        'div.strong.mid.menu-item.sidebar-chunk:nth-of-type(29)',
        'div.strong.mid.menu-item.sidebar-chunk:nth-of-type(32)',
    ];

    private const AD_KEYWORDS = ['adfox', 'advert', 'banner', 'promo', 'commercial', 'sponsor'];
    private const IMG_ATTRS = ['src', 'data-src', 'data-original', 'data-lazy-src', 'data-lazy', 'data-srcset', 'data-hi-res-src'];

    public const PARAMETERS = [
        [
            'category' => [
                'name' => 'Category',
                'type' => 'list',
                'values' => [
                    'All' => 'all',
                    'Hardware' => 'hardware',
                    'Software' => 'software',
                ],
                'defaultValue' => 'all',
            ],
            'fetch_full' => [
                'name' => 'Fetch full content',
                'type' => 'checkbox',
                'defaultValue' => false,
            ],
            'limit' => [
                'name' => 'Limit',
                'type' => 'number',
                'defaultValue' => 20,
                'title' => 'Maximum: 20 for previews, 10 for full articles',
            ]
        ]
    ];

    private function cleanImageUrl(string $url): string
    {
        $url = trim($url);
        $url = ltrim($url, '|/');

        if (str_starts_with($url, '//') === true) {
            $url = 'https:' . $url;
        }

        $url = (string)preg_replace('/\?.*$/', '', $url);
        $url = (string)preg_replace('/\/sm\./', '/', $url);
        $url = (string)preg_replace('/\.(800|400|200|100)\./', '.', $url);

        return $url;
    }

    private function extractImageUrl(\Dom\Element $img): string
    {
        foreach (self::IMG_ATTRS as $attr) {
            $value = $img->getAttribute($attr);

            if ($value === null || $value === '') {
                continue;
            }

            if ($attr === 'data-srcset') {
                $bestUrl = $this->parseSrcset($value);
                if ($bestUrl !== '') {
                    return $this->cleanImageUrl($bestUrl);
                }
            } elseif (str_contains($value, 'cdn.3dnews.ru') === true) {
                return $this->cleanImageUrl($value);
            }
        }

        $src = $img->getAttribute('src');
        if ($src !== null && $src !== '') {
            return $this->cleanImageUrl($src);
        }

        return '';
    }

    private function parseSrcset(string $srcset): string
    {
        $bestUrl = '';
        $bestSize = 0;

        foreach (explode(',', $srcset) as $part) {
            $part = trim($part);
            if (preg_match('/^([^\s]+)\s+(\d+)w$/', $part, $m) === 1) {
                $size = (int)$m[2];
                if ($size > $bestSize) {
                    $bestSize = $size;
                    $bestUrl = $m[1];
                }
            }
        }

        return $bestUrl;
    }

    private function isInAdBlock(\Dom\Element $element): bool
    {
        $current = $element;

        while ($current !== null) {
            if ($current instanceof \Dom\Element === false) {
                $current = $current->parentNode;
                continue;
            }

            $className = strtolower($current->getAttribute('class') ?? '');
            $id = strtolower($current->getAttribute('id') ?? '');

            foreach (self::AD_KEYWORDS as $keyword) {
                if (str_contains($className, $keyword) === true || str_contains($id, $keyword) === true) {
                    return true;
                }
            }

            $current = $current->parentNode;
        }

        return false;
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
                $img = '<img src="' . $escapedThumbnailUrl . '" style="' . self::IMG_STYLE . '" alt="YouTube Video" />';
                $linkClose = '</a>';
                $linkStyle = 'display: block; text-align: center; margin: 0 0 16px 0;';
                $textLink = '<a href="' . $escapedVideoUrl . '" target="_blank" rel="noopener" style="' . $linkStyle . '">Watch on YouTube</a>';

                return '<p>' . $linkOpen . $img . $linkClose . $textLink . '</p>';
            },
            $html
        );
    }

    private function processHtmlContent(string $html): string
    {
        $html = (string)preg_replace_callback('/<img\s+([^>]*)>/i', function (array $matches): string {
            $attributes = $matches[1];
            $attributes = (string)preg_replace('/\s+(width|height|align|style)=["\'][^"\']*["\']/i', '', $attributes);
            $attributes = trim($attributes);

            if ($attributes !== '') {
                $attributes .= ' ';
            }

            return '<img ' . $attributes . 'style="' . self::IMG_STYLE . '" align="left" />';
        }, $html);

        $html = (string)preg_replace_callback('/<(p|div|h[1-6])\s+([^>]*)>/i', function (array $matches): string {
            $tag = strtolower($matches[1]);
            $attributes = $matches[2];

            $newAttributes = (string)preg_replace('/\s*style=["\'][^"\']*text-align\s*:\s*center[^"\']*["\']/i', '', $attributes);
            $newAttributes = trim($newAttributes);

            if ($newAttributes === '') {
                return '<' . $tag . ' align="left">';
            }

            return '<' . $tag . ' ' . $newAttributes . ' align="left">';
        }, $html);

        return $html;
    }

    private function processArticleImages(\Dom\Element $article): void
    {
        $containers = $article->querySelectorAll('.js-mediator-article > div');

        foreach ($containers as $container) {
            if ($container instanceof \Dom\Element === false || $this->isInAdBlock($container) === true) {
                continue;
            }

            $images = $container->querySelectorAll('img');
            if (count($images) === 0) {
                continue;
            }

            $fullImageUrl = '';

            foreach ($images as $img) {
                if ($img instanceof \Dom\Element === false) {
                    continue;
                }

                $url = $this->extractImageUrl($img);
                if ($url !== '' && str_contains($url, '/sm.') === false) {
                    $fullImageUrl = $url;
                    break;
                }
            }

            if ($fullImageUrl === '') {
                $firstImg = $images[0];
                if ($firstImg instanceof \Dom\Element === true) {
                    $fullImageUrl = $this->extractImageUrl($firstImg);
                }
            }

            if ($fullImageUrl !== '' && $container->parentNode !== null) {
                $newImg = $container->ownerDocument->createElement('img');
                $newImg->setAttribute('src', $fullImageUrl);
                $newImg->setAttribute('style', self::IMG_STYLE);
                $newImg->setAttribute('align', 'left');

                $container->parentNode->replaceChild($newImg, $container);
            }
        }

        foreach ($article->querySelectorAll('img') as $img) {
            if ($img instanceof \Dom\Element === false) {
                continue;
            }

            if ($this->isInAdBlock($img) === true) {
                $img->remove();
                continue;
            }

            $cleanUrl = $this->extractImageUrl($img);
            if ($cleanUrl === '') {
                continue;
            }

            $img->setAttribute('src', $cleanUrl);
            $img->setAttribute('style', self::IMG_STYLE);
            $img->setAttribute('align', 'left');

            foreach (self::IMG_ATTRS as $attr) {
                if ($attr !== 'src') {
                    $img->removeAttribute($attr);
                }
            }
        }
    }

    private function removeGarbage(\Dom\Element $article): void
    {
        foreach ($article->querySelectorAll('script, style') as $element) {
            if ($element instanceof \Dom\Element === true) {
                $element->remove();
            }
        }

        foreach (self::REMOVE_SELECTORS as $selector) {
            foreach ($article->querySelectorAll($selector) as $element) {
                if ($element instanceof \Dom\Element === true) {
                    $element->remove();
                }
            }
        }
    }

    private function fetchFullArticleContent(string $link): string
    {
        try {
            $html = getContents($link);

            if ($html === '') {
                return '';
            }

            libxml_use_internal_errors(true);
            $dom = \Dom\HTMLDocument::createFromString($html);
            libxml_clear_errors();

            $articleBody = $dom->querySelector('.news-full-item-main-pub.news-full-item.article-entry');

            if ($articleBody === null) {
                return '';
            }

            $this->removeGarbage($articleBody);
            $this->processArticleImages($articleBody);

            return $articleBody->innerHTML ?? '';
        } catch (\Exception $e) {
            return '';
        }
    }

    public function collectData(): void
    {
        $categoryInput = $this->getInput('category');
        $category = $categoryInput !== null && $categoryInput !== '' ? (string)$categoryInput : 'all';

        $fetchFull = (bool)$this->getInput('fetch_full');
        $maxLimit = $fetchFull === true ? self::LIMIT_FULL_MAX : self::LIMIT_PREVIEW_MAX;

        $limitInput = $this->getInput('limit');
        $requestedLimit = $limitInput !== null && (int)$limitInput > 0 ? (int)$limitInput : $maxLimit;
        $limit = min($requestedLimit, $maxLimit);

        $rssUrl = self::RSS_URLS[$category] ?? self::RSS_URLS['all'];
        $xmlString = getContents($rssUrl);

        if ($xmlString === '') {
            throwServerException('Failed to fetch RSS feed from 3DNews.');
        }

        $parser = new \RSSBridge\FeedParser();
        try {
            $feed = $parser->parseFeed($xmlString);
        } catch (\Exception $e) {
            throwServerException('Failed to parse 3DNews RSS feed: ' . $e->getMessage());
        }

        if ($feed['items'] === []) {
            throwServerException('Failed to parse 3DNews RSS feed or feed is empty.');
        }

        $count = 0;

        foreach ($feed['items'] as $item) {
            if ($count >= $limit) {
                break;
            }

            $title = $item['title'] !== '' ? $item['title'] : 'Untitled';
            $link = $item['uri'] ?? '';
            $description = $item['content'] ?? '';
            $timestamp = $item['timestamp'] !== '' ? (int)$item['timestamp'] : time();

            $content = $description;

            if (isset($item['enclosures']) === true && is_array($item['enclosures']) === true && $item['enclosures'] !== []) {
                $enclosureUrl = $item['enclosures'][0];
                if ($enclosureUrl !== '') {
                    $escapedUrl = htmlspecialchars($enclosureUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $content = '<img src="' . $escapedUrl . '" style="' . self::IMG_STYLE . '" align="left" />' . $content;
                }
            }

            if ($fetchFull === true && $link !== '') {
                if ($count > 0) {
                    usleep(self::REQUEST_DELAY_MS * 1000);
                }

                $fullContent = $this->fetchFullArticleContent($link);
                if ($fullContent !== '') {
                    $content = $fullContent;
                }
            }

            $content = $this->processYouTubeIframes($content);
            $content = $this->processHtmlContent($content);

            $this->items[] = [
                'title'     => $title,
                'uri'       => $link,
                'content'   => $content,
                'timestamp' => $timestamp,
                'uid'       => hash('sha256', $link),
            ];

            $count++;
        }
    }
}
