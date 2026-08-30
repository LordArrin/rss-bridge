<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class NOSBridge extends BridgeAbstract
{
    public const NAME = 'NOS Nieuws & Sport';
    public const URI = 'https://www.nos.nl';
    public const DESCRIPTION = 'NOS Nieuws & Sport';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        [
            'topic' => [
                'type' => 'list',
                'name' => 'Onderwerp',
                'title' => 'Kies onderwerp',
                'values' => [
                    'Laatste nieuws' => 'nieuws/laatste',
                    'Binnenland' => 'nieuws/binnenland',
                    'Buitenland' => 'nieuws/buitenland',
                    'Regionaal nieuws' => 'nieuws/regio',
                    'Politiek' => 'nieuws/politiek',
                    'Economie' => 'nieuws/economie',
                    'Koningshuis' => 'nieuws/koningshuis',
                    'Tech' => 'nieuws/tech',
                    'Cultuur en media' => 'nieuws/cultuur-en-media',
                    'Opmerkelijk' => 'nieuws/opmerkelijk',
                    'Voetbal' => 'sport/voetbal',
                    'Formule 1' => 'sport/formule-1',
                    'Wielrennen' => 'sport/wielrennen',
                    'Schaatsen' => 'sport/schaatsen',
                    'Tennis' => 'sport/tennis',
                ],
            ]
        ]
    ];

    public function collectData(): void
    {
        $topicInput = $this->getInput('topic');
        if (is_string($topicInput) === false || $topicInput === '') {
            \throwClientException('Topic is required');
        }

        $url = sprintf('https://www.nos.nl/%s', $topicInput);
        $html = getContents($url);

        if (is_string($html) === false || $html === '') {
            \throwServerException('Empty response from NOS page');
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $mainContent = $dom->querySelector('main#content > div > section > ul');
        if ($mainContent === null) {
            \throwServerException(sprintf('Unable to find css selector on `%s`', $url));
        }

        $this->resolveRelativeLinks($mainContent, self::URI);

        $articles = $mainContent->querySelectorAll('li');

        foreach ($articles as $article) {
            if ($article instanceof \Dom\Element === false) {
                continue;
            }

            $h2 = $article->querySelector('h2');
            if ($h2 === null) {
                continue;
            }

            $title = trim((string) $h2->textContent);

            $link = $article->querySelector('a');
            if ($link === null) {
                continue;
            }

            $href = (string) ($link->getAttribute('href') ?? '');
            if ($href === '') {
                continue;
            }

            $p = $article->querySelector('p');
            $content = '';
            if ($p !== null) {
                $content = trim((string) $p->textContent);
            }

            $timeElement = $article->querySelector('time');
            $timestamp = null;
            if ($timeElement !== null) {
                $datetime = (string) ($timeElement->getAttribute('datetime') ?? '');
                if ($datetime !== '') {
                    $ts = strtotime($datetime);
                    if ($ts !== false) {
                        $timestamp = $ts;
                    }
                }
            }

            $item = [
                'title' => $title,
                'uri' => $href,
                'content' => $content,
                'uid' => md5($href),
            ];

            if ($timestamp !== null && $timestamp > 0) {
                $item['timestamp'] = $timestamp;
            }

            $this->items[] = $item;
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
}
