<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class AzurLaneBridge extends BridgeAbstract
{
    public const NAME = 'Azur Lane News';
    public const URI = 'https://azurlane.yo-star.com/news/';
    public const DESCRIPTION = 'Latest news from Azur Lane official website with optional full article content';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    private const RSS_URL = 'https://azurlane.yo-star.com/news/feed/';

    private const IMG_STYLE = 'display: block; float: none; clear: both; max-width: 1600px; width: auto; height: auto; margin: 16px 0 16px 0; padding: 0;';

    private const LIMIT_PREVIEW_MAX = 10;
    private const LIMIT_FULL_MAX = 5;
    private const REQUEST_DELAY_MS = 1500;

    private const REMOVE_SELECTORS = [
        '.comments-area',
        '.comment-respond',
        '.reply',
        '.post-navigation',
        '.related-posts',
        '.sharedaddy',
        '.jp-relatedposts',
        '.yarpp-related',
        '.post-tags',
        '.tag-cloud',
        '.author-box',
        '.post-author',
        '.social-share',
        '.sharing',
        '#comments',
        '#respond',
    ];

    public const PARAMETERS = [
        [
            'fetch_full' => [
                'name' => 'Fetch full content',
                'type' => 'checkbox',
                'defaultValue' => false,
            ],
            'limit' => [
                'name' => 'Limit',
                'type' => 'number',
                'defaultValue' => 10,
                'title' => 'Maximum: 10 for previews, 5 for full articles',
            ]
        ]
    ];

    private function processYouTubeIframes(string $html): string
    {
        return preg_replace_callback(
            '/<iframe[^>]*src=["\'](?:https?:)?\/\/(?:www\.)?youtube\.com\/embed\/([a-zA-Z0-9_-]+)[^"\']*["\'][^>]*>/i',
            function (array $matches): string {
                $videoId = $matches[1];
                $thumbnailUrl = "https://img.youtube.com/vi/{$videoId}/maxresdefault.jpg";
                $fallbackUrl = "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg";
                $videoUrl = "https://www.youtube.com/watch?v={$videoId}";

                $thumbnailData = @file_get_contents($thumbnailUrl);
                if ($thumbnailData === false || strlen($thumbnailData) < 100) {
                    $thumbnailUrl = $fallbackUrl;
                }

                return sprintf(
                    '<p><a href="%s" target="_blank"><img src="%s" style="%s" alt="YouTube Video" /></a><a href="%s" target="_blank" style="display: block; text-align: center; margin: 0 0 16px 0;">Watch on YouTube</a></p>',
                    htmlspecialchars($videoUrl, ENT_QUOTES),
                    htmlspecialchars($thumbnailUrl, ENT_QUOTES),
                    self::IMG_STYLE,
                    htmlspecialchars($videoUrl, ENT_QUOTES)
                );
            },
            $html
        );
    }

    private function processHtmlContent(string $html): string
    {
        $html = preg_replace_callback('/<img\s+([^>]*)>/i', function (array $matches): string {
            $attributes = preg_replace('/\s+(width|height|align|style)=["\'][^"\']*["\']/i', '', $matches[1]);
            $attributes = trim($attributes);

            if ($attributes !== '') {
                $attributes .= ' ';
            }

            return '<img ' . $attributes . 'style="' . self::IMG_STYLE . '" align="left" />';
        }, $html);

        $html = preg_replace_callback('/<(p|div|h[1-6])\s+([^>]*)>/i', function (array $matches): string {
            $tag = strtolower($matches[1]);
            $attributes = trim(preg_replace('/\s*style=["\'][^"\']*text-align\s*:\s*center[^"\']*["\']/i', '', $matches[2]));

            if ($attributes === '') {
                return '<' . $tag . ' align="left">';
            }

            return '<' . $tag . ' ' . $attributes . ' align="left">';
        }, $html);

        return $html;
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

    private function resolveLimit(bool $fetchFull): int
    {
        $maxLimit = $fetchFull === true ? self::LIMIT_FULL_MAX : self::LIMIT_PREVIEW_MAX;
        $limitInput = $this->getInput('limit');

        if ($limitInput === null || (int)$limitInput <= 0) {
            return $maxLimit;
        }

        return min((int)$limitInput, $maxLimit);
    }

    private function fetchFullContent(string $link, int $count): string
    {
        if ($count > 0) {
            usleep(self::REQUEST_DELAY_MS * 1000);
        }

        $dom = getSimpleHTMLDOM($link);
        $articleBody = $dom->querySelector('.entry-content');

        if ($articleBody === null) {
            return '';
        }

        $this->removeGarbage($articleBody);
        return $articleBody->innerHTML;
    }

    private function processContent(string $content): string
    {
        $content = $this->processYouTubeIframes($content);
        $content = $this->processHtmlContent($content);
        return $content;
    }

    public function collectData(): void
    {
        $fetchFull = (bool)$this->getInput('fetch_full');
        $limit = $this->resolveLimit($fetchFull);

        $xmlString = getContents(self::RSS_URL);

        if ($xmlString === '') {
            throwServerException('Failed to fetch RSS feed from Azur Lane.');
        }

        libxml_use_internal_errors(true);
        $rss = simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NOCDATA);
        libxml_clear_errors();

        if ($rss === false || isset($rss->channel->item) === false) {
            throwServerException('Failed to parse Azur Lane RSS feed.');
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

            $content = $fetchFull === true ? $this->fetchFullContent($link, $count) : $description;
            $content = $this->processContent($content);

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
