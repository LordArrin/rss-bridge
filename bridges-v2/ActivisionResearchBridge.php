<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class ActivisionResearchBridge extends BridgeAbstract
{
    public const NAME = 'Activision Research Blog';
    public const URI = 'https://research.activision.com';
    public const DESCRIPTION = 'Posts from the Activision Research blog';
    public const MAINTAINER = 'no maintainer';

    public const CACHE_TIMEOUT = 86400;

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
        $limit = (int)$this->getInput('limit');
        if ($limit <= 0) {
            $limit = 10;
        }

        $html = getContents(self::URI);
        if ($html === '') {
            throwServerException(sprintf('Failed to fetch %s', self::URI));
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $container = $dom->querySelector('div[id="home-blog-feed"]');
        if ($container === null) {
            throwServerException(sprintf('Unable to find css selector on `%s`', self::URI));
        }

        $this->resolveRelativeUrls($container);

        $articles = $container->querySelectorAll('div.blog-entry');
        $count = 0;

        foreach ($articles as $article) {
            if ($count >= $limit) {
                break;
            }

            $link = $article->querySelector('a');
            if ($link === null) {
                continue;
            }

            $href = $link->getAttribute('href') ?? '';
            if ($href === '') {
                continue;
            }

            $blogImg = '';
            $blogImgDiv = $article->querySelector('div.blog-img');
            if ($blogImgDiv !== null) {
                $style = $blogImgDiv->getAttribute('style') ?? '';
                if (preg_match('/url\(([^)]+)\)/i', $style, $matches) === 1) {
                    $blogImg = trim($matches[1], '"\'');
                }
            }

            $titleDiv = $article->querySelector('div.title');
            $title = $titleDiv !== null ? html_entity_decode(trim($titleDiv->textContent ?? ''), ENT_QUOTES, 'UTF-8') : '';
            if ($title === '') {
                continue;
            }

            $authorDiv = $article->querySelector('div.author');
            $author = $authorDiv !== null ? html_entity_decode(trim($authorDiv->textContent ?? ''), ENT_QUOTES, 'UTF-8') : '';

            $dateDiv = $article->querySelector('div.pubdate');
            $date = $dateDiv !== null ? trim($dateDiv->textContent ?? '') : '';
            $timestamp = null;
            if ($date !== '') {
                $parsed = strtotime($date);
                if ($parsed !== false) {
                    $timestamp = $parsed;
                }
            }

            $entryHtml = getContents($href);
            if ($entryHtml === '') {
                continue;
            }

            libxml_use_internal_errors(true);
            $entryDom = \Dom\HTMLDocument::createFromString($entryHtml);
            libxml_use_internal_errors(false);

            $this->resolveRelativeUrls($entryDom->documentElement);

            $contentElement = $entryDom->querySelector('div.blog-body');
            if ($contentElement === null) {
                continue;
            }

            $tagsToRemove = ['script', 'iframe', 'input', 'form'];
            foreach ($tagsToRemove as $tag) {
                $elements = $contentElement->querySelectorAll($tag);
                foreach ($elements as $el) {
                    $el->remove();
                }
            }

            $boldElements = $contentElement->querySelectorAll('.cmp-text > p > b');
            foreach ($boldElements as $bold) {
                $bold->remove();
            }

            $content = '';
            if ($blogImg !== '') {
                $imgUrl = self::URI . $blogImg;
                $content .= '<img src="' . htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8') . '" alt="">';
            }

            $content .= $entryDom->saveHTML($contentElement);

            $this->items[] = [
                'title' => $title,
                'author' => $author,
                'uri' => $href,
                'content' => $content,
                'timestamp' => $timestamp,
            ];

            $count++;
        }
    }

    private function resolveRelativeUrls(?\Dom\Element $container): void
    {
        if ($container === null) {
            return;
        }

        $base = rtrim(self::URI, '/');
        $elements = $container->querySelectorAll('[src], [href]');
        foreach ($elements as $el) {
            foreach (['src', 'href'] as $attr) {
                $value = $el->getAttribute($attr);
                if ($value === null) {
                    continue;
                }
                if (str_starts_with($value, '/') === true && str_starts_with($value, '//') === false) {
                    $el->setAttribute($attr, $base . $value);
                }
            }
        }
    }
}
