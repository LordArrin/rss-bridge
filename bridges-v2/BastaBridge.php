<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

use function urljoin;

final class BastaBridge extends BridgeAbstract
{
    public const NAME = 'Bastamag';
    public const URI = 'https://basta.media/';
    public const DESCRIPTION = 'Returns the newest articles.';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [[
        'limit' => [
            'name' => 'Limit',
            'type' => 'number',
            'defaultValue' => 10,
            'title' => 'Number of articles to fetch'
        ]
    ]];

    public function collectData(): void
    {
        $limit = (int) $this->getInput('limit');
        if ($limit <= 0) {
            $limit = 10;
        }

        $feedUrl = self::URI . 'spip.php?page=backend';
        $feedHtml = getContents($feedUrl);
        if ($feedHtml === '') {
            throwServerException('Failed to fetch RSS feed');
        }

        libxml_use_internal_errors(true);
        $xml = \Dom\XMLDocument::createFromString($feedHtml);
        libxml_use_internal_errors(false);

        $items = $xml->querySelectorAll('item');
        $count = 0;

        foreach ($items as $element) {
            if ($count >= $limit) {
                break;
            }

            $titleEl = $element->querySelector('title');
            $guidEl = $element->querySelector('guid');
            $dateEl = $element->querySelector('dc\:date');

            if ($titleEl === null || $guidEl === null) {
                continue;
            }

            $title = trim($titleEl->textContent ?? '');
            $uri = trim($guidEl->textContent ?? '');

            if ($title === '' || $uri === '') {
                continue;
            }

            $timestamp = null;
            if ($dateEl !== null) {
                $dateText = trim($dateEl->textContent ?? '');
                if ($dateText !== '') {
                    $parsed = strtotime($dateText);
                    if ($parsed !== false) {
                        $timestamp = $parsed;
                    }
                }
            }

            $articleData = $this->fetchArticleData($uri);

            $this->items[] = [
                'title'     => $title,
                'uri'       => $uri,
                'timestamp' => $timestamp,
                'author'    => $articleData['author'],
                'content'   => $articleData['content'],
            ];

            $count++;
        }
    }

    /**
     * @return array{content: string, author: string}
     */
    private function fetchArticleData(string $uri): array
    {
        $html = getContents($uri);
        if ($html === '') {
            return ['content' => '', 'author' => ''];
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        // Extract author before modifying the DOM
        $author = '';
        $authorBlock = $dom->querySelector('.mb-1.c-cartouche-authors');
        if ($authorBlock !== null) {
            $author = trim($authorBlock->textContent ?? '');
        }

        // Main article container. Falls back to the legacy SPIP "div.texte"
        // for older layouts.
        $content = $dom->querySelector('.o-main-content.o-content');
        if ($content === null) {
            $content = $dom->querySelector('div.texte');
        }
        if ($content === null) {
            return ['content' => '', 'author' => $author];
        }

        $this->removeJunk($content);
        $this->normalizeMedia($content);
        $this->resolveRelativeUrls($content);

        return [
            'content' => $content->innerHTML,
            'author' => $author,
        ];
    }

    /**
     * Removes site chrome and promotional blocks, but KEEPS the article's own
     * photo blocks (.o-content-logo lead image and .spip_document inline photos).
     */
    private function removeJunk(\Dom\Element $content): void
    {
        $junkSelectors = [
            // Structural chrome around the body.
            '.o-content-footer',
            '.o-content-aside',
            'aside',
            'form',
            '.ajax-form-container',
            '.formulaire_spip',

            // Interactive maps and embedded widgets.
            '.carto',
            'iframe',

            // Donation / newsletter / "Sur le même sujet" widgets.
            '.c-call--don',
            '.c-call--newsletter',
            '.c-call--modele',
            '.c-encart',
            '.c-encart--liens',
            '.c-encart--memesujet',
            '.c-encart--memesujet-logo',
            '.o-push',
            '.o-push--aside',

            // Legacy related-content blocks.
            '.sur-le-meme-sujet',
            '.meme-sujet',
            '.same-topic',
            '.lire-aussi',
            '.also-read',
            '.related',
            '.related-articles',
            '.articles-lies',
            '[data-role="related"]',

            // Inline markup that has no place in a feed.
            'script',
            'style',
            'noscript',
            'svg',
            '.fb-like',
            '.twitter-tweet',
        ];

        foreach ($junkSelectors as $selector) {
            foreach (iterator_to_array($content->querySelectorAll($selector)) as $junk) {
                $junk->remove();
            }
        }
    }

    /**
     * Feed readers do not understand <picture>/srcset. Unwrap <picture> into its
     * fallback <img>, restore lazy-loaded sources, and drop the responsive
     * attributes so only a plain absolute src remains.
     */
    private function normalizeMedia(\Dom\Element $content): void
    {
        foreach (iterator_to_array($content->querySelectorAll('picture')) as $picture) {
            $parent = $picture->parentNode;
            if ($parent === null) {
                continue;
            }
            $img = $picture->querySelector('img');
            if ($img !== null) {
                $parent->insertBefore($img, $picture);
            }
            $picture->remove();
        }

        foreach ($content->querySelectorAll('img') as $img) {
            $this->restoreLazyImage($img);

            foreach (['srcset', 'sizes', 'loading', 'onload', 'style', 'class', 'data-src', 'data-srcset'] as $attr) {
                if ($img->hasAttribute($attr) === true) {
                    $img->removeAttribute($attr);
                }
            }

            $img->setAttribute('style', 'max-width:100%;height:auto;');
        }
    }

    /**
     * Replaces a placeholder src (empty or data: URI) with the real image URL
     * found in one of the lazy-loading attributes.
     */
    private function restoreLazyImage(\Dom\Element $img): void
    {
        $src = $img->getAttribute('src') ?? '';
        if ($src !== '' && str_starts_with($src, 'data:') === false) {
            return;
        }

        foreach (['data-src', 'data-original', 'data-lazy-src', 'data-cfsrc'] as $attr) {
            $candidate = $img->getAttribute($attr);
            if (
                $candidate !== null
                && $candidate !== ''
                && str_starts_with($candidate, 'data:') === false
            ) {
                $img->setAttribute('src', $candidate);
                return;
            }
        }

        foreach (['data-srcset', 'srcset'] as $attr) {
            $candidate = $img->getAttribute($attr);
            if ($candidate === null || $candidate === '') {
                continue;
            }
            $url = self::bestSrcsetUrl($candidate);
            if ($url !== null) {
                $img->setAttribute('src', $url);
                return;
            }
        }
    }

    /**
     * Picks the largest candidate from a srcset attribute.
     */
    private static function bestSrcsetUrl(string $srcset): ?string
    {
        $best = null;
        $bestWidth = -1;

        foreach (explode(',', $srcset) as $candidate) {
            $parts = preg_split('/\s+/', trim($candidate));
            if ($parts === false || $parts === [] || $parts[0] === '') {
                continue;
            }

            $url = $parts[0];
            if (str_starts_with($url, 'data:') === true) {
                continue;
            }

            $width = -1;
            if (isset($parts[1]) === true && preg_match('/^(\d+)w$/', $parts[1], $m) === 1) {
                $width = (int) $m[1];
            }

            if ($width >= $bestWidth) {
                $bestWidth = $width;
                $best = $url;
            }
        }

        return $best;
    }

    private function resolveRelativeUrls(\Dom\Element $container): void
    {
        $base = self::URI;
        $elements = $container->querySelectorAll('[src], [href], [poster]');

        foreach ($elements as $el) {
            foreach (['src', 'href', 'poster'] as $attr) {
                if ($el->hasAttribute($attr) === false) {
                    continue;
                }

                $value = $el->getAttribute($attr) ?? '';

                if (
                    $value === '' ||
                    preg_match('#^(https?:)?//#i', $value) === 1 ||
                    str_starts_with($value, 'data:') === true ||
                    str_starts_with($value, 'mailto:') === true ||
                    str_starts_with($value, 'javascript:') === true ||
                    str_starts_with($value, 'tel:') === true
                ) {
                    continue;
                }

                $resolvedUrl = urljoin($base, $value);
                if ($resolvedUrl !== null && $resolvedUrl !== '') {
                    $el->setAttribute($attr, $resolvedUrl);
                }
            }
        }
    }
}
