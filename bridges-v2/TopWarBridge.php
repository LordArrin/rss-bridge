<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;
use RSSBridge\FeedParser;

use function urljoin;

final class TopWarBridge extends BridgeAbstract
{
    public const NAME = 'TopWar';
    public const URI = 'https://topwar.ru/';
    public const DESCRIPTION = 'Fetches full articles from TopWar.ru';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 1800;

    private const ARTICLE_CACHE_TTL = 604800;
    private const MAX_CAPTION_LENGTH = 500;

    private const ARTICLE_BODY_SELECTORS = [
        'div.full-story-text.text',
        'div.full-story-text',
    ];

    private const NOISE_SELECTORS = [
        'div.banner-full-story',
        'div.banner-block',
        'script',
        'style',
        'meta',
        'noscript',
    ];

    private const IMG_ATTRS_TO_REMOVE = ['class', 'width', 'height', 'sizes', 'srcset', 'align', 'loading', 'decoding'];

    private const IMG_STYLE = 'display:block;max-width:100%;width:auto;height:auto;margin:0 auto';
    private const CONTAINER_STYLE = 'display:block;clear:both;max-width:100%;margin:20px 0;text-align:center';
    private const CAPTION_STYLE = 'font-size:0.9em;margin:8px 0 15px 0;text-align:left';

    private const HEADING_STYLES = [
        'h2' => 'margin:30px 0 20px 0;font-weight:bold;font-size:1.4em',
        'h3' => 'margin:25px 0 15px 0;font-weight:bold;font-size:1.2em',
    ];

    private const LAZY_SRC_ATTRS = ['data-src', 'data-lazy-src', 'data-original'];

    public const PARAMETERS = [
        [
            'feed' => [
                'name' => 'Feed Section',
                'type' => 'list',
                'values' => [
                    'All News (Main)' => 'rssdzen',
                    'News' => 'news/rssdzen',
                    'Armament' => 'armament/rssdzen',
                    'History' => 'history/rssdzen',
                    'Opinions' => 'opinions/rssdzen',
                    'Video' => 'video/rssdzen',
                ],
                'defaultValue' => 'rssdzen',
            ],
        ],
    ];

    public function collectData(): void
    {
        $feedSlug = (string) ($this->getInput('feed') ?? 'rssdzen');
        $feedUrl = self::URI . $feedSlug . '.xml';

        $xmlString = getContents($feedUrl);
        if ($xmlString === '') {
            throwServerException('Empty response from ' . $feedUrl);
        }

        try {
            $feed = (new FeedParser())->parseFeed($xmlString);
        } catch (\Throwable $e) {
            throwServerException('Failed to parse feed from ' . $feedUrl . ': ' . $e->getMessage());
        }

        foreach ($feed['items'] as $item) {
            $articleUrl = (string) ($item['uri'] ?? '');
            if ($articleUrl === '') {
                continue;
            }

            $content = $this->fetchFullArticle($articleUrl);
            if ($content === '') {
                continue;
            }

            $author = (string) ($item['author'] ?? '');

            $this->items[] = [
                'title' => $this->resolveTitle($item),
                'uri' => $articleUrl,
                'content' => $content,
                'timestamp' => $this->resolveTimestamp($item),
                'author' => $author !== '' ? $author : null,
                'uid' => $articleUrl,
            ];
        }

        if ($this->items === []) {
            throwServerException('No articles could be extracted from ' . $feedUrl);
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function resolveTitle(array $item): string
    {
        $title = trim((string) ($item['title'] ?? ''));
        return $title !== '' ? $title : 'Untitled';
    }

    /**
     * @param array<string, mixed> $item
     */
    private function resolveTimestamp(array $item): int
    {
        $timestamp = $item['timestamp'] ?? null;

        if (is_int($timestamp) === true && $timestamp > 0) {
            return $timestamp;
        }

        if (is_string($timestamp) === true && $timestamp !== '') {
            $parsed = strtotime($timestamp);
            if ($parsed !== false) {
                return $parsed;
            }
        }

        return time();
    }

    private function fetchFullArticle(string $url): string
    {
        $cacheKey = 'topwar_article_' . md5($url);
        $cached = $this->loadCacheValue($cacheKey);

        if (is_string($cached) === true && $cached !== '') {
            return $cached;
        }

        try {
            $html = getContents($url);
        } catch (\Throwable $e) {
            $this->logger?->warning(sprintf('TopWar: failed to fetch %s: %s', $url, $e->getMessage()));
            return '';
        }

        if ($html === '') {
            return '';
        }

        $articleBody = $this->findArticleBody(parseHtmlDocument($html));
        if ($articleBody === null) {
            $this->logger?->warning(sprintf('TopWar: article body not found on %s', $url));
            return '';
        }

        $this->removeNoise($articleBody);
        $this->fixRelativeUrls($articleBody);
        $this->processImageContainers($articleBody);
        $this->processHeadings($articleBody);

        $content = $this->finalizeContent($articleBody->innerHTML ?? '');

        if (trim(strip_tags($content)) === '') {
            return '';
        }

        $this->saveCacheValue($cacheKey, $content, self::ARTICLE_CACHE_TTL);

        return $content;
    }

    private function findArticleBody(\Dom\HTMLDocument $dom): ?\Dom\Element
    {
        foreach (self::ARTICLE_BODY_SELECTORS as $selector) {
            $node = $dom->querySelector($selector);
            if ($node instanceof \Dom\Element === true) {
                return $node;
            }
        }

        return null;
    }

    private function removeNoise(\Dom\Element $articleBody): void
    {
        foreach (self::NOISE_SELECTORS as $selector) {
            foreach ($articleBody->querySelectorAll($selector) as $node) {
                $node->remove();
            }
        }
    }

    private function fixRelativeUrls(\Dom\Element $articleBody): void
    {
        $baseUri = rtrim(self::URI, '/');

        foreach ($articleBody->querySelectorAll('a, img, source, iframe') as $node) {
            if ($node instanceof \Dom\Element === false) {
                continue;
            }

            $this->promoteLazyImageSource($node);

            $attrs = $node->localName === 'a' ? ['href'] : ['src'];
            foreach ($attrs as $attr) {
                $value = $node->getAttribute($attr);
                if ($value === null || $value === '') {
                    continue;
                }

                $value = trim($value);
                if ($this->isAbsoluteOrSpecialUrl($value) === true) {
                    continue;
                }

                if (str_starts_with($value, '//') === true) {
                    $value = 'https:' . $value;
                } elseif (str_starts_with($value, '/') === true) {
                    $value = $baseUri . $value;
                } else {
                    $value = urljoin(self::URI, $value);
                }

                $node->setAttribute($attr, $value);
            }
        }
    }

    private function promoteLazyImageSource(\Dom\Element $node): void
    {
        if ($node->localName !== 'img' && $node->localName !== 'source') {
            return;
        }

        foreach (self::LAZY_SRC_ATTRS as $lazyAttr) {
            $lazyValue = $node->getAttribute($lazyAttr);
            if ($lazyValue !== null && $lazyValue !== '' && str_starts_with($lazyValue, 'data:') === false) {
                $node->setAttribute('src', $lazyValue);
                $node->removeAttribute($lazyAttr);
                return;
            }
        }
    }

    private function isAbsoluteOrSpecialUrl(string $url): bool
    {
        if ($url === '' || $url === '#') {
            return true;
        }

        foreach (['http://', 'https://', 'mailto:', 'tel:', '#', 'data:'] as $prefix) {
            if (str_starts_with($url, $prefix) === true) {
                return true;
            }
        }

        return false;
    }

    private function processImageContainers(\Dom\Element $articleBody): void
    {
        $containers = iterator_to_array($articleBody->querySelectorAll('[style*="text-align"]'));

        foreach ($containers as $container) {
            if ($container instanceof \Dom\Element === false) {
                continue;
            }

            if ($this->isCenteredContainer($container) === false) {
                continue;
            }

            if (count($container->querySelectorAll('img')) === 0) {
                continue;
            }

            foreach ($container->querySelectorAll('img') as $img) {
                $this->normalizeImageAttributes($img);
            }

            $container->setAttribute('style', self::CONTAINER_STYLE);

            $this->wrapCaption($container);
        }

        foreach ($articleBody->querySelectorAll('img') as $img) {
            $parent = $img->parentElement;
            if ($parent instanceof \Dom\Element === true && $this->isCenteredContainer($parent) === true) {
                continue;
            }

            $this->normalizeImageAttributes($img);
        }
    }

    private function isCenteredContainer(\Dom\Element $container): bool
    {
        $style = str_replace(' ', '', strtolower($container->getAttribute('style') ?? ''));
        return str_contains($style, 'text-align:center') === true;
    }

    private function normalizeImageAttributes(\Dom\Element $img): void
    {
        foreach (self::IMG_ATTRS_TO_REMOVE as $attr) {
            $img->removeAttribute($attr);
        }

        $img->setAttribute('style', self::IMG_STYLE);
    }

    private function wrapCaption(\Dom\Element $container): void
    {
        $next = $container->nextSibling;
        while ($next instanceof \Dom\Text && trim($next->textContent ?? '') === '') {
            $next = $next->nextSibling;
        }

        if ($next instanceof \Dom\Element === false) {
            return;
        }

        if ($next->localName !== 'div' && $next->localName !== 'span') {
            return;
        }

        if ($next->querySelector('img') !== null) {
            return;
        }

        $caption = trim($next->textContent ?? '');
        if ($caption === '' || mb_strlen($caption) > self::MAX_CAPTION_LENGTH) {
            return;
        }

        $ownerDocument = $container->ownerDocument;
        $parent = $container->parentNode;
        if ($ownerDocument === null || $parent === null) {
            return;
        }

        $wrapper = $ownerDocument->createElement('div');
        $wrapper->setAttribute('style', self::CAPTION_STYLE);
        $wrapper->textContent = $caption;

        $parent->insertBefore($wrapper, $next->nextSibling);
        $next->remove();
    }

    private function processHeadings(\Dom\Element $articleBody): void
    {
        foreach (self::HEADING_STYLES as $tag => $style) {
            foreach ($articleBody->querySelectorAll($tag) as $heading) {
                $existing = rtrim((string) ($heading->getAttribute('style') ?? ''), '; ');
                $heading->setAttribute('style', $existing !== '' ? $existing . ';' . $style : $style);
            }
        }
    }

    private function finalizeContent(string $html): string
    {
        $html = $this->fixEscapedIframes($html);

        $baseUri = rtrim(self::URI, '/');
        $html = preg_replace('/\b(src|href)="\/([^"]+)"/i', '$1="' . $baseUri . '/$2"', $html) ?? $html;
        $html = preg_replace('/\b(src|href)="\/\/([^"]+)"/i', '$1="https://$2"', $html) ?? $html;

        return trim($html);
    }

    private function fixEscapedIframes(string $html): string
    {
        $html = preg_replace('/&lt;(iframe\s[^>]*)\s*&gt;/i', '<$1>', $html) ?? $html;
        $html = preg_replace('/&lt;\/iframe\s*&gt;/i', '</iframe>', $html) ?? $html;

        return preg_replace('/<[\x{200B}-\x{200D}\x{FEFF}\x{200C}]iframe/iu', '<iframe', $html) ?? $html;
    }
}
