<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\FeedExpander;

use function urljoin;

final class ThreeDNewsBridge extends FeedExpander
{
    public const NAME = '3DNews';
    public const URI = 'https://3dnews.ru/';
    public const DESCRIPTION = 'Latest 3DNews items by category or a single digest of the previous day';
    public const MAINTAINER = 'LordArrin';
    public const CACHE_TIMEOUT = 3600;

    private const CONTEXT_LATEST = 'Latest news';
    private const CONTEXT_DIGEST = 'Daily digest';

    private const FEEDS = [
        'all' => 'https://3dnews.ru/news/rss',
        'hardware' => 'https://3dnews.ru/hardware-news/rss',
        'software' => 'https://3dnews.ru/software-news/rss',
    ];

    private const FEED_LABELS = [
        'all' => '3DNews: All news',
        'hardware' => '3DNews: Hardware news',
        'software' => '3DNews: Software news',
    ];

    private const CATEGORY_PARAMETER = [
        'name' => 'Category',
        'type' => 'list',
        'values' => [
            'All news' => 'all',
            'Hardware' => 'hardware',
            'Software' => 'software',
        ],
        'defaultValue' => 'all',
    ];

    public const PARAMETERS = [
        self::CONTEXT_LATEST => [
            'category' => self::CATEGORY_PARAMETER,
            'limit' => [
                'name' => 'Item limit',
                'type' => 'number',
                'defaultValue' => 10,
                'title' => 'Maximum 20 items',
            ],
        ],
        self::CONTEXT_DIGEST => [
            'category' => self::CATEGORY_PARAMETER,
        ],
    ];

    private const CSS = [
        'wrapper' => 'font-size:14px; line-height:1.6; word-wrap:break-word;',
        'item'    => 'margin:0 0 16px 0;',
        'head'    => 'margin:0 0 6px 0;',
        'time'    => 'color:#888;',
        'text'    => 'margin:0 0 8px 0;',
        'figure'  => 'text-align:left; margin:8px 0;',
        'img'     => 'display:block; float:none; max-width:1200px; width:auto; height:auto; margin:8px 0;',
        'imglink' => 'display:block;',
        'iframe'  => 'max-width:100%;',
        'code'    => 'white-space:pre-wrap; overflow-wrap:break-word; word-break:break-word; margin:8px 0; padding:8px; font-family:monospace; font-size:13px;',
        'list'    => 'padding-left:24px; margin:8px 0; list-style-position:outside;',
        'li'      => 'display:list-item; list-style-type:inherit; margin:4px 0;',
    ];

    private const LIMIT_MAX = 20;
    private const DIGEST_CACHE_TIMEOUT = 43200;

    private const COLOR_STYLE_PATTERN = '/(?<![-\w])(?:color|background|background-color)\s*:[^;"\']*(?:;|$)/i';

    private int $dayStart = 0;
    private int $dayEnd = 0;

    public function getName(): string
    {
        return self::FEED_LABELS[$this->category()] ?? parent::getName();
    }

    public function getCacheTimeout(): int
    {
        return $this->isDigest() === true ? self::DIGEST_CACHE_TIMEOUT : self::CACHE_TIMEOUT;
    }

    public function collectData(): void
    {
        $category = $this->category();
        $digest = $this->isDigest();

        if ($digest === true) {
            $tz = new \DateTimeZone(date_default_timezone_get());
            $todayStart = (new \DateTimeImmutable('now', $tz))->setTime(0, 0, 0);
            $yesterday = $todayStart->modify('-1 day');
            $this->dayStart = $yesterday->getTimestamp();
            $this->dayEnd = $todayStart->getTimestamp();
        }

        $this->collectExpandableDatas(self::FEEDS[$category], $digest === true ? -1 : $this->itemLimit());

        $entries = $this->collectEntries();

        if ($entries === []) {
            $this->items = [];

            return;
        }

        if ($digest === true) {
            $this->items = [$this->buildDigest($entries, $category)];

            return;
        }

        $this->items = [];

        foreach ($entries as $entry) {
            $this->items[] = $this->buildItem($entry);
        }
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>|false
     */
    protected function parseItem(array $item): array|false
    {
        if ($this->isDigest() === false) {
            return $item;
        }

        $timestamp = $item['timestamp'] ?? null;

        if (is_int($timestamp) === false || $timestamp < $this->dayStart || $timestamp >= $this->dayEnd) {
            return false;
        }

        return $item;
    }

    private function isDigest(): bool
    {
        return $this->queriedContext === self::CONTEXT_DIGEST;
    }

    private function category(): string
    {
        $category = (string)$this->getInput('category');

        return isset(self::FEEDS[$category]) === true ? $category : 'all';
    }

    private function itemLimit(): int
    {
        $requested = (int)($this->getInput('limit') ?? 10);

        if ($requested <= 0) {
            $requested = 10;
        }

        return min($requested, self::LIMIT_MAX);
    }

    /**
     * @return list<array{ts: int, title: string, uri: string, content: string, enclosure: string}>
     */
    private function collectEntries(): array
    {
        $entries = [];

        foreach ($this->items as $item) {
            $title = $item['title'] ?? null;
            $uri = $item['uri'] ?? null;
            $content = $item['content'] ?? null;

            if (is_string($title) === false || is_string($uri) === false || is_string($content) === false) {
                continue;
            }

            $title = trim($title);
            $uri = trim($uri);

            if ($title === '' || $uri === '') {
                continue;
            }

            $ts = $item['timestamp'] ?? null;
            $enclosure = '';
            $enclosures = $item['enclosures'] ?? null;

            if (is_array($enclosures) === true && isset($enclosures[0]) === true && is_string($enclosures[0]) === true) {
                $enclosure = $enclosures[0];
            }

            $entries[] = [
                'ts' => is_int($ts) === true ? $ts : time(),
                'title' => $title,
                'uri' => $uri,
                'content' => trim($content),
                'enclosure' => $enclosure,
            ];
        }

        usort($entries, fn(array $a, array $b): int => $a['ts'] <=> $b['ts']);

        if ($this->isDigest() === false) {
            $entries = array_reverse($entries);
        }

        return $entries;
    }

    /**
     * @param array{ts: int, title: string, uri: string, content: string, enclosure: string} $entry
     * @return array<string, mixed>
     */
    private function buildItem(array $entry): array
    {
        return [
            'title' => $entry['title'],
            'uri' => $entry['uri'],
            'content' => sprintf('<div style="%s">%s</div>', self::CSS['wrapper'], $this->entryBody($entry)),
            'timestamp' => $entry['ts'],
            'uid' => hash('sha256', $entry['uri']),
        ];
    }

    /**
     * @param list<array{ts: int, title: string, uri: string, content: string, enclosure: string}> $entries
     * @return array<string, mixed>
     */
    private function buildDigest(array $entries, string $category): array
    {
        $tz = new \DateTimeZone(date_default_timezone_get());
        $day = (new \DateTimeImmutable('@' . $this->dayStart))->setTimezone($tz);
        $content = sprintf('<div style="%s">', self::CSS['wrapper']);

        foreach ($entries as $entry) {
            $time = (new \DateTimeImmutable('@' . $entry['ts']))->setTimezone($tz)->format('H:i');

            $content .= sprintf('<div style="%s">', self::CSS['item']);
            $content .= sprintf(
                '<p style="%s"><span style="%s">%s</span> <a href="%s" rel="noopener"><b>%s</b></a></p>',
                self::CSS['head'],
                self::CSS['time'],
                e($time),
                e($entry['uri']),
                e($entry['title'])
            );
            $content .= $this->entryBody($entry);
            $content .= '</div>';
        }

        return [
            'title' => self::FEED_LABELS[$category] . ' за ' . $day->format('d.m.Y'),
            'uri' => $this->getURI(),
            'content' => $content . '</div>',
            'timestamp' => $entries[count($entries) - 1]['ts'],
            'uid' => '3dnews-' . $category . '-' . $day->format('Y-m-d'),
        ];
    }

    /**
     * @param array{ts: int, title: string, uri: string, content: string, enclosure: string} $entry
     */
    private function entryBody(array $entry): string
    {
        $body = trim($entry['content']);
        $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (preg_match(pattern: '/<(p|div|br|ul|ol|table|blockquote)\b/i', subject: $body) !== 1) {
            $body = sprintf('<p style="%s">%s</p>', self::CSS['text'], nl2br(e($body), false));
        }

        if ($entry['enclosure'] !== '' && preg_match(pattern: '/<img\b/i', subject: $body) !== 1) {
            $body = sprintf('<img src="%s">', e($this->cleanImageUrl($entry['enclosure']))) . $body;
        }

        return $this->renderHtml($body);
    }

    private function cleanImageUrl(string $url): string
    {
        $url = ltrim(trim($url), '|/');

        if (str_starts_with($url, '//') === true) {
            $url = 'https:' . $url;
        }

        if ($url !== '' && str_starts_with($url, 'http') === false) {
            $url = urljoin(self::URI, $url);
        }

        $url = (string)preg_replace(pattern: '/\?.*$/', replacement: '', subject: $url);
        $url = (string)preg_replace(pattern: '#/sm\.#', replacement: '/', subject: $url);

        return (string)preg_replace(pattern: '/\.(800|400|200|100)\./', replacement: '.', subject: $url);
    }

    private function renderHtml(string $html): string
    {
        $html = (string)preg_replace(pattern: self::COLOR_STYLE_PATTERN, replacement: '', subject: $html);

        $fragment = parseHtmlFragment($html);

        if ($fragment === null) {
            return break_annoying_html_tags($html, true);
        }

        $wrapper = $fragment->querySelector('#rss-bridge-temp-wrapper');

        if ($wrapper === null) {
            return break_annoying_html_tags($html, true);
        }

        foreach (iterator_to_array($wrapper->querySelectorAll('[xmlns]')) as $node) {
            $node->removeAttribute('xmlns');
        }

        foreach (iterator_to_array($wrapper->querySelectorAll('font')) as $font) {
            $parent = $font->parentNode;

            if ($parent === null) {
                continue;
            }

            while ($font->firstChild !== null) {
                $parent->insertBefore($font->firstChild, $font);
            }

            $parent->removeChild($font);
        }

        foreach (iterator_to_array($wrapper->querySelectorAll('[color]')) as $node) {
            $node->removeAttribute('color');
        }

        foreach (iterator_to_array($wrapper->querySelectorAll('[bgcolor]')) as $node) {
            $node->removeAttribute('bgcolor');
        }

        foreach (iterator_to_array($wrapper->querySelectorAll('[style]')) as $node) {
            $style = (string)$node->getAttribute('style');
            $style = (string)preg_replace(pattern: self::COLOR_STYLE_PATTERN, replacement: '', subject: $style);
            $style = trim(trim($style), ';');

            if ($style === '') {
                $node->removeAttribute('style');
            } else {
                $node->setAttribute('style', $style);
            }
        }

        foreach (iterator_to_array($wrapper->querySelectorAll('pre')) as $pre) {
            $pre->setAttribute('style', self::CSS['code']);
        }

        foreach (iterator_to_array($wrapper->querySelectorAll('ul, ol')) as $list) {
            $list->setAttribute('style', 'list-style-type:' . $this->listMarker($list, $wrapper) . '; ' . self::CSS['list']);
        }

        foreach (iterator_to_array($wrapper->querySelectorAll('li')) as $li) {
            $li->setAttribute('style', self::CSS['li']);
        }

        foreach (iterator_to_array($wrapper->querySelectorAll('iframe')) as $iframe) {
            $src = (string)$iframe->getAttribute('src');

            if ($src !== '' && video_embed_provider($src) !== null) {
                $iframe->setAttribute('style', $this->mergeStyle($iframe->getAttribute('style'), self::CSS['iframe']));
                $iframe->setAttribute('loading', 'lazy');

                continue;
            }

            $this->replaceWithLink($fragment, $iframe, $src, 'Watch video');
        }

        foreach (iterator_to_array($wrapper->querySelectorAll('img')) as $img) {
            $src = (string)$img->getAttribute('src');

            if ($src !== '') {
                $img->setAttribute('src', $this->cleanImageUrl($src));
            }

            $img->setAttribute('style', $this->mergeStyle($img->getAttribute('style'), self::CSS['img']));
            $img->setAttribute('loading', 'lazy');
            $img->removeAttribute('align');
            $img->removeAttribute('hspace');
            $img->removeAttribute('vspace');
            $img->removeAttribute('width');
            $img->removeAttribute('height');

            $parent = $img->parentElement;

            if ($parent !== null && $parent->localName === 'a' && trim($parent->textContent ?? '') === '') {
                $parent->setAttribute('style', self::CSS['imglink']);
            }
        }

        foreach (iterator_to_array($wrapper->querySelectorAll('a[href]')) as $link) {
            $href = (string)$link->getAttribute('href');

            if ($href !== '' && str_starts_with($href, 'http') === false && str_starts_with($href, '#') === false) {
                $link->setAttribute('href', urljoin(self::URI, $href));
            }

            $link->setAttribute('rel', 'noopener');
        }

        foreach (iterator_to_array($wrapper->querySelectorAll('center')) as $center) {
            $block = $fragment->createElement('div');
            $block->setAttribute('style', self::CSS['figure']);

            while ($center->firstChild !== null) {
                $block->appendChild($center->firstChild);
            }

            $center->parentNode?->replaceChild($block, $center);
        }

        return break_annoying_html_tags($wrapper->innerHTML, true);
    }

    private function replaceWithLink(\Dom\HTMLDocument $document, \Dom\Element $node, string $url, string $label): void
    {
        $parent = $node->parentNode;

        if ($parent === null || $url === '') {
            $node->remove();

            return;
        }

        $link = $document->createElement('a');
        $link->setAttribute('href', $url);
        $link->setAttribute('rel', 'noopener');
        $link->appendChild($document->createTextNode($label));
        $parent->replaceChild($link, $node);
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
}
