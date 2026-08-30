<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class HytaleBridge extends BridgeAbstract
{
    public const NAME = 'Hytale';
    public const URI = 'https://hytale.com/news';
    public const DESCRIPTION = 'All blog posts from Hytale\'s news blog';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    private const CSS = [
        'img' => 'display: block; float: none; clear: both; max-width: 800px; width: auto; height: auto; margin: 16px 0; padding: 0;',
    ];

    private const ARTICLES_CONTAINER_CLASS = 'space-y-0';
    private const DESCRIPTION_SELECTOR = 'span.line-clamp-4';
    private const AUTHOR_SELECTOR = 'span.text-right';

    public function collectData(): void
    {
        $html = getContents(self::URI);

        if (is_string($html) === false || $html === '') {
            \throwServerException('Empty response from Hytale news page');
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $articlesContainer = $dom->querySelector('div.' . self::ARTICLES_CONTAINER_CLASS);

        if ($articlesContainer === null) {
            return;
        }

        $articles = $articlesContainer->querySelectorAll('article');

        foreach ($articles as $article) {
            if ($article instanceof \Dom\Element === false) {
                continue;
            }

            $this->addBlogPost($article);
        }
    }

    private function addBlogPost(\Dom\Element $blogPost): void
    {
        $link = $blogPost->querySelector('h4 a');

        if ($link === null) {
            return;
        }

        $articlePath = (string) ($link->getAttribute('href') ?? '');

        if ($articlePath === '') {
            return;
        }

        $articleUrl = 'https://hytale.com' . $articlePath;

        $item = [];
        $item['uri'] = $articleUrl;
        $item['title'] = trim((string) $link->textContent);

        $content = '';

        $imgElement = $blogPost->querySelector('img');

        if ($imgElement !== null) {
            $imageUrl = (string) ($imgElement->getAttribute('src') ?? '');

            if ($imageUrl !== '') {
                $content .= '<img src="' . htmlspecialchars($imageUrl) . '" alt="Article thumbnail" style="' . self::CSS['img'] . '" />';
            }
        }

        $descriptionElement = $blogPost->querySelector(self::DESCRIPTION_SELECTOR);

        if ($descriptionElement !== null) {
            $descriptionText = trim((string) $descriptionElement->textContent);
            if ($descriptionText !== '') {
                if ($content !== '') {
                    $content .= '<br />';
                }
                $content .= '<p>' . htmlspecialchars($descriptionText) . '</p>';
            }
        }

        if ($content !== '') {
            $item['content'] = $content;
        }

        $footerSpans = $blogPost->querySelectorAll('span.flex.flex-row.gap-2 > span');

        if ($footerSpans->length >= 1) {
            $firstSpan = $footerSpans->item(0);
            if ($firstSpan !== null) {
                $dateText = trim((string) $firstSpan->textContent);
                if ($dateText !== '') {
                    $timestamp = strtotime($dateText);
                    if ($timestamp !== false) {
                        $item['timestamp'] = $timestamp;
                    }
                }
            }
        }

        $authorElement = $blogPost->querySelector(self::AUTHOR_SELECTOR);

        if ($authorElement !== null) {
            $authorText = trim((string) $authorElement->textContent);

            if (preg_match('/Posted by\s+(.+)/i', $authorText, $matches) === 1) {
                $item['author'] = trim((string) $matches[1]);
            }
        }

        $item['uid'] = md5($articlePath);

        $this->items[] = $item;
    }
}
