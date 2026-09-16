<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

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

        $url = preg_replace('/\?.*$/', '', $url);
        $url = preg_replace('/\/sm\./', '/', $url);
        $url = preg_replace('/\.(800|400|200|100)\./', '.', $url);

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

    private function processYouTubeIframes(string $html): string
    {
        return preg_replace_callback(
            '/<iframe[^>]*src=["\'](?:https?:)?\/\/(?:www\.)?youtube\.com\/embed\/([a-zA-Z0-9_-]+)[^"\']*["\'][^>]*>/i',
            function ($matches) {
                $videoId = $matches[1];
                $thumbnailUrl = "https://img.youtube.com/vi/{$videoId}/maxresdefault.jpg";
                $fallbackUrl = "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg";
                $videoUrl = "https://www.youtube.com/watch?v={$videoId}";

                $thumbnailData = @file_get_contents($thumbnailUrl);
                if ($thumbnailData === false || strlen($thumbnailData) < 100) {
                    $thumbnailUrl = $fallbackUrl;
                }

                $linkStyle = 'display: block; text-align: center; margin: 0 0 16px 0;';

                return sprintf(
                    '<p><a href="%s" target="_blank"><img src="%s" style="%s" alt="YouTube Video" /></a><a href="%s" target="_blank" style="%s">Watch on YouTube</a></p>',
                    htmlspecialchars($videoUrl, ENT_QUOTES),
                    htmlspecialchars($thumbnailUrl, ENT_QUOTES),
                    self::IMG_STYLE,
                    htmlspecialchars($videoUrl, ENT_QUOTES),
                    $linkStyle
                );
            },
            $html
        );
    }

    private function processHtmlContent(string $html): string
    {
        $html = preg_replace_callback('/<img\s+([^>]*)>/i', function ($matches) {
            $attributes = $matches[1];
            $attributes = preg_replace('/\s+(width|height|align|style)=["\'][^"\']*["\']/i', '', $attributes);
            $attributes = trim($attributes);

            if ($attributes !== '') {
                $attributes .= ' ';
            }

            return '<img ' . $attributes . 'style="' . self::IMG_STYLE . '" align="left" />';
        }, $html);

        $html = preg_replace_callback('/<(p|div|h[1-6])\s+([^>]*)>/i', function ($matches) {
            $tag = strtolower($matches[1]);
            $attributes = $matches[2];

            $newAttributes = preg_replace('/\s*style=["\'][^"\']*text-align\s*:\s*center[^"\']*["\']/i', '', $attributes);
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

            if ($fullImageUrl === '' && count($images) > 0) {
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

    public function collectData(): void
    {
        $categoryInput = $this->getInput('category');
        if ($categoryInput !== null && $categoryInput !== '') {
            $category = (string)$categoryInput;
        } else {
            $category = 'all';
        }

        $fetchFull = (bool)$this->getInput('fetch_full');

        $maxLimit = $fetchFull === true ? self::LIMIT_FULL_MAX : self::LIMIT_PREVIEW_MAX;

        $limitInput = $this->getInput('limit');
        if ($limitInput !== null && (int)$limitInput > 0) {
            $requestedLimit = (int)$limitInput;
        } else {
            $requestedLimit = $maxLimit;
        }

        $limit = min($requestedLimit, $maxLimit);

        $rssUrl = self::RSS_URLS[$category] ?? self::RSS_URLS['all'];
        $xmlString = getContents($rssUrl);

        if ($xmlString === '') {
            throwServerException('Failed to fetch RSS feed from 3DNews.');
        }

        libxml_use_internal_errors(true);
        $rss = simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NOCDATA);
        libxml_clear_errors();

        if ($rss === false || isset($rss->channel->item) === false) {
            throwServerException('Failed to parse 3DNews RSS feed.');
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

            $enclosureImage = '';
            if (isset($item->enclosure) === true) {
                $enclosureUrl = (string)$item->enclosure['url'];
                if ($enclosureUrl !== '') {
                    $enclosureImage = '<img src="' . htmlspecialchars($enclosureUrl, ENT_QUOTES) . '" style="' . self::IMG_STYLE . '" align="left" />';
                }
            }

            $content = $enclosureImage . $description;

            if ($fetchFull === true && $link !== '') {
                if ($count > 0) {
                    usleep(self::REQUEST_DELAY_MS * 1000);
                }

                $dom = getSimpleHTMLDOM($link);
                $articleBody = $dom->querySelector('.news-full-item-main-pub.news-full-item.article-entry');

                if ($articleBody !== null) {
                    $this->removeGarbage($articleBody);
                    $this->processArticleImages($articleBody);
                    $content = $articleBody->innerHTML;
                }
            }

            $content = $this->processYouTubeIframes($content);
            $content = $this->processHtmlContent($content);

            $timestamp = $pubDate;
            if ($timestamp === false || $timestamp === 0) {
                $timestamp = time();
            }

            $this->items[] = [
                'title' => $title,
                'uri' => $link,
                'content' => $content,
                'timestamp' => $timestamp,
                'uid' => hash('sha256', $link),
            ];

            $count++;
        }
    }
}
