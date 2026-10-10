<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;
use function RSSBridge\Exceptions\throwServerException;

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
    ];

    protected const LIMIT = 10;

    public function collectData(): void
    {
        $html = getContents(self::URI);

        if (is_string($html) === false || $html === '') {
            throwServerException('Empty response from The Hacker News homepage');
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $elements = $dom->querySelectorAll('div.body-post');
        $count = 0;

        foreach ($elements as $element) {
            if ($count >= self::LIMIT) {
                break;
            }

            if ($element instanceof \Dom\Element === false) {
                continue;
            }

            $articleAuthor = null;

            $titleNode = $element->querySelector('h2.home-title');
            $articleTitle = ($titleNode !== null) ? trim((string) $titleNode->textContent) : '';

            $articleTimestamp = $this->extractTimestamp($element);

            $articleThumbnail = '';
            $thumbnailNode = $element->querySelector('img');
            if ($thumbnailNode !== null) {
                $src = $thumbnailNode->getAttribute('src');
                if ($src !== null && $src !== '' && str_starts_with($src, 'data:image/svg+xml') === false) {
                    $articleThumbnail = (string) $src;
                }
            }

            $descNode = $element->querySelector('div.home-desc');
            $articleContentFallback = ($descNode !== null) ? trim((string) $descNode->textContent) : '';

            $linkNode = $element->querySelector('a.story-link');
            if ($linkNode === null) {
                continue;
            }

            $href = $linkNode->getAttribute('href');
            if ($href === null || $href === '') {
                continue;
            }

            $articleUrl = urljoin(self::URI, (string) $href);

            $articleContent = $this->fetchArticleContent($articleUrl, $articleAuthor);

            if ($articleContent === '') {
                $articleContent = $articleContentFallback;
            }

            $content = '';
            if ($articleThumbnail !== '') {
                $fullThumbnailUrl = urljoin($articleUrl, $articleThumbnail);
                $content .= sprintf(
                    '<p style="%s"><img src="%s" style="%s" alt="%s" /></p>',
                    self::WRAPPER_STYLE,
                    e($fullThumbnailUrl),
                    self::IMG_STYLE,
                    e($articleTitle)
                );
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

    private function extractTimestamp(\Dom\Element $element): int
    {
        $calendar = $element->querySelector('i.icon-calendar');
        if ($calendar === null) {
            return time();
        }

        $parent = $calendar->parentElement;
        if ($parent instanceof \Dom\Element === false) {
            return time();
        }

        $parentHtml = (string) $parent->innerHTML;
        if (preg_match('/<\/i>(.*?)<\/span>/is', $parentHtml, $matches) === 1) {
            $dateText = trim(strip_tags((string) $matches[1]));
            if ($dateText !== '') {
                $ts = strtotime($dateText);
                if ($ts !== false) {
                    return $ts;
                }
            }
        }

        return time();
    }

    private function fetchArticleContent(string $url, ?string &$author): string
    {
        $articleDom = getSimpleHTMLDOMCached($url, 86400);
        if ($articleDom === null) {
            return '';
        }

        $authorSpans = $articleDom->querySelectorAll('span.author');
        if ($authorSpans->length > 0) {
            $lastAuthor = $authorSpans->item($authorSpans->length - 1);
            if ($lastAuthor !== null) {
                $author = trim((string) $lastAuthor->textContent);
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
            if ($parentImg instanceof \Dom\Element === true) {
                $parentImg->removeAttribute('style');
            }
        }

        $html = break_annoying_html_tags((string) $articleBody->innerHTML);

        return $html;
    }

    private function removeSvgPlaceholders(\Dom\Node $node): void
    {
        if ($node instanceof \Dom\Element === false && $node instanceof \Dom\HTMLDocument === false) {
            return;
        }

        $images = $node->querySelectorAll('img');

        foreach ($images as $img) {
            if ($img instanceof \Dom\Element === false) {
                continue;
            }

            $src = $img->getAttribute('src');
            if (is_string($src) === true && str_starts_with($src, 'data:image/svg+xml') === true) {
                $parent = $img->parentElement;
                if ($parent !== null) {
                    $parent->removeChild($img);
                }
            }
        }
    }

    private function fixImages(\Dom\Node $node): void
    {
        if ($node instanceof \Dom\Element === false && $node instanceof \Dom\HTMLDocument === false) {
            return;
        }

        $images = $node->querySelectorAll('img');

        foreach ($images as $img) {
            if ($img instanceof \Dom\Element === false) {
                continue;
            }

            $img->removeAttribute('width');
            $img->removeAttribute('height');
            $img->removeAttribute('align');
            $img->removeAttribute('border');

            $img->setAttribute('style', self::IMG_STYLE);

            $current = $img->parentElement;
            while ($current !== null && $current instanceof \Dom\Element === true) {
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
            if ($parent instanceof \Dom\Element === true) {
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

    private function styleLists(\Dom\Node $node): void
    {
        if ($node instanceof \Dom\Element === false && $node instanceof \Dom\HTMLDocument === false) {
            return;
        }

        foreach ($node->querySelectorAll('ul') as $ul) {
            if ($ul instanceof \Dom\Element === true) {
                $ul->setAttribute('style', self::UL_STYLE);
            }
        }

        foreach ($node->querySelectorAll('ol') as $ol) {
            if ($ol instanceof \Dom\Element === true) {
                $ol->setAttribute('style', self::UL_STYLE);
            }
        }

        foreach ($node->querySelectorAll('li') as $li) {
            if ($li instanceof \Dom\Element === true) {
                $li->setAttribute('style', self::LI_STYLE);
            }
        }
    }

    private function removeSelectors(\Dom\Node $node, array $selectors): void
    {
        if ($node instanceof \Dom\Element === false && $node instanceof \Dom\HTMLDocument === false) {
            return;
        }

        $combinedSelector = implode(',', $selectors);
        $elements = $node->querySelectorAll($combinedSelector);

        foreach ($elements as $el) {
            if ($el instanceof \Dom\Element === true) {
                $parent = $el->parentElement;
                if ($parent !== null) {
                    $parent->removeChild($el);
                }
            }
        }
    }

    private function convertLazyLoading(\Dom\Node $node): void
    {
        if ($node instanceof \Dom\Element === false && $node instanceof \Dom\HTMLDocument === false) {
            return;
        }

        $lazyAttrs = ['data-src', 'data-lazy-src', 'data-original', 'data-url', 'data-srcset', 'data-cfsrc'];
        $images = $node->querySelectorAll('img');

        foreach ($images as $img) {
            if ($img instanceof \Dom\Element === false) {
                continue;
            }

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

    private function resolveRelativeLinks(\Dom\Node $node, string $baseUrl): void
    {
        if ($node instanceof \Dom\Element === false && $node instanceof \Dom\HTMLDocument === false) {
            return;
        }

        $selectors = ['a[href]', 'img[src]', 'link[href]', 'script[src]', 'source[src]', 'video[src]'];

        foreach ($selectors as $selector) {
            foreach ($node->querySelectorAll($selector) as $el) {
                if ($el instanceof \Dom\Element === false) {
                    continue;
                }

                $attrName = str_contains($selector, 'href') === true ? 'href' : 'src';
                $attr = $el->getAttribute($attrName);

                if (is_string($attr) === true && $attr !== '') {
                    $el->setAttribute($attrName, urljoin($baseUrl, $attr));
                }
            }
        }
    }
}
