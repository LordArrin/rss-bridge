<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class TheHackerNewsBridge extends BridgeAbstract
{
    public const NAME = 'The Hacker News';
    public const URI = 'https://thehackernews.com/';
    public const DESCRIPTION = 'Cyber Security, Hacking, Technology News';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    private const CSS = [
        'img' => 'display: block; float: none; clear: both; max-width: 800px; width: auto; height: auto; margin: 16px 0; padding: 0;',
        'ul' => 'list-style-type: disc; margin: 12px 0; padding-left: 24px;',
        'li' => 'margin: 6px 0;',
    ];

    private const JUNK_SELECTORS = [
        '.lazyload',
        'center.cf',
        'script',
        'style',
        'noscript',
        'iframe',
    ];

    protected const LIMIT = 5;

    public function collectData(): void
    {
        $html = getContents($this->getURI());

        if (is_string($html) === false || $html === '') {
            \throwServerException('Empty response from The Hacker News homepage');
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $elements = $dom->querySelectorAll('div.body-post');
        $limit = 0;

        foreach ($elements as $element) {
            if ($limit >= self::LIMIT) {
                break;
            }

            if ($element instanceof \Dom\Element === false) {
                continue;
            }

            $articleAuthor = null;

            $titleNode = $element->querySelector('h2.home-title');
            $articleTitle = ($titleNode !== null) ? trim((string) $titleNode->textContent) : '';

            $articleTimestamp = time();
            $calendar = $element->querySelector('i.icon-calendar');
            if ($calendar !== null) {
                $parent = $calendar->parentNode;
                if ($parent instanceof \Dom\Element === true) {
                    $parentHtml = (string) $parent->innerHTML;
                    if (preg_match('/<\/i>(.*?)<\/span>/is', $parentHtml, $matches) === 1) {
                        $dateText = trim(strip_tags((string) $matches[1]));
                        if ($dateText !== '') {
                            $ts = strtotime($dateText);
                            if ($ts !== false) {
                                $articleTimestamp = $ts;
                            }
                        }
                    }
                }
            }

            $articleThumbnail = '';
            $thumbnailNode = $element->querySelector('img');
            if ($thumbnailNode !== null) {
                $articleThumbnail = (string) ($thumbnailNode->getAttribute('src') ?? '');
            }

            $descNode = $element->querySelector('div.home-desc');
            $articleContent = ($descNode !== null) ? trim((string) $descNode->textContent) : '';

            $linkNode = $element->querySelector('a.story-link');
            if ($linkNode === null) {
                continue;
            }

            $articleUrl = (string) ($linkNode->getAttribute('href') ?? '');
            if ($articleUrl === '') {
                continue;
            }
            $articleUrl = $this->resolveUrl(self::URI, $articleUrl);

            $articleHtml = getContents($articleUrl);
            if (is_string($articleHtml) === true && $articleHtml !== '') {
                libxml_use_internal_errors(true);
                $articleDom = \Dom\HTMLDocument::createFromString($articleHtml);
                libxml_use_internal_errors(false);

                $articleBody = $articleDom->querySelector('div.articlebody');
                if ($articleBody !== null) {
                    $this->convertLazyLoading($articleBody);
                    $this->resolveRelativeLinks($articleBody, $articleUrl);
                    $this->removeSelectors($articleBody, self::JUNK_SELECTORS);
                    $this->limitImageSize($articleBody);
                    $this->styleLists($articleBody);

                    $headerImg = $articleBody->querySelector('img');
                    if ($headerImg !== null) {
                        $parentImg = $headerImg->parentNode;
                        if ($parentImg instanceof \Dom\Element === true) {
                            $parentImg->removeAttribute('style');
                        }
                    }

                    $articleContent = (string) $articleBody->innerHTML;
                }

                $authorSpans = $articleDom->querySelectorAll('span.author');
                if ($authorSpans->length > 0) {
                    $lastAuthor = $authorSpans->item($authorSpans->length - 1);
                    if ($lastAuthor !== null) {
                        $articleAuthor = trim((string) $lastAuthor->textContent);
                    }
                }
            }

            $content = '';
            if ($articleThumbnail !== '') {
                $fullThumbnailUrl = $this->resolveUrl($articleUrl, $articleThumbnail);
                $content .= '<p><img src="' . htmlspecialchars($fullThumbnailUrl) . '" style="' . self::CSS['img'] . '" alt="" /></p>';
            }
            $content .= $articleContent;

            $item = [];
            $item['uri'] = $articleUrl;
            $item['title'] = $articleTitle;
            if (is_string($articleAuthor) === true && $articleAuthor !== '') {
                $item['author'] = $articleAuthor;
            }
            $item['timestamp'] = $articleTimestamp;
            $item['content'] = trim($content);
            $item['uid'] = $articleUrl;

            $this->items[] = $item;
            $limit++;
        }
    }

    private function styleLists(\Dom\Node $node): void
    {
        if ($node instanceof \Dom\Element === false && $node instanceof \Dom\HTMLDocument === false) {
            return;
        }

        foreach ($node->querySelectorAll('ul') as $ul) {
            if ($ul instanceof \Dom\Element === true) {
                $ul->setAttribute('style', self::CSS['ul']);
            }
        }

        foreach ($node->querySelectorAll('li') as $li) {
            if ($li instanceof \Dom\Element === true) {
                $li->setAttribute('style', self::CSS['li']);
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
                $parent = $el->parentNode;
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

                $attrName = 'src';
                if (str_contains($selector, 'href') === true) {
                    $attrName = 'href';
                }
                $attr = (string) ($el->getAttribute($attrName) ?? '');
                if ($attr !== '') {
                    $el->setAttribute($attrName, $this->resolveUrl($baseUrl, $attr));
                }
            }
        }
    }

    private function resolveUrl(string $base, string $relative): string
    {
        if (str_starts_with($relative, 'http://') === true || str_starts_with($relative, 'https://') === true) {
            return $relative;
        }

        if (str_starts_with($relative, '//') === true) {
            return 'https:' . $relative;
        }

        if (str_starts_with($relative, '/') === true) {
            $parsed = parse_url($base);
            $scheme = (string) ($parsed['scheme'] ?? 'https');
            $host = (string) ($parsed['host'] ?? '');
            return $scheme . '://' . $host . $relative;
        }

        return rtrim($base, '/') . '/' . ltrim($relative, '/');
    }

    private function limitImageSize(\Dom\Node $node): void
    {
        if ($node instanceof \Dom\Element === false && $node instanceof \Dom\HTMLDocument === false) {
            return;
        }

        foreach ($node->querySelectorAll('img') as $img) {
            if ($img instanceof \Dom\Element === true) {
                $img->removeAttribute('width');
                $img->removeAttribute('height');
                $img->removeAttribute('align');
                $img->setAttribute('style', self::CSS['img']);
            }
        }
    }
}
