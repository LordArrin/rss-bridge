<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class AnthropicNewsBridge extends BridgeAbstract
{
    public const NAME = 'Anthropic News';
    public const URI = 'https://www.anthropic.com/news';
    public const DESCRIPTION = 'Announcements, product updates and policy news from Anthropic';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public function collectData(): void
    {
        $dom = getSimpleHTMLDOM(self::URI);
        $seen = [];

        foreach ($dom->querySelectorAll('a') as $link) {
            $heading = $link->querySelector('h2, h3');
            if ($heading === null) {
                continue;
            }

            $title = trim($heading->textContent ?? '');
            $href = trim($link->getAttribute('href') ?? '');
            if ($title === '' || $href === '' || str_starts_with($href, '#') === true) {
                continue;
            }

            $uri = urljoin(self::URI, $href);
            if (isset($seen[$uri]) === true) {
                continue;
            }
            $seen[$uri] = true;

            $card = $this->findCard($link);

            $item = [
                'title' => $title,
                'uri' => $uri,
            ];

            $content = $this->extractContent($card);
            if ($content !== '') {
                $item['content'] = $content;
            }

            $timestamp = $this->extractTimestamp($card);
            if ($timestamp !== null) {
                $item['timestamp'] = $timestamp;
            }

            $categories = $this->extractCategories($card);
            if ($categories !== []) {
                $item['categories'] = $categories;
            }

            $this->items[] = $item;
        }
    }

    private function findCard(\Dom\Element $link): \Dom\Element
    {
        $card = $link;
        $node = $link->parentElement;
        $depth = 0;

        while ($node !== null && $depth < 8) {
            if ($node->localName === 'li' && $node->hasAttribute('data-listing-row') === true) {
                return $node;
            }
            if ($node->querySelectorAll('time')->length > 1) {
                break;
            }
            $card = $node;
            if ($node->querySelector('time') !== null) {
                return $node;
            }
            $node = $node->parentElement;
            $depth++;
        }

        return $card;
    }

    private function extractContent(\Dom\Element $card): string
    {
        $paragraph = $card->querySelector('p[data-component="Body"]');
        if ($paragraph === null) {
            $paragraph = $card->querySelector('[class*="description"] p');
        }
        if ($paragraph === null) {
            return '';
        }
        $markup = $paragraph->C14N();
        return $markup !== false ? trim($markup) : '';
    }

    private function extractTimestamp(\Dom\Element $card): ?int
    {
        $time = $card->querySelector('time');
        if ($time === null) {
            return null;
        }

        $value = trim($time->getAttribute('datetime') ?? '');
        if ($value === '') {
            $value = trim($time->textContent ?? '');
        }
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);
        return $timestamp !== false ? $timestamp : null;
    }

    /**
     * @return list<string>
     */
    private function extractCategories(\Dom\Element $card): array
    {
        $categories = [];
        foreach ($card->querySelectorAll('header a, a[class*="category"]') as $node) {
            $text = trim($node->textContent ?? '');
            if ($text !== '' && in_array($text, $categories, true) === false) {
                $categories[] = $text;
            }
        }
        return $categories;
    }
}
