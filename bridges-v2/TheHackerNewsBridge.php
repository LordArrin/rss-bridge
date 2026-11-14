<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class TheHackerNewsBridge extends BridgeAbstract
{
    public const NAME = 'The Hacker News';
    public const URI = 'https://thehackernews.com/';
    public const DESCRIPTION = 'Cyber Security, Hacking, Technology News';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    private const IMG_STYLE = 'display: block; float: none; clear: both; max-width: 800px; width: auto; height: auto; margin: 0; padding: 0;';
    private const WRAPPER_STYLE = 'display: block; clear: both; margin: 16px 0; padding: 0; text-align: left;';
    private const UL_STYLE = 'list-style-type: disc; margin: 12px 0; padding-left: 24px; clear: both;';
    private const LI_STYLE = 'margin: 6px 0;';

    private const JUNK_SELECTORS = [
        '.lazyload',
        'center.cf',
        'script',
        'style',
        'noscript',
        'iframe',
        '.social-share',
        '.related-posts',
        '.ad-container',
        '.note-b.cf',
    ];

    protected const LIMIT = 10;

    public function collectData(): void
    {
        $html = getContents(self::URI);

        if (is_string($html) === false || $html === '') {
            throwServerException('Empty response from The Hacker News homepage');
        }

        try {
            libxml_use_internal_errors(true);
            $dom = \Dom\HTMLDocument::createFromString($html);
            libxml_use_internal_errors(false);
        } catch (\Throwable) {
            libxml_use_internal_errors(false);
            throwServerException('Failed to parse The Hacker News homepage');
        }

        $elements = $dom->querySelectorAll('div.body-post');
        $count = 0;

        foreach ($elements as $element) {
            if ($count >= self::LIMIT) {
                break;
            }

            if ($element instanceof \Dom\Element === false) {
                continue;
            }

            [$articleAuthor, $articleTimestamp] = $this->extractAuthorAndDate($element);

            $titleNode = $element->querySelector('h2.home-title');
            $articleTitle = ($titleNode !== null) ? trim($titleNode->textContent ?? '') : '';

            $articleThumbnail = '';
            $thumbnailNode = $element->querySelector('img');
            if ($thumbnailNode !== null) {
                $src = $thumbnailNode->getAttribute('src');
                if ($src !== null && $src !== '' && str_starts_with($src, 'data:image/svg+xml') === false) {
                    $articleThumbnail = $src;
                }
            }

            $descNode = $element->querySelector('div.home-desc');
            $articleContentFallback = ($descNode !== null) ? trim($descNode->textContent ?? '') : '';

            $linkNode = $element->querySelector('a.story-link');
            if ($linkNode === null) {
                continue;
            }

            $href = $linkNode->getAttribute('href');
            if ($href === null || $href === '') {
                continue;
            }

            $articleUrl = urljoin(self::URI, $href);
            if ($articleUrl === null || $articleUrl === '') {
                continue;
            }

            $articleContent = $this->fetchArticleContent($articleUrl, $articleAuthor);

            if ($articleContent === '') {
                $articleContent = $articleContentFallback;
            }

            $content = '';
            if ($articleThumbnail !== '') {
                $fullThumbnailUrl = urljoin($articleUrl, $articleThumbnail);
                if ($fullThumbnailUrl !== null && $fullThumbnailUrl !== '') {
                    $content .= sprintf(
                        '<p style="%s"><img src="%s" style="%s" alt="%s" /></p>',
                        self::WRAPPER_STYLE,
                        e($fullThumbnailUrl),
                        self::IMG_STYLE,
                        e($articleTitle)
                    );
                }
            }
            $content .= $articleContent;

            $item = [
                'uri' => $articleUrl,
                'title' => $articleTitle,
                'timestamp' => $articleTimestamp,
                'content' => trim($content),
                'uid' => $articleUrl,
            ];

            if (is_string($articleAuthor) === true && $articleAuthor !== '') {
                $item['author'] = $articleAuthor;
            }

            $this->items[] = $item;
            $count++;
        }

        if ($this->items === []) {
            throwServerException('No articles found. The site layout may have changed.');
        }
    }

    /**
     * @return array{0: string|null, 1: int}
     */
    private function extractAuthorAndDate(\Dom\Element $element): array
    {
        $author = null;
        $timestamp = time();

        // Ищем контейнер .postmeta. Если его нет, ищем прямо в элементе.
        $postmeta = $element->querySelector('.postmeta');
        $searchRoot = $postmeta !== null ? $postmeta : $element;

        // Находим все span.author внутри этого блока
        $spans = $searchRoot->querySelectorAll('span.author');

        if ($spans->length >= 2) {
            // Первый span - это автор, второй - дата
            $author = trim($spans->item(0)->textContent ?? '');
            $dateStr = trim($spans->item(1)->textContent ?? '');

            if ($dateStr !== '') {
                $ts = strtotime($dateStr);
                if ($ts !== false) {
                    $timestamp = $ts;
                }
            }
        } elseif ($spans->length === 1) {
            // Если span только один, проверяем, не дата ли это. Если нет - это автор.
            $text = trim($spans->item(0)->textContent ?? '');
            if ($text !== '' && preg_match('/^\d{4}-\d{2}-\d{2}|^\w{3}\s+\d{1,2},\s+\d{4}$/', $text) === false) {
                $author = $text;
            }
        }

        return [$author, $timestamp];
    }

    private function fetchArticleContent(string $url, ?string &$author): string
    {
        try {
            $articleHtml = getContents($url);
        } catch (\Exception) {
            return '';
        }

        if (is_string($articleHtml) === false || $articleHtml === '') {
            return '';
        }

        try {
            libxml_use_internal_errors(true);
            $articleDom = \Dom\HTMLDocument::createFromString($articleHtml);
            libxml_use_internal_errors(false);
        } catch (\Throwable) {
            libxml_use_internal_errors(false);
            return '';
        }

        // Если автор не был найден на главной, пытаемся найти его на странице статьи
        if ($author === null || $author === '') {
            $authorSpans = $articleDom->querySelectorAll('span.author');
            $spanCount = count($authorSpans);
            if ($spanCount > 0) {
                $lastAuthor = $authorSpans->item($spanCount - 1);
                if ($lastAuthor !== null) {
                    $authorText = trim($lastAuthor->textContent ?? '');
                    if ($authorText !== '' && preg_match('/^\d{4}-\d{2}-\d{2}|^\w{3}\s+\d{1,2},\s+\d{4}$/', $authorText) === false) {
                        $author = $authorText;
                    }
                }
            }
        }

        $articleBody = $articleDom->querySelector('div.articlebody');
        if ($articleBody === null) {
            return '';
        }

        $this->convertLazyLoading($articleBody);
        $this->resolveRelativeLinks($articleBody, $url);
        $this->removeSelectors($articleBody, self::JUNK_SELECTORS);
        $this->removeSvgPlaceholders($articleBody);
        $this->fixImages($articleBody);
        $this->styleLists($articleBody);

        $headerImg = $articleBody->querySelector('img');
        if ($headerImg !== null) {
            $parentImg = $headerImg->parentElement;
            if ($parentImg !== null) {
                $parentImg->removeAttribute('style');
            }
        }

        $html = break_annoying_html_tags($articleBody->innerHTML);

        return $html;
    }

    private function removeSvgPlaceholders(\Dom\Element $node): void
    {
        foreach (iterator_to_array($node->querySelectorAll('img')) as $img) {
            $src = $img->getAttribute('src');
            if (is_string($src) === true && str_starts_with($src, 'data:image/svg+xml') === true) {
                if ($img->parentNode !== null) {
                    $img->parentNode->removeChild($img);
                }
            }
        }
    }

    private function fixImages(\Dom\Element $node): void
    {
        foreach (iterator_to_array($node->querySelectorAll('img')) as $img) {
            $img->removeAttribute('width');
            $img->removeAttribute('height');
            $img->removeAttribute('align');
            $img->removeAttribute('border');

            $img->setAttribute('style', self::IMG_STYLE);

            $current = $img->parentElement;
            while ($current !== null) {
                $tagName = strtolower($current->tagName);

                if ($tagName === 'div' || $tagName === 'p' || $tagName === 'article' || $tagName === 'section') {
                    break;
                }

                if ($tagName === 'a' || $tagName === 'span' || $tagName === 'figure') {
                    $current->setAttribute('style', self::WRAPPER_STYLE);
                }

                $current = $current->parentElement;
            }

            $parent = $img->parentElement;
            if ($parent !== null) {
                $parentTag = strtolower($parent->tagName);
                if ($parentTag !== 'p' && $parentTag !== 'div' && $parentTag !== 'figure') {
                    $wrapper = $node->ownerDocument?->createElement('div');
                    if ($wrapper !== null) {
                        $wrapper->setAttribute('style', self::WRAPPER_STYLE);
                        $parent->insertBefore($wrapper, $img);
                        $wrapper->appendChild($img);
                    }
                }
            }
        }
    }

    private function styleLists(\Dom\Element $node): void
    {
        foreach (iterator_to_array($node->querySelectorAll('ul')) as $ul) {
            $ul->setAttribute('style', self::UL_STYLE);
        }

        foreach (iterator_to_array($node->querySelectorAll('ol')) as $ol) {
            $ol->setAttribute('style', self::UL_STYLE);
        }

        foreach (iterator_to_array($node->querySelectorAll('li')) as $li) {
            $li->setAttribute('style', self::LI_STYLE);
        }
    }

    /**
     * @param list<string> $selectors
     */
    private function removeSelectors(\Dom\Element $node, array $selectors): void
    {
        $combinedSelector = implode(',', $selectors);
        foreach (iterator_to_array($node->querySelectorAll($combinedSelector)) as $el) {
            if ($el->parentNode !== null) {
                $el->parentNode->removeChild($el);
            }
        }
    }

    private function convertLazyLoading(\Dom\Element $node): void
    {
        $lazyAttrs = ['data-src', 'data-lazy-src', 'data-original', 'data-url', 'data-srcset', 'data-cfsrc'];

        foreach (iterator_to_array($node->querySelectorAll('img')) as $img) {
            foreach ($lazyAttrs as $lazyAttr) {
                $value = $img->getAttribute($lazyAttr);
                if (is_string($value) === true && $value !== '') {
                    $img->setAttribute('src', $value);
                    $img->removeAttribute($lazyAttr);
                    break;
                }
            }
        }
    }

    private function resolveRelativeLinks(\Dom\Element $node, string $baseUrl): void
    {
        $selectors = ['a[href]', 'img[src]', 'link[href]', 'script[src]', 'source[src]', 'video[src]'];

        foreach ($selectors as $selector) {
            foreach (iterator_to_array($node->querySelectorAll($selector)) as $el) {
                $attrName = str_contains($selector, 'href') === true ? 'href' : 'src';
                $attr = $el->getAttribute($attrName);

                if (is_string($attr) === true && $attr !== '') {
                    $resolved = urljoin($baseUrl, $attr);
                    if ($resolved !== null && $resolved !== '') {
                        $el->setAttribute($attrName, $resolved);
                    }
                }
            }
        }
    }
}
