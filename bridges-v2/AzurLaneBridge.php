<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\FeedExpander;

final class AzurLaneBridge extends FeedExpander
{
    public const NAME = 'Azur Lane News';
    public const URI = 'https://azurlane.yo-star.com/news/';
    public const DESCRIPTION = 'Latest news from Azur Lane official website';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    private const RSS_URL = 'https://azurlane.yo-star.com/news/feed/';
    private const LIMIT_MAX = 10;
    private const CACHE_PREFIX_YT_THUMBNAIL = 'yt_thumb_';

    private const IMG_STYLE = 'display: block; float: none; clear: both; max-width: 1600px; width: auto; height: auto; margin: 16px 0 16px 0; padding: 0;';

    public const PARAMETERS = [
        [
            'limit' => [
                'name' => 'Limit',
                'type' => 'number',
                'defaultValue' => 10,
                'title' => 'Maximum number of items to return',
            ]
        ]
    ];

    public function collectData(): void
    {
        $limit = $this->resolveLimit();
        $this->collectExpandableDatas(self::RSS_URL, $limit);
    }

    protected function parseItem(array $item): array
    {
        $item['content'] = $this->processContent($item['content'] ?? '');
        return $item;
    }

    private function resolveLimit(): int
    {
        $limitInput = $this->getInput('limit');

        if ($limitInput === null || (int)$limitInput <= 0) {
            return self::LIMIT_MAX;
        }

        return min((int)$limitInput, self::LIMIT_MAX);
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
                $textLink = '<a href="' . $escapedVideoUrl . '" target="_blank" rel="noopener" style="display: block; text-align: center; margin: 0 0 16px 0;">Watch on YouTube</a>';

                return '<p>' . $linkOpen . $img . $linkClose . $textLink . '</p>';
            },
            $html
        );
    }

    private function processHtmlContent(string $html): string
    {
        $html = (string)preg_replace_callback('/<img\s+([^>]*)>/i', function (array $matches): string {
            $attributes = (string)preg_replace('/\s+(width|height|align|style)=["\'][^"\']*["\']/i', '', $matches[1]);
            $attributes = trim($attributes);

            if ($attributes !== '') {
                $attributes .= ' ';
            }

            return '<img ' . $attributes . 'style="' . self::IMG_STYLE . '" align="left" />';
        }, $html);

        $html = (string)preg_replace_callback('/<(p|div|h[1-6])\s+([^>]*)>/i', function (array $matches): string {
            $tag = strtolower($matches[1]);
            $regex = '/\s*style=["\'][^"\']*text-align\s*:\s*center[^"\']*["\']/i';
            $attributes = trim((string)preg_replace($regex, '', $matches[2]));

            if ($attributes === '') {
                return '<' . $tag . ' align="left">';
            }

            return '<' . $tag . ' ' . $attributes . ' align="left">';
        }, $html);

        return $html;
    }

    private function processContent(string $content): string
    {
        $content = $this->processYouTubeIframes($content);
        $content = $this->processHtmlContent($content);
        return $content;
    }
}
