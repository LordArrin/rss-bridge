<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class ABCNewsBridge extends BridgeAbstract
{
    public const NAME = 'ABC News';
    public const URI = 'https://www.abc.net.au';
    public const DESCRIPTION = 'Topics of the Australian Broadcasting Corporation';
    public const MAINTAINER = 'No maintainer';

    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        [
            'topic' => [
                'type' => 'list',
                'name' => 'Region',
                'title' => 'Choose state',
                'values' => [
                    'ACT' => 'act',
                    'NSW' => 'nsw',
                    'NT' => 'nt',
                    'QLD' => 'qld',
                    'SA' => 'sa',
                    'TAS' => 'tas',
                    'VIC' => 'vic',
                    'WA' => 'wa'
                ],
            ]
        ]
    ];

    public function collectData(): void
    {
        $topic = (string)$this->getInput('topic');
        $url = sprintf('https://www.abc.net.au/news/%s', $topic);

        $html = getContents($url);
        if ($html === '') {
            throwServerException(sprintf('Failed to fetch %s', $url));
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $container = $dom->querySelector('div[data-component="PaginationList"]');
        if ($container === null) {
            throwServerException(sprintf('Unable to find css selector on `%s`', $url));
        }

        $this->resolveRelativeUrls($container);

        $articles = $container->querySelectorAll('article[data-component="DetailCard"]');
        foreach ($articles as $article) {
            $link = $article->querySelector('a');
            if ($link === null) {
                continue;
            }

            $href = $link->getAttribute('href') ?? '';
            if ($href === '') {
                continue;
            }

            $title = trim($link->textContent ?? '');
            if ($title === '') {
                continue;
            }

            $description = '';
            $p = $article->querySelector('p');
            if ($p !== null) {
                $description = trim($p->textContent ?? '');
            }

            $timestamp = null;
            $timeElement = $article->querySelector('time');
            if ($timeElement !== null) {
                $datetime = $timeElement->getAttribute('datetime');
                if ($datetime !== null && $datetime !== '') {
                    $parsed = strtotime($datetime);
                    if ($parsed !== false) {
                        $timestamp = $parsed;
                    }
                }
            }

            $this->items[] = [
                'title' => $title,
                'uri' => $href,
                'content' => $description,
                'timestamp' => $timestamp,
            ];
        }
    }

    private function resolveRelativeUrls(\Dom\Element $container): void
    {
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
