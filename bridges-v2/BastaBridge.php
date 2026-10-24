<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

use function RSSBridge\Exceptions\throwServerException;
use function urljoin;

final class BastaBridge extends BridgeAbstract
{
    public const NAME = 'Bastamag';
    public const URI = 'https://basta.media/';
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
        $limit = (int) $this->getInput('limit');
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

        $items = $xml->querySelectorAll('item');
        $count = 0;

        foreach ($items as $element) {
            if ($count >= $limit) {
                break;
            }

            $titleEl = $element->querySelector('title');
            $guidEl = $element->querySelector('guid');
            $dateEl = $element->querySelector('dc\:date');

            if ($titleEl === null || $guidEl === null) {
                continue;
            }

            $title = trim($titleEl->textContent);
            $uri = trim($guidEl->textContent);

            if ($title === '' || $uri === '') {
                continue;
            }

            $timestamp = null;
            if ($dateEl !== null) {
                $dateText = trim($dateEl->textContent);
                if ($dateText !== '') {
                    $parsed = strtotime($dateText);
                    if ($parsed !== false) {
                        $timestamp = $parsed;
                    }
                }
            }

            $content = $this->fetchArticleContent($uri);

            $this->items[] = [
                'title'     => $title,
                'uri'       => $uri,
                'timestamp' => $timestamp,
                'content'   => $content,
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

        $junkSelectors = [
            '.sur-le-meme-sujet',
            '.meme-sujet',
            '.same-topic',
            '.lire-aussi',
            '.also-read',
            '.related',
            '.related-articles',
            '.articles-lies',
            '[data-role="related"]',
            'svg[aria-hidden="true"]',
            '.spip_document',
        ];

        foreach ($junkSelectors as $selector) {
            foreach ($dom->querySelectorAll($selector) as $junk) {
                $junk->remove();
            }
        }

        $this->resolveRelativeUrls($dom->documentElement);

        $texte = $dom->querySelector('div.texte');
        if ($texte === null) {
            return '';
        }

        return $texte->innerHTML;
    }

    private function resolveRelativeUrls(?\Dom\Element $container): void
    {
        if ($container === null) {
            return;
        }

        $base = self::URI;
        $elements = $container->querySelectorAll('[src], [href]');

        foreach ($elements as $el) {
            foreach (['src', 'href'] as $attr) {
                if ($el->hasAttribute($attr) === false) {
                    continue;
                }

                $value = $el->getAttribute($attr);

                if (
                    $value === '' ||
                    preg_match('#^(https?:)?//#i', $value) === 1 ||
                    str_starts_with($value, 'data:') === true ||
                    str_starts_with($value, 'mailto:') === true ||
                    str_starts_with($value, 'javascript:') === true ||
                    str_starts_with($value, 'tel:') === true
                ) {
                    continue;
                }

                $resolvedUrl = urljoin($base, $value);
                if ($resolvedUrl !== null && $resolvedUrl !== '') {
                    $el->setAttribute($attr, $resolvedUrl);
                }
            }
        }
    }
}
