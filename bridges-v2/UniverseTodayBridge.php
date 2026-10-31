<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\FeedExpander;

use function urljoin;

final class UniverseTodayBridge extends FeedExpander
{
    public const NAME = 'Universe Today';
    public const URI = 'https://www.universetoday.com/';
    public const DESCRIPTION = 'Returns the latest articles from Universe Today';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    private const IMAGE_ATTRS_TO_REMOVE = ['class', 'width', 'height', 'sizes', 'style'];

    private const IMG_STYLE = 'display: block !important; max-width: 1600px !important; width: auto !important; height: auto !important; float: none !important; clear: both !important;';

    public const PARAMETERS = [
        '' => [
            'limit' => [
                'name' => 'Feed Item Limit',
                'required' => true,
                'type' => 'number',
                'defaultValue' => 10,
                'exampleValue' => 10,
                'title' => 'Maximum number of returned feed items. Default 10',
            ],
        ],
    ];

    public function collectData(): void
    {
        $limitInput = $this->getInput('limit');
        $limit = is_numeric($limitInput) === true ? (int) $limitInput : 10;
        $this->collectExpandableDatas(self::URI . 'rss.xml', $limit);
    }

    protected function parseItem(array $item): array
    {
        $item = $this->cleanAuthor($item);

        $articleUrl = (string) $item['uri'];
        $cacheKey = 'article_' . md5($articleUrl);
        $cachedHtml = $this->loadCacheValue($cacheKey);

        if (is_string($cachedHtml) === false || $cachedHtml === '') {
            $html = (string) getContents($articleUrl);
            if ($html === '') {
                return $item;
            }
            $this->saveCacheValue($cacheKey, $html, 7 * 24 * 60 * 60);
            $dom = \Dom\HTMLDocument::createFromString($html);
        } else {
            $dom = \Dom\HTMLDocument::createFromString($cachedHtml);
        }

        $articleMain = $dom->querySelector('main > article');
        if ($articleMain instanceof \Dom\Element === false) {
            return $item;
        }

        foreach ($articleMain->querySelectorAll('iframe') as $iframe) {
            if ($iframe instanceof \Dom\Element === false) {
                continue;
            }
            $src = $iframe->getAttribute('src') ?? '';
            if ($src !== '') {
                $link = $iframe->ownerDocument->createElement('a');
                $link->textContent = $src;
                $link->setAttribute('href', $src);
                $iframe->replaceWith($link);
            }
        }

        $this->fixRelativeLinks($articleMain);

        $selectorsToRemove = [
            '.article-header',
            'div.author-bio',
            'nav.article-navigation',
            '.author-footer--with-popular',
            '.author-footer',
            '.author-popular',
        ];

        foreach ($selectorsToRemove as $selector) {
            foreach ($articleMain->querySelectorAll($selector) as $node) {
                if ($node instanceof \Dom\Element === true) {
                    $node->remove();
                }
            }
        }

        $figures = $articleMain->querySelectorAll('figure');
        foreach ($figures as $figure) {
            if ($figure instanceof \Dom\Element === false) {
                continue;
            }
            $img = $figure->querySelector('img');
            if ($img instanceof \Dom\Element === false) {
                $figure->remove();
                continue;
            }

            $this->normalizeImageAttributes($img);
            $figure->replaceWith($img);
        }

        foreach ($articleMain->querySelectorAll('img') as $img) {
            if ($img instanceof \Dom\Element === false) {
                continue;
            }

            $parent = $img->parentElement;
            if ($parent instanceof \Dom\Element === true && $parent->tagName === 'figure') {
                continue;
            }

            $this->normalizeImageAttributes($img);
        }

        $html = $articleMain->innerHTML ?? '';

        $baseUri = rtrim(self::URI, '/');

        $html = preg_replace('/\b(src|href)="\/([^"]+)"/i', '$1="' . $baseUri . '/$2"', $html);
        $html = preg_replace('/\b(src|href)="\/\/([^"]+)"/i', '$1="https://$2"', $html);

        $item['content'] = $html;

        return $item;
    }

    private function cleanAuthor(array $item): array
    {
        $author = (string) ($item['author'] ?? '');
        if ($author === '') {
            return $item;
        }

        $cleanAuthor = preg_replace('/\s*\(https?:\/\/[^)]+\)\s*$/', '', $author);
        if (is_string($cleanAuthor) === true && $cleanAuthor !== '') {
            $item['author'] = trim($cleanAuthor);
        }

        return $item;
    }

    private function normalizeImageAttributes(\Dom\Element $img): void
    {
        foreach (self::IMAGE_ATTRS_TO_REMOVE as $attr) {
            $img->removeAttribute($attr);
        }
        $img->setAttribute('style', self::IMG_STYLE);
    }

    private function isAbsoluteOrSpecialUrl(string $url): bool
    {
        if ($url === '' || $url === '#') {
            return true;
        }

        $prefixes = ['http://', 'https://', 'mailto:', 'tel:', '#'];
        foreach ($prefixes as $prefix) {
            if (str_starts_with($url, $prefix) === true) {
                return true;
            }
        }

        return false;
    }

    private function fixRelativeLinks(\Dom\Element $element): void
    {
        $baseUri = rtrim(self::URI, '/');

        foreach ($element->querySelectorAll('a, img, source') as $node) {
            if ($node instanceof \Dom\Element === false) {
                continue;
            }

            if ($node->tagName === 'img' || $node->tagName === 'source') {
                foreach (['data-src', 'data-lazy-src', 'data-original'] as $lazyAttr) {
                    $lazyValue = $node->getAttribute($lazyAttr);
                    if ($lazyValue !== null && $lazyValue !== '' && str_starts_with($lazyValue, 'data:') === false) {
                        $node->setAttribute('src', $lazyValue);
                        $node->removeAttribute($lazyAttr);
                        break;
                    }
                }
            }

            $attrs = $node->tagName === 'a' ? ['href'] : ['src'];
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
}
