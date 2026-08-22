<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class BastaBridge extends BridgeAbstract
{
    public const NAME = 'Bastamag';
    public const URI = 'https://www.bastamag.net/';
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
        $limit = (int)$this->getInput('limit');
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

        $items = $xml->getElementsByTagName('item');
        $count = 0;

        foreach ($items as $element) {
            if ($count >= $limit) {
                break;
            }

            $titleEl = $element->getElementsByTagName('title')->item(0);
            $guidEl = $element->getElementsByTagName('guid')->item(0);
            $dateEl = $element->getElementsByTagName('dc:date')->item(0);

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

            $content = $this->fetchArticleContent($uri);

            $this->items[] = [
                'title' => $title,
                'uri' => $uri,
                'timestamp' => $timestamp,
                'content' => $content,
            ];

            $count++;
        }
    }

    private function fetchArticleContent(string $uri): string
    {
        $html = getContents($uri);
        if ($html === '') {
            return '';
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $this->resolveRelativeUrls($dom->documentElement);

        $texte = $dom->querySelector('div.texte');
        if ($texte === null) {
            return '';
        }

        return $dom->saveHTML($texte);
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
