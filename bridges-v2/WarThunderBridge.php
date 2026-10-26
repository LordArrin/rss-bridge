<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class WarThunderBridge extends BridgeAbstract
{
    public const NAME = 'War Thunder';
    public const URI = 'https://warthunder.com/';
    public const DESCRIPTION = 'Latest news from War Thunder official website with optional full article content';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    private const IMG_STYLE = 'display: block; float: none; clear: both; max-width: 1600px; width: auto; height: auto; margin: 16px 0 16px 0; padding: 0;';
    private const LIST_STYLE = 'display: block; list-style-type: disc; margin: 16px 0 16px 24px; padding: 0; text-align: left;';
    private const LI_STYLE = 'display: list-item; margin: 4px 0; text-align: left;';

    private const LIMIT_PREVIEW_MAX = 10;
    private const LIMIT_FULL_MAX = 5;
    private const REQUEST_DELAY_MS = 1500;
    private const ITEMS_PER_PAGE = 18;
    private const CACHE_PREFIX_YT_THUMBNAIL = 'yt_thumb_';

    public const PARAMETERS = [
        [
            'language' => [
                'name' => 'Language',
                'type' => 'list',
                'values' => [
                    'English' => 'en',
                    'Deutsch' => 'de',
                    'Русский' => 'ru',
                    'Français' => 'fr',
                    'Español' => 'es',
                    'Português' => 'pt',
                    'Polski' => 'pl',
                    'Čeština' => 'cs',
                    '한국어' => 'ko',
                    '中文' => 'zh',
                ],
                'defaultValue' => 'en',
            ],
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

        $html = (string)preg_replace_callback('/<ul\s+([^>]*)>/i', function (array $matches): string {
            $attributes = $matches[1];
            $attributes = (string)preg_replace('/\s*style=["\'][^"\']*["\']/i', '', $attributes);
            $attributes = trim($attributes);

            if ($attributes !== '') {
                $attributes .= ' ';
            }

            return '<ul ' . $attributes . 'style="' . self::LIST_STYLE . '">';
        }, $html);

        $html = (string)preg_replace_callback('/<li\s+([^>]*)>/i', function (array $matches): string {
            $attributes = $matches[1];
            $attributes = (string)preg_replace('/\s*style=["\'][^"\']*["\']/i', '', $attributes);
            $attributes = trim($attributes);

            if ($attributes !== '') {
                $attributes .= ' ';
            }

            return '<li ' . $attributes . 'style="' . self::LI_STYLE . '">';
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

    private function removeGarbage(\Dom\Element $article): void
    {
        foreach ($article->querySelectorAll('script, style, nav, footer, .showcase, .e-video__button') as $element) {
            if ($element instanceof \Dom\Element === true) {
                $element->remove();
            }
        }
    }

    private function processImageContainers(\Dom\Element $article): void
    {
        $figures = $article->querySelectorAll('.e-figure.-no-caption, .e-figure');

        foreach ($figures as $figure) {
            if ($figure instanceof \Dom\Element === false) {
                continue;
            }

            $img = $figure->querySelector('img');
            if ($img === null) {
                continue;
            }

            $src = $img->getAttribute('src');
            if ($src === null || $src === '') {
                $src = $img->getAttribute('data-src');
            }

            if ($src === null || $src === '') {
                continue;
            }

            if (str_starts_with($src, '//') === true) {
                $src = 'https:' . $src;
            }

            $newImg = $figure->ownerDocument->createElement('img');
            $newImg->setAttribute('src', $src);
            $newImg->setAttribute('style', self::IMG_STYLE);
            $newImg->setAttribute('align', 'left');

            $alt = $img->getAttribute('alt');
            if ($alt !== null && $alt !== '') {
                $newImg->setAttribute('alt', $alt);
            }

            if ($figure->parentNode !== null) {
                $figure->parentNode->replaceChild($newImg, $figure);
            }
        }
    }

    private function processScreenshotLinks(\Dom\Element $article): void
    {
        $downloadWrappers = $article->querySelectorAll('.e-screenshot__download-wrapper');

        foreach ($downloadWrappers as $wrapper) {
            if ($wrapper instanceof \Dom\Element === false) {
                continue;
            }

            $links = $wrapper->querySelectorAll('a.e-screenshot__download-link');
            if (count($links) === 0) {
                $wrapper->remove();
                continue;
            }

            $linksHtml = '<p align="left" style="text-align: left; margin: 16px 0;">';
            foreach ($links as $link) {
                if ($link instanceof \Dom\Element === false) {
                    continue;
                }

                $href = $link->getAttribute('href');
                $text = trim($link->textContent ?? '');

                if ($href === null || $href === '') {
                    continue;
                }

                if (str_starts_with($href, '//') === true) {
                    $href = 'https:' . $href;
                }

                $startTag = '<p align="left" style="text-align: left; margin: 16px 0;">';
                if ($linksHtml !== $startTag) {
                    $linksHtml .= ' | ';
                }

                $escapedHref = htmlspecialchars($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $escapedText = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                $linksHtml .= '<a href="' . $escapedHref . '" target="_blank" rel="noopener">' . $escapedText . '</a>';
            }
            $linksHtml .= '</p>';

            $emptyParagraph = '<p align="left" style="text-align: left; margin: 16px 0;"></p>';
            if ($linksHtml !== $emptyParagraph && $wrapper->parentNode !== null) {
                $fragment = $wrapper->ownerDocument->createDocumentFragment();
                $result = $fragment->appendXml($linksHtml);
                if ($result === true) {
                    $wrapper->parentNode->replaceChild($fragment, $wrapper);
                } else {
                    $wrapper->remove();
                }
            } else {
                $wrapper->remove();
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

    private function parseDate(string $dateString): int
    {
        $dateString = trim($dateString);

        $months = [
            'января' => 'January',
            'февраля' => 'February',
            'марта' => 'March',
            'апреля' => 'April',
            'мая' => 'May',
            'июня' => 'June',
            'июля' => 'July',
            'августа' => 'August',
            'сентября' => 'September',
            'октября' => 'October',
            'ноября' => 'November',
            'декабря' => 'December',
            'january' => 'January',
            'february' => 'February',
            'march' => 'March',
            'april' => 'April',
            'may' => 'May',
            'june' => 'June',
            'july' => 'July',
            'august' => 'August',
            'september' => 'September',
            'october' => 'October',
            'november' => 'November',
            'december' => 'December',
        ];

        foreach ($months as $ru => $en) {
            $dateString = str_ireplace($ru, $en, $dateString);
        }

        $timestamp = strtotime($dateString);

        if ($timestamp === false || $timestamp === 0) {
            return time();
        }

        return $timestamp;
    }

    private function fetchNewsList(string $language, int $limit): array
    {
        $news = [];
        $pagesNeeded = (int)ceil($limit / self::ITEMS_PER_PAGE);

        for ($page = 1; $page <= $pagesNeeded; $page++) {
            if ($page > 1) {
                usleep(self::REQUEST_DELAY_MS * 1000);
            }

            $url = "https://warthunder.com/{$language}/news" . ($page > 1 ? "?page={$page}" : '');
            $html = getContents($url);

            if ($html === '') {
                break;
            }

            libxml_use_internal_errors(true);
            $dom = \Dom\HTMLDocument::createFromString($html);
            libxml_clear_errors();

            $items = $dom->querySelectorAll('.showcase__item.widget');

            foreach ($items as $item) {
                if ($item instanceof \Dom\Element === false) {
                    continue;
                }

                if (count($news) >= $limit) {
                    break 2;
                }

                $linkEl = $item->querySelector('.widget__link');
                $titleEl = $item->querySelector('.widget__title');
                $descEl = $item->querySelector('.widget__comment');
                $dateEl = $item->querySelector('.widget-meta__item--right');
                $imgEl = $item->querySelector('.widget__poster-media');

                if ($linkEl === null || $titleEl === null) {
                    continue;
                }

                $href = $linkEl->getAttribute('href');
                $title = trim($titleEl->textContent ?? '');
                $description = $descEl !== null ? trim($descEl->textContent ?? '') : '';
                $dateText = $dateEl !== null ? trim($dateEl->textContent ?? '') : '';
                $imageUrl = '';

                if ($imgEl !== null) {
                    $imageUrl = $imgEl->getAttribute('data-src') ?? $imgEl->getAttribute('src') ?? '';
                    if (str_starts_with($imageUrl, '//') === true) {
                        $imageUrl = 'https:' . $imageUrl;
                    }
                }

                if ($href !== null && str_starts_with($href, 'http') === false) {
                    $href = 'https://warthunder.com' . $href;
                }

                $news[] = [
                    'link' => $href,
                    'title' => $title,
                    'description' => $description,
                    'date' => $this->parseDate($dateText),
                    'image' => $imageUrl,
                ];
            }
        }

        return $news;
    }

    private function fetchFullContent(string $link, int $count): string
    {
        if ($count > 0) {
            usleep(self::REQUEST_DELAY_MS * 1000);
        }

        try {
            $html = getContents($link);

            if ($html === '') {
                return '';
            }

            libxml_use_internal_errors(true);
            $dom = \Dom\HTMLDocument::createFromString($html);
            libxml_clear_errors();

            $article = $dom->querySelector('.section--narrow.article');

            if ($article === null) {
                return '';
            }

            $this->removeGarbage($article);
            $this->processImageContainers($article);
            $this->processScreenshotLinks($article);

            $contentParts = [];
            $grids = $article->querySelectorAll('.g-grid');

            foreach ($grids as $grid) {
                if ($grid instanceof \Dom\Element === false) {
                    continue;
                }

                $col = $grid->querySelector('.g-col');
                if ($col !== null) {
                    $contentParts[] = $col->innerHTML;
                }
            }

            return implode("\n", $contentParts);
        } catch (\Exception $e) {
            return '';
        }
    }

    private function processContent(string $content): string
    {
        $content = $this->processYouTubeIframes($content);
        $content = $this->processHtmlContent($content);
        return $content;
    }

    public function collectData(): void
    {
        $languageInput = $this->getInput('language');
        $language = $languageInput !== null ? (string)$languageInput : 'en';

        $fetchFull = (bool)$this->getInput('fetch_full');
        $limit = $this->resolveLimit($fetchFull);

        $newsList = $this->fetchNewsList($language, $limit);

        if (count($newsList) === 0) {
            throwServerException('Failed to fetch news from War Thunder.');
        }

        $count = 0;

        foreach ($newsList as $news) {
            if ($count >= $limit) {
                break;
            }

            $title = $news['title'];
            $link = $news['link'];
            $description = $news['description'];
            $timestamp = $news['date'];
            $imageUrl = $news['image'];

            $content = $description;

            if ($imageUrl !== '') {
                $escapedImageUrl = htmlspecialchars($imageUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $content = '<img src="' . $escapedImageUrl . '" style="' . self::IMG_STYLE . '" align="left" />' . "\n" . $content;
            }

            if ($fetchFull === true && $link !== '') {
                $fullContent = $this->fetchFullContent($link, $count);
                if ($fullContent !== '') {
                    $content = $fullContent;
                }
            }

            $content = $this->processContent($content);

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
