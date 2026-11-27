<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\FeedExpander;

use function urljoin;

final class OpenNetDailyBridge extends FeedExpander
{
    public const NAME = 'OpenNET Daily';
    public const URI = 'https://www.opennet.ru/';
    public const DESCRIPTION = 'Collects all OpenNET news of the previous day into a single digest post';
    public const MAINTAINER = 'LordArrin';
    public const CACHE_TIMEOUT = 86400;

    private const FEEDS = [
        'full' => 'https://opennet.ru/opennews/opennews_full_utf.rss',
        'main' => 'https://opennet.ru/opennews/opennews_6.rss',
        'mini' => 'https://opennet.ru/opennews/opennews_mini.rss',
    ];

    private const FEED_LABELS = [
        'full' => 'OpenNet.ru: All news (with full text)',
        'main' => 'OpenNet.ru: Main news',
        'mini' => 'OpenNet.ru: Mini news',
    ];

    private const CSS = [
        'wrapper' => 'font-size:14px; line-height:1.6; word-wrap:break-word;',
        'item'    => 'margin:0 0 16px 0;',
        'head'    => 'margin:0 0 6px 0;',
        'time'    => 'color:#888;',
        'text'    => 'margin:0 0 8px 0;',
        'figure'  => 'text-align:left; margin:8px 0;',
        'img'     => 'display:block; float:none; max-width:100%; height:auto; margin:8px 0;',
        'imglink' => 'display:block;',
        'iframe'  => 'max-width:100%;',
        'pre'     => 'white-space:pre-wrap; overflow-wrap:break-word; word-break:break-word; margin:8px 0; padding:8px; font-family:monospace; font-size:13px;',
        'list'    => 'padding-left:24px; margin:8px 0; list-style-position:outside;',
        'li'      => 'display:list-item; list-style-type:inherit; margin:4px 0;',
    ];

    public const PARAMETERS = [
        '' => [
            'feed' => [
                'name' => 'Feed',
                'type' => 'list',
                'values' => [
                    'All news (with full text)' => 'full',
                    'Main news' => 'main',
                    'Mini news' => 'mini',
                ],
                'defaultValue' => 'main',
            ],
        ],
    ];

    private int $dayStart = 0;
    private int $dayEnd = 0;

    public function getName(): string
    {
        $feedKey = (string) $this->getInput('feed');
        return self::FEED_LABELS[$feedKey] ?? parent::getName();
    }

    public function collectData(): void
    {
        $feedKey = (string) $this->getInput('feed');
        if (isset(self::FEEDS[$feedKey]) === false) {
            $feedKey = 'main';
        }

        $tzString = date_default_timezone_get();
        $tz = new \DateTimeZone($tzString);

        $todayStart = (new \DateTimeImmutable('now', $tz))->setTime(0, 0, 0);
        $yesterday = $todayStart->modify('-1 day');

        $this->dayStart = $yesterday->getTimestamp();
        $this->dayEnd = $todayStart->getTimestamp();

        $this->collectExpandableDatas(self::FEEDS[$feedKey]);

        $entries = [];
        foreach ($this->items as $item) {
            $ts = $item['timestamp'] ?? null;
            $title = $item['title'] ?? null;
            $uri = $item['uri'] ?? null;
            $content = $item['content'] ?? null;

            if (
                is_int($ts) === false
                || is_string($title) === false
                || is_string($uri) === false
                || is_string($content) === false
            ) {
                continue;
            }

            $title = trim($title);
            $uri = trim($uri);
            if ($title === '' || $uri === '') {
                continue;
            }

            $entries[] = ['ts' => $ts, 'title' => $title, 'uri' => $uri, 'content' => trim($content)];
        }

        if ($entries === []) {
            $this->items = [];
            return;
        }

        usort($entries, fn(array $a, array $b): int => $a['ts'] <=> $b['ts']);

        $this->items = [[
            'title' => self::FEED_LABELS[$feedKey] . ' за ' . $yesterday->format('d.m.Y'),
            'uri' => $this->getURI(),
            'content' => $this->buildContent($entries, $tz),
            'timestamp' => $entries[count($entries) - 1]['ts'],
            'uid' => 'opennet-' . $feedKey . '-' . $yesterday->format('Y-m-d'),
        ]];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>|false
     */
    protected function parseItem(array $item): array|false
    {
        $timestamp = $item['timestamp'] ?? null;
        if (is_int($timestamp) === false || $timestamp < $this->dayStart || $timestamp >= $this->dayEnd) {
            return false;
        }

        return $item;
    }

    /**
     * @param list<array{ts: int, title: string, uri: string, content: string}> $entries
     */
    private function buildContent(array $entries, \DateTimeZone $tz): string
    {
        $content = sprintf('<div style="%s">', self::CSS['wrapper']);

        foreach ($entries as $entry) {
            $time = (new \DateTimeImmutable('@' . $entry['ts']))->setTimezone($tz)->format('H:i');

            $content .= sprintf('<div style="%s">', self::CSS['item']);
            $content .= sprintf(
                '<p style="%s"><span style="%s">%s</span> <a href="%s"><b>%s</b></a></p>',
                self::CSS['head'],
                self::CSS['time'],
                e($time),
                e($entry['uri']),
                e($entry['title'])
            );

            if ($entry['content'] !== '') {
                $content .= $this->renderBody($entry['content']);
            }

            $content .= '</div>';
        }

        return $content . '</div>';
    }

    private function renderBody(string $body): string
    {
        if (preg_match('/<(a|p|br|img|pre|ul|ol|li|center|iframe|table|div|blockquote|b|i|font)\b/i', $body) === 1) {
            return $this->renderHtml($body);
        }

        return $this->renderText($body);
    }

    private function renderHtml(string $html): string
    {
        $html = preg_replace('#<br\s*/?>\s*<br\s*/?>\s*Источник:\s*<b><a\s[^>]*>.*?</a></b>\s*$#is', '', $html) ?? $html;

        $fragment = parseHtmlFragment($html);
        if ($fragment === null) {
            return break_annoying_html_tags($html, true);
        }

        $wrapper = $fragment->querySelector('#rss-bridge-temp-wrapper');
        if ($wrapper === null) {
            return break_annoying_html_tags($html, true);
        }

        foreach ($wrapper->querySelectorAll('[xmlns]') as $node) {
            $node->removeAttribute('xmlns');
        }

        foreach ($wrapper->querySelectorAll('pre') as $pre) {
            $this->normalizeCodeBlock($pre);
        }

        foreach ($wrapper->querySelectorAll('ul, ol') as $list) {
            $list->setAttribute('style', 'list-style-type:' . $this->listMarker($list, $wrapper) . '; ' . self::CSS['list']);
        }

        foreach ($wrapper->querySelectorAll('li') as $li) {
            $li->setAttribute('style', self::CSS['li']);
        }

        foreach ($wrapper->querySelectorAll('img') as $img) {
            $img->setAttribute('style', $this->mergeStyle($img->getAttribute('style'), self::CSS['img']));
            $img->setAttribute('loading', 'lazy');
            $img->removeAttribute('align');

            $parent = $img->parentElement;
            if ($parent !== null && $parent->localName === 'a' && trim($parent->textContent ?? '') === '') {
                $parent->setAttribute('style', self::CSS['imglink']);
            }
        }

        foreach ($wrapper->querySelectorAll('iframe') as $iframe) {
            $iframe->setAttribute('style', $this->mergeStyle($iframe->getAttribute('style'), self::CSS['iframe']));
        }

        foreach ($wrapper->querySelectorAll('a[href]') as $link) {
            $href = $link->getAttribute('href') ?? '';
            if ($href !== '' && str_starts_with($href, 'http') === false && str_starts_with($href, '#') === false) {
                $link->setAttribute('href', urljoin(self::URI, $href));
            }
            $link->setAttribute('rel', 'noopener');
        }

        foreach ($wrapper->querySelectorAll('center') as $center) {
            $block = $fragment->createElement('div');
            $block->setAttribute('style', self::CSS['figure']);
            while ($center->firstChild !== null) {
                $block->appendChild($center->firstChild);
            }
            $center->parentNode?->replaceChild($block, $center);
        }

        return break_annoying_html_tags($wrapper->innerHTML, true);
    }

    private function normalizeCodeBlock(\Dom\Element $pre): void
    {
        $pre->setAttribute('style', self::CSS['pre']);

        foreach (iterator_to_array($pre->querySelectorAll('font')) as $font) {
            $parent = $font->parentNode;
            if ($parent === null) {
                continue;
            }
            while ($font->firstChild !== null) {
                $parent->insertBefore($font->firstChild, $font);
            }
            $parent->removeChild($font);
        }

        foreach ($pre->querySelectorAll('[color]') as $node) {
            $node->removeAttribute('color');
        }

        foreach ($pre->querySelectorAll('[style]') as $node) {
            $style = $node->getAttribute('style') ?? '';
            $clean = preg_replace('/(?<![-\w])color\s*:[^;]*;?/i', '', $style);
            $clean = trim(is_string($clean) === true ? $clean : $style);
            if ($clean === '') {
                $node->removeAttribute('style');
            } else {
                $node->setAttribute('style', $clean);
            }
        }
    }

    private function listMarker(\Dom\Element $list, \Dom\Element $wrapper): string
    {
        if ($list->localName === 'ol') {
            return 'decimal';
        }

        $depth = 0;
        $parent = $list->parentElement;
        while ($parent !== null && $parent->isSameNode($wrapper) === false) {
            if ($parent->localName === 'ul' || $parent->localName === 'ol') {
                $depth++;
            }
            $parent = $parent->parentElement;
        }

        return match (true) {
            $depth === 0 => 'disc',
            $depth === 1 => 'circle',
            default => 'square',
        };
    }

    private function mergeStyle(?string $existing, string $extra): string
    {
        $existing = trim($existing ?? '');
        if ($existing === '') {
            return $extra;
        }

        return rtrim($existing, ';') . '; ' . $extra;
    }

    private function renderText(string $text): string
    {
        $paragraphs = preg_split('/\n\s*\n/', $text);
        if (is_array($paragraphs) === false) {
            $paragraphs = [$text];
        }

        $html = '';
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }
            $html .= sprintf('<p style="%s">%s</p>', self::CSS['text'], nl2br(e($paragraph), false));
        }

        return $html;
    }
}
