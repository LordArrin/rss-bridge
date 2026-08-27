<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

use function urljoin;

final class DjMagDotComBridge extends BridgeAbstract
{
    public const NAME = 'DJMag News';
    public const URI = 'https://www.djmag.com/';
    public const DESCRIPTION = 'News from DJMag.com';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [[
        'limit' => [
            'name' => 'Limit',
            'type' => 'number',
            'title' => 'The number of news to get (max: 20)',
            'defaultValue' => 10,
        ],
    ]];

    private const MAX_LIMIT = 20;
    private const DEFAULT_LIMIT = 10;
    private const CATEGORY_NEWS = 'NEWS';

    private const ALLOWED_TAGS = [
        'p' => [],
        'h2' => [],
        'h3' => [],
        'h4' => [],
        'strong' => [],
        'b' => [],
        'em' => [],
        'i' => [],
        'u' => [],
        'a' => ['href', 'target'],
        'br' => [],
        'blockquote' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'img' => ['src', 'alt'],
        'figure' => [],
        'figcaption' => [],
    ];

    private const CSS = [
        'image' => 'display: block; max-width: 500px; height: auto; margin: 10px 0;',
    ];

    public function getURI()
    {
        return self::URI . 'news';
    }

    public function collectData()
    {
        $limit = $this->getLimit();
        $url = $this->getURI();

        $html = getContents($url);

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $featuredItem = $dom->querySelector('div.attachment-before div.view-content');
        if ($featuredItem !== null) {
            $item = $this->extractNewsItem($featuredItem, true);
            if ($item !== null) {
                $this->items[] = $item;
                $limit--;
            }
        }

        if ($limit <= 0) {
            return;
        }

        $newsItems = iterator_to_array($dom->querySelectorAll('div#views-bootstrap-listing-news-page > div.row article'));

        foreach ($newsItems as $newsItem) {
            if ($limit <= 0) {
                break;
            }

            if ($newsItem instanceof \Dom\Element === false) {
                continue;
            }

            $item = $this->extractNewsItem($newsItem, false);
            if ($item !== null) {
                $this->items[] = $item;
                $limit--;
            }
        }
    }

    private function getLimit(): int
    {
        $limitInput = $this->getInput('limit');

        if (is_numeric($limitInput) === false) {
            return self::DEFAULT_LIMIT;
        }

        $limit = (int)$limitInput;

        if ($limit < 1) {
            return self::DEFAULT_LIMIT;
        }

        if ($limit > self::MAX_LIMIT) {
            return self::MAX_LIMIT;
        }

        return $limit;
    }

    private function extractNewsItem(\Dom\Element $element, bool $isFeatured): ?array
    {
        $titleLink = $element->querySelector('h1 > a');
        if ($titleLink === null) {
            return null;
        }

        $title = trim($titleLink->textContent ?? '');
        if ($title === '') {
            return null;
        }

        $href = $titleLink->getAttribute('href') ?? '';
        if ($href === '') {
            return null;
        }

        $uri = urljoin(self::URI, $href);

        $sourceTag = $element->querySelector('source');
        $image = null;
        if ($sourceTag !== null) {
            $srcset = $sourceTag->getAttribute('srcset') ?? '';
            if ($srcset !== '') {
                $image = $this->extractFirstImageFromSrcset($srcset);
            }
        }

        $details = $this->fetchArticleDetails($uri, $image, $title);
        if ($details === null) {
            return null;
        }

        $item = [];
        $item['title'] = $title;
        $item['uri'] = $uri;
        $item['uid'] = sha1($uri);
        $item['content'] = $details['content'];
        $item['timestamp'] = $details['timestamp'];
        $item['author'] = $details['author'];
        $item['categories'] = [self::CATEGORY_NEWS];

        return $item;
    }

    private function extractFirstImageFromSrcset(string $srcset): ?string
    {
        $parts = explode(',', $srcset);
        if (count($parts) === 0) {
            return null;
        }

        $firstPart = trim($parts[0]);
        $urlParts = explode(' ', $firstPart);

        if (count($urlParts) === 0) {
            return null;
        }

        $url = trim($urlParts[0]);
        if ($url === '') {
            return null;
        }

        return urljoin(self::URI, $url);
    }

    private function fetchArticleDetails(string $uri, ?string $image, string $title): ?array
    {
        try {
            $html = getContents($uri);
        } catch (\Exception $e) {
            return null;
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $standfirst = $dom->querySelector('article div.article--standfirst p');
        $standfirstText = '';
        if ($standfirst !== null) {
            $standfirstText = trim($standfirst->textContent ?? '');
        }

        $contentColumn = $dom->querySelector('article div.content-column-wrap-oh > div > div.field--name-field-content > div');
        $articleHtml = '';
        if ($contentColumn !== null) {
            $articleHtml = $this->extractArticleContent($contentColumn);
        }

        $content = $this->buildArticleContent($standfirstText, $image, $title, $articleHtml);

        $authorInfoBlocks = iterator_to_array($dom->querySelectorAll('article div.pane-author-info'));
        if (count($authorInfoBlocks) < 2) {
            return [
                'timestamp' => null,
                'content' => $content,
                'author' => null,
            ];
        }

        $metaFields = $authorInfoBlocks[1];
        if ($metaFields instanceof \Dom\Element === false) {
            return [
                'timestamp' => null,
                'content' => $content,
                'author' => null,
            ];
        }

        $metaDivs = iterator_to_array($metaFields->querySelectorAll('div'));

        $author = null;
        if (isset($metaDivs[0]) === true && $metaDivs[0] instanceof \Dom\Element === true) {
            $authorText = trim($metaDivs[0]->textContent ?? '');
            if ($authorText !== '') {
                $author = $authorText;
            }
        }

        $timestamp = null;
        if (isset($metaDivs[1]) === true && $metaDivs[1] instanceof \Dom\Element === true) {
            $rawTimestamp = trim($metaDivs[1]->textContent ?? '');
            if ($rawTimestamp !== '') {
                $timestamp = $this->parseDateString($rawTimestamp);
            }
        }

        return [
            'timestamp' => $timestamp,
            'content' => $content,
            'author' => $author,
        ];
    }

    private function extractArticleContent(\Dom\Element $container): string
    {
        $html = '';

        foreach ($container->childNodes as $node) {
            if ($node instanceof \Dom\Element === false) {
                continue;
            }

            $tagName = strtolower($node->tagName ?? '');

            if (isset(self::ALLOWED_TAGS[$tagName]) === false) {
                $innerContent = $this->extractNestedContent($node);
                if ($innerContent !== '') {
                    $html .= $innerContent;
                }
                continue;
            }

            $html .= $this->serializeElement($node, $tagName);
        }

        return $html;
    }

    private function extractNestedContent(\Dom\Element $element): string
    {
        $html = '';

        foreach ($element->childNodes as $node) {
            if ($node instanceof \Dom\Element === true) {
                $tagName = strtolower($node->tagName ?? '');

                if (isset(self::ALLOWED_TAGS[$tagName]) === true) {
                    $html .= $this->serializeElement($node, $tagName);
                } else {
                    $html .= $this->extractNestedContent($node);
                }
            } elseif ($node instanceof \Dom\Text === true) {
                $text = trim($node->textContent ?? '');
                if ($text !== '') {
                    $html .= '<p>' . e($text) . '</p>';
                }
            }
        }

        return $html;
    }

    private function serializeElement(\Dom\Element $element, string $tagName): string
    {
        $allowedAttrs = self::ALLOWED_TAGS[$tagName] ?? [];

        $attrs = '';
        foreach ($allowedAttrs as $attrName) {
            $attrValue = $element->getAttribute($attrName);
            if ($attrValue !== null && $attrValue !== '') {
                if ($attrName === 'href' || $attrName === 'src') {
                    $attrValue = urljoin(self::URI, $attrValue);
                }
                $attrs .= ' ' . $attrName . '="' . e($attrValue) . '"';
            }
        }

        if ($tagName === 'img') {
            if (strpos($attrs, 'style=') === false) {
                $attrs .= ' style="' . self::CSS['image'] . '"';
            }
            return '<img' . $attrs . ' />';
        }

        if ($tagName === 'br') {
            return '<br />';
        }

        $innerHtml = $this->serializeInnerContent($element);

        return '<' . $tagName . $attrs . '>' . $innerHtml . '</' . $tagName . '>';
    }

    private function serializeInnerContent(\Dom\Element $element): string
    {
        $html = '';

        foreach ($element->childNodes as $node) {
            if ($node instanceof \Dom\Element === true) {
                $tagName = strtolower($node->tagName ?? '');

                if (isset(self::ALLOWED_TAGS[$tagName]) === true) {
                    $html .= $this->serializeElement($node, $tagName);
                } else {
                    $html .= $this->extractNestedContent($node);
                }
            } elseif ($node instanceof \Dom\Text === true) {
                $html .= e($node->textContent ?? '');
            }
        }

        return $html;
    }

    private function buildArticleContent(string $standfirst, ?string $image, string $title, string $articleHtml): string
    {
        $content = '';

        if ($standfirst !== '') {
            $content .= '<h2>' . e($standfirst) . '</h2>';
        }

        if ($image !== null && $image !== '') {
            $content .= '<img src="' . e($image) . '" alt="' . e($title) . '" style="' . self::CSS['image'] . '" />';
        }

        if ($articleHtml !== '') {
            $content .= $articleHtml;
        }

        return $content;
    }

    private function parseDateString(string $dateString): ?int
    {
        $dateString = trim($dateString);

        $dt = \DateTime::createFromFormat('j F Y, H:i', $dateString);
        if ($dt instanceof \DateTime) {
            return $dt->getTimestamp();
        }

        $dt = \DateTime::createFromFormat('d F Y, H:i', $dateString);
        if ($dt instanceof \DateTime) {
            return $dt->getTimestamp();
        }

        $ts = strtotime($dateString);
        if ($ts !== false) {
            return $ts;
        }

        return null;
    }
}
