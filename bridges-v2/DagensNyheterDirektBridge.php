<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class DagensNyheterDirektBridge extends BridgeAbstract
{
    public const NAME = 'Dagens Nyheter Direkt';
    public const URI = 'https://www.dn.se/direkt/';
    public const DESCRIPTION = 'Latest news summarised by Dagens Nyheter';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [[
        'limit' => [
            'name' => 'Limit',
            'type' => 'number',
            'defaultValue' => 20,
            'title' => 'Number of articles to fetch'
        ]
    ]];

    public function getIcon(): string
    {
        return 'https://cdn.dn-static.se/images/favicon__c2dd3284b46ffdf4d520536e526065fa8.svg';
    }

    public function collectData(): void
    {
        $limit = (int)$this->getInput('limit');
        if ($limit <= 0) {
            $limit = 20;
        }

        $baseUrl = parse_url(self::URI, PHP_URL_SCHEME) . '://' . parse_url(self::URI, PHP_URL_HOST);
        $newsUrl = $baseUrl . '/ajax/direkt/';

        $html = getContents($newsUrl);
        if ($html === '') {
            throwServerException('Could not fetch: ' . $newsUrl);
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $this->resolveRelativeUrls($dom->documentElement, $baseUrl);

        $articles = $dom->querySelectorAll('article');

        foreach ($articles as $element) {
            if (count($this->items) >= $limit) {
                break;
            }

            $button = $element->querySelector('button');
            if ($button === null) {
                continue;
            }

            $link = $button->getAttribute('data-link') ?? '';
            if ($link === '') {
                continue;
            }

            $datetime = $element->getAttribute('data-publication-time') ?? '';
            $url = $baseUrl . $link;

            $titleEl = $element->querySelector('h2');
            $title = $titleEl !== null ? trim($titleEl->textContent ?? '') : '';
            if ($title === '') {
                continue;
            }

            $authorEl = $element->querySelector('div.ds-byline__titles');
            $author = $authorEl !== null ? trim($authorEl->textContent ?? '') : '';

            $articleContent = $element->querySelector('div.direkt-post__content');
            $articleHtml = '';

            $figure = $element->querySelector('figure');
            if ($figure !== null) {
                $img = $figure->querySelector('img');
                $figcaption = $figure->querySelector('figcaption');

                if ($img !== null) {
                    $articleHtml .= $dom->saveHTML($img);
                }
                if ($figcaption !== null) {
                    $articleHtml .= '<p><i>' . trim($figcaption->textContent ?? '') . '</i></p>';
                }
            }

            if ($articleContent !== null) {
                $paragraphs = $articleContent->querySelectorAll('p');
                foreach ($paragraphs as $p) {
                    $articleHtml .= $dom->saveHTML($p);
                }
            }

            $timestamp = null;
            if ($datetime !== '') {
                $parsed = strtotime($datetime);
                if ($parsed !== false) {
                    $timestamp = $parsed;
                }
            }

            $this->items[] = [
                'uri' => $url,
                'title' => $title,
                'author' => $author,
                'timestamp' => $timestamp,
                'content' => trim($articleHtml),
            ];
        }
    }

    private function resolveRelativeUrls(?\Dom\Element $container, string $baseUrl): void
    {
        if ($container === null) {
            return;
        }

        $base = rtrim($baseUrl, '/');
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
