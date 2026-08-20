<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

use function urljoin;

final class PanoramaBridge extends BridgeAbstract
{
    const MAINTAINER = 'LordArrin';
    const NAME = 'IA Panorama';
    const URI = 'https://panorama.pub';
    const DESCRIPTION = 'News feed of the Russian satirical information agency "Panorama"';
    const CACHE_TIMEOUT = 3600;
    const PARAMETERS = [];

    const REQUEST_DELAY_US = 800000;
    const ARTICLE_CACHE_TTL = 86400;
    const DATE_FORMAT = 'd-m-Y';
    const FETCH_DAYS_BACK = 1;
    const DEFAULT_AUTHOR = self::NAME;

    public function collectData(): void
    {
        $dates = $this->getDatesToFetch();
        $processedUris = [];

        foreach ($dates as $date) {
            $url = self::URI . '/news/' . $date;

            try {
                $html = $this->fetchHtml($url);
            } catch (\Exception $e) {
                continue;
            }

            $cards = $html->querySelectorAll('a.flex-col');

            foreach ($cards as $card) {
                $href = $card->getAttribute('href');
                if ($href === null) {
                    continue;
                }

                $uri = urljoin(self::URI, $href);
                $path = parse_url($uri, PHP_URL_PATH);

                if (!$this->isValidNewsUri($path, $processedUris)) {
                    continue;
                }

                $processedUris[] = $uri;

                $item = $this->processNewsCard($card, $uri);

                if ($item !== null) {
                    $this->items[] = $item;
                }

                usleep(self::REQUEST_DELAY_US);
            }

            usleep(self::REQUEST_DELAY_US);
        }
    }

    private function fetchHtml(string $url): \Dom\HTMLDocument
    {
        $html = getContents($url);
        if (empty($html)) {
            throw new \Exception("Failed to fetch {$url}");
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        return $dom;
    }

    private function fetchHtmlCached(string $url, int $ttl): ?\Dom\HTMLDocument
    {
        $cacheKey = 'panorama_article_' . md5($url);
        $cachedHtml = $this->cache->get($cacheKey);

        if ($cachedHtml === null) {
            try {
                $cachedHtml = getContents($url);
                if (!empty($cachedHtml)) {
                    $this->cache->set($cacheKey, $cachedHtml, $ttl);
                }
            } catch (\Exception $e) {
                return null;
            }
        }

        if (empty($cachedHtml)) {
            return null;
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($cachedHtml);
        libxml_use_internal_errors(false);

        return $dom;
    }

    private function getDatesToFetch(): array
    {
        $tzString = date_default_timezone_get();
        $timezone = new \DateTimeZone($tzString);
        $dates = [];

        $current = new \DateTime('now', $timezone);
        for ($i = 0; $i <= self::FETCH_DAYS_BACK; $i++) {
            $dates[] = $current->format(self::DATE_FORMAT);
            $current->modify('-1 day');
        }

        return $dates;
    }

    private function isValidNewsUri(?string $path, array $processedUris): bool
    {
        if ($path === null) {
            return false;
        }

        if (preg_match('/^\/news\/\d{2}-\d{2}-\d{4}$/', $path)) {
            return false;
        }

        if ($path === '/news' || $path === '/news/') {
            return false;
        }

        if (in_array($path, $processedUris)) {
            return false;
        }

        return true;
    }

    private function processNewsCard(\Dom\Element $card, string $uri): ?array
    {
        $previewTitle = $this->extractPreviewTitle($card);
        $previewImage = $this->extractPreviewImage($card);

        $articleHTML = $this->fetchHtmlCached($uri, self::ARTICLE_CACHE_TTL);
        if ($articleHTML === null) {
            return null;
        }

        return [
            'uri' => $uri,
            'uid' => $uri,
            'title' => $this->extractTitle($articleHTML, $previewTitle),
            'timestamp' => $this->extractTimestamp($articleHTML),
            'author' => $this->extractAuthor($articleHTML),
            'content' => $this->buildFinalContent(
                $this->extractImage($articleHTML, $previewImage),
                $this->extractContent($articleHTML, $previewTitle),
                $this->extractTitle($articleHTML, $previewTitle)
            )
        ];
    }

    private function extractPreviewTitle(\Dom\Element $card): string
    {
        $titleDiv = $card->querySelector('div.font-semibold');
        return $titleDiv !== null ? trim($titleDiv->textContent) : '';
    }

    private function extractPreviewImage(\Dom\Element $card): string
    {
        $imgTag = $card->querySelector('img');
        if ($imgTag === null) {
            return '';
        }

        $src = $imgTag->getAttribute('src');
        return $src !== null ? $this->normalizeUrl($src) : '';
    }

    private function extractTitle(\Dom\HTMLDocument $articleHTML, string $fallbackTitle): string
    {
        $h1 = $articleHTML->querySelector('h1[itemprop="headline"]');
        if ($h1 !== null) {
            return trim($h1->textContent);
        }

        $ogTitle = $articleHTML->querySelector('meta[property="og:title"]');
        if ($ogTitle !== null) {
            $content = $ogTitle->getAttribute('content');
            return $content !== null ? trim($content) : $fallbackTitle;
        }

        return $fallbackTitle;
    }

    private function extractTimestamp(\Dom\HTMLDocument $articleHTML): int
    {
        $publishedTime = $articleHTML->querySelector('meta[property="article:published_time"]');
        if ($publishedTime !== null) {
            $content = $publishedTime->getAttribute('content');
            if ($content !== null) {
                $timestamp = strtotime($content);
                if ($timestamp !== false) {
                    return $timestamp;
                }
            }
        }

        return time();
    }

    private function extractAuthor(\Dom\HTMLDocument $articleHTML): string
    {
        $authorTag = $articleHTML->querySelector('meta[property="article:author"]');
        if ($authorTag !== null) {
            $content = $authorTag->getAttribute('content');
            return $content !== null ? $content : self::DEFAULT_AUTHOR;
        }

        return self::DEFAULT_AUTHOR;
    }

    private function extractImage(\Dom\HTMLDocument $articleHTML, string $fallbackImage): string
    {
        $ogImage = $articleHTML->querySelector('meta[property="og:image"]');
        if ($ogImage !== null) {
            $content = $ogImage->getAttribute('content');
            if ($content !== null) {
                $imageUrl = trim($content);
                if ($imageUrl !== '') {
                    return $this->normalizeUrl($imageUrl);
                }
            }
        }

        return $this->normalizeUrl($fallbackImage);
    }

    private function normalizeUrl(string $url): string
    {
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        return $url;
    }

    private function extractContent(\Dom\HTMLDocument $articleHTML, string $fallbackDescription): string
    {
        $contentElem = $articleHTML->querySelector('div[itemprop="articleBody"]');
        if ($contentElem === null) {
            $contentElem = $articleHTML->querySelector('.entry-contents');
        }

        if ($contentElem !== null) {
            $junkSelectors = [
                'script',
                'style',
                'div[id*="yandex_rtb"]',
                '.sharethis-inline-share-buttons',
                '.alert'
            ];

            foreach ($contentElem->querySelectorAll(implode(',', $junkSelectors)) as $junk) {
                $junk->remove();
            }

            return $contentElem->innerHTML;
        }

        $ogDesc = $articleHTML->querySelector('meta[property="og:description"]');
        if ($ogDesc !== null) {
            $content = $ogDesc->getAttribute('content');
            if ($content !== null) {
                $description = trim($content);
                if ($description !== '') {
                    return '<p><em>' . htmlspecialchars($description) . '</em></p>';
                }
            }
        }

        return '<p><em>' . htmlspecialchars($fallbackDescription) . '</em></p>';
    }

    private function buildFinalContent(string $imageUrl, string $content, string $title): string
    {
        $finalContent = '';

        if ($imageUrl !== '') {
            $finalContent .= '<figure><img src="' . htmlspecialchars($imageUrl) . '" alt="' . htmlspecialchars($title) . '" /></figure><br/>';
        }

        $finalContent .= $content;

        return $finalContent;
    }
}
