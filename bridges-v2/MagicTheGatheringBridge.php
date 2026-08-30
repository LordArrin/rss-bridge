<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class MagicTheGatheringBridge extends BridgeAbstract
{
    public const NAME = 'Magic: The Gathering';
    public const URI = 'https://magic.wizards.com/en/news/';
    public const DESCRIPTION = 'Daily MTG - MTG News, Announcements, and Podcasts';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 86400;

    private const CSS = [
        'img' => 'display: block; float: none; clear: both; max-width: 800px; width: auto; height: auto; margin: 16px 0; padding: 0;',
    ];

    public const PARAMETERS = [
        [
            'category' => [
                'name' => 'Category',
                'type' => 'list',
                'title' => 'News categories',
                'values' => [
                    'All' => 'archive',
                    'Annoucements' => 'annoucements',
                    'Card Image Gallery' => 'card-image-gallery',
                    'Card Preview' => 'card-preview',
                    'Feature' => 'feature',
                    'Magic Story' => 'magic-story',
                    'Making Magic' => 'making-magic',
                    'MTG Arena' => 'mtg-arena',
                ]
            ]
        ]
    ];

    public function collectData(): void
    {
        $categoryInput = $this->getInput('category');
        if (is_string($categoryInput) === true && $categoryInput !== '') {
            $category = $categoryInput;
        } else {
            $category = 'archive';
        }

        $url = self::URI . $category;
        $html = getContents($url);

        if (is_string($html) === false || $html === '') {
            \throwServerException('Empty response from Magic: The Gathering page');
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $articles = $dom->querySelectorAll('article');

        foreach ($articles as $article) {
            if ($article instanceof \Dom\Element === false) {
                continue;
            }

            $item = $this->parseArticle($article);
            if ($item !== null) {
                $this->items[] = $item;
            }
        }
    }

    private function parseArticle(\Dom\Element $article): ?array
    {
        $h3 = $article->querySelector('h3');
        if ($h3 === null) {
            return null;
        }

        $title = trim((string) $h3->innerHTML);

        $links = $article->querySelectorAll('a');
        if ($links->length < 3) {
            return null;
        }

        $authorLink = $links->item(2);
        $articleLink = $links->item(1);

        if ($authorLink === null || $articleLink === null) {
            return null;
        }

        $author = trim((string) $authorLink->textContent);
        $articlePath = (string) ($articleLink->getAttribute('href') ?? '');

        if ($articlePath === '') {
            return null;
        }

        if (str_starts_with($articlePath, 'http://') === true || str_starts_with($articlePath, 'https://') === true) {
            $articleUrl = $articlePath;
        } else {
            $articleUrl = 'https://magic.wizards.com' . $articlePath;
        }

        $fullArticleHtml = getContents($articleUrl);
        if (is_string($fullArticleHtml) === false || $fullArticleHtml === '') {
            return null;
        }

        libxml_use_internal_errors(true);
        $fullArticleDom = \Dom\HTMLDocument::createFromString($fullArticleHtml);
        libxml_use_internal_errors(false);

        $articleBody = $fullArticleDom->querySelector('article');
        if ($articleBody === null) {
            return null;
        }

        $timeElement = $articleBody->querySelector('time');
        $timestamp = null;
        if ($timeElement !== null) {
            $timeText = trim((string) $timeElement->textContent);
            if ($timeText !== '') {
                $ts = strtotime($timeText);
                if ($ts !== false) {
                    $timestamp = $ts;
                }
            }
        }

        $contentDiv = $articleBody->querySelector('div.article-body');
        $content = '';

        if ($contentDiv !== null) {
            $this->resolveRelativeLinks($contentDiv, $articleUrl);
            $this->limitImageSize($contentDiv);
            $content = (string) $contentDiv->innerHTML;
        }

        $item = [
            'title' => $title,
            'author' => $author,
            'uri' => $articleUrl,
            'content' => $content,
            'uid' => md5($articleUrl),
        ];

        if ($timestamp !== null && $timestamp > 0) {
            $item['timestamp'] = $timestamp;
        }

        return $item;
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
