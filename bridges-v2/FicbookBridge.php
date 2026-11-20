<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

use function urljoin;

final class FicbookBridge extends BridgeAbstract
{
    public const NAME = 'Ficbook.net';
    public const URI = 'https://ficbook.net/';
    public const DESCRIPTION = 'Retrieves fanfiction updates by chapter from the ficbook.net';
    public const MAINTAINER = 'LordArrin';
    public const CACHE_TIMEOUT = 3600;

    private const CHAPTER_CACHE_TTL = 86400;
    private const PROXY_TIMEOUT_MS = 120000;
    private const FIC_ID_PATTERN = '(?:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|\d+)';
    private const TIMEZONE = 'Europe/Moscow';
    private const BLOCK_TAGS = 'p|div|blockquote|h[1-6]|ul|ol|li|center|pre|hr';

    private const MONTHS = [
        'января' => 1,
        'февраля' => 2,
        'марта' => 3,
        'апреля' => 4,
        'мая' => 5,
        'июня' => 6,
        'июля' => 7,
        'августа' => 8,
        'сентября' => 9,
        'октября' => 10,
        'ноября' => 11,
        'декабря' => 12,
    ];

    public const PARAMETERS = [
        '' => [
            'url' => [
                'name' => 'Fanfic URL',
                'type' => 'text',
                'required' => true,
                'exampleValue' => 'https://ficbook.net/readfic/11296776',
                'title' => 'Link to the fanfic or its ID (UUID or numeric)',
            ],
            'proxy' => [
                'name' => 'Proxy profile',
                'type' => 'list',
                'defaultValue' => 'flaresolverr',
                'values' => [
                    'FlareSolverr' => 'flaresolverr',
                    'Trawl' => 'trawl',
                    'Direct (no proxy)' => 'direct',
                ],
                'title' => 'Server-side profile used to bypass Cloudflare. Configure PROXY_PROFILE_FLARESOLVERR_URL or PROXY_PROFILE_TRAWL_URL first',
            ],
            'limit' => [
                'name' => 'Number of chapters',
                'type' => 'number',
                'defaultValue' => 5,
                'title' => 'Maximum number of chapters to return (max 100)',
            ],
            'full_content' => [
                'name' => 'Fetch full chapter content',
                'type' => 'checkbox',
                'defaultValue' => false,
                'title' => 'Download the chapter text into the feed. Every chapter costs one extra proxy request, so keep the limit low',
            ],
            'hide_categories' => [
                'name' => 'Hide categories',
                'type' => 'checkbox',
                'defaultValue' => false,
                'title' => 'Do not attach the fandom and the full tag list to feed items',
            ],
            'filter' => [
                'name' => 'Chapter title filter',
                'type' => 'text',
                'required' => false,
                'exampleValue' => 'Арка*, -*Ансель*',
                'title' => 'Comma-separated rules: * is a wildcard, a leading - excludes, + joins parts with AND, \\ escapes special characters. Without * a part matches as a substring, case-insensitive',
            ],
        ],
    ];

    private string $ficTitle = '';
    private string $ficAuthor = '';
    private string $ficUri = '';

    public function getIcon(): string
    {
        return self::URI . 'favicon.ico';
    }

    public function getName(): string
    {
        if ($this->ficTitle === '') {
            return parent::getName();
        }

        return $this->ficAuthor !== '' ? $this->ficTitle . ' — ' . $this->ficAuthor : $this->ficTitle;
    }

    public function getURI(): string
    {
        return $this->ficUri !== '' ? $this->ficUri : parent::getURI();
    }

    public function collectData(): void
    {
        $ficId = $this->getFicId();
        $profile = $this->getProfile();
        $this->ficUri = self::URI . 'readfic/' . $ficId;

        $limit = (int) ($this->getInput('limit') ?? 10);
        $limit = min(100, max(1, $limit));
        $fullContent = (bool) $this->getInput('full_content');
        $hideCategories = (bool) $this->getInput('hide_categories');
        $filter = trim((string) $this->getInput('filter'));

        $dom = $this->fetchPage($this->ficUri, $profile, self::CACHE_TIMEOUT);

        $this->ficTitle = $this->extractFicTitle($dom);
        $this->ficAuthor = strip_invisible_unicode(trim($dom->querySelector('a[itemprop="author"]')->textContent ?? ''));
        $categories = $hideCategories === true ? [] : $this->extractCategories($dom);

        $chapters = $this->extractChapters($dom, $ficId);
        if ($chapters === []) {
            $chapters = $this->extractSingleChapter($dom, $ficId);
        }

        if ($chapters === []) {
            throwServerException('No chapters found. The fanfic may be private, deleted, or the page layout has changed.');
        }

        usort($chapters, static fn (array $a, array $b): int => ($b['date'] ?? 0) <=> ($a['date'] ?? 0));

        $count = 0;
        foreach ($chapters as $chapter) {
            if ($count >= $limit) {
                break;
            }

            if ($filter !== '' && matches_keyword_rules($chapter['title'], $filter) === false) {
                continue;
            }

            $content = '<p><a href="' . e($chapter['uri']) . '">Read at Ficbook</a></p>';
            if ($fullContent === true) {
                $text = $this->fetchChapterContent($chapter['uri'], $profile);
                if ($text !== '') {
                    $content = $text;
                }
            }

            $item = [
                'uri' => $chapter['uri'],
                'title' => $chapter['title'],
                'timestamp' => $chapter['date'] ?? time(),
                'uid' => 'ficbook-' . $chapter['id'],
                'content' => $content,
            ];
            if ($this->ficAuthor !== '') {
                $item['author'] = $this->ficAuthor;
            }
            if ($categories !== []) {
                $item['categories'] = $categories;
            }

            $this->items[] = $item;
            $count++;
        }

        if ($this->items === []) {
            throwServerException('No chapters matched the title filter.');
        }
    }

    private function getFicId(): string
    {
        $input = trim((string) $this->getInput('url'));
        if ($input === '') {
            throwClientException('Fanfic URL or ID is required.');
        }

        if (preg_match('/^' . self::FIC_ID_PATTERN . '$/i', $input) === 1) {
            return strtolower($input);
        }

        if (preg_match('#ficbook\.net/readfic/(' . self::FIC_ID_PATTERN . ')#i', $input, $match) === 1) {
            return strtolower($match[1]);
        }

        if (preg_match('#(?:^|/)readfic/(' . self::FIC_ID_PATTERN . ')#i', $input, $match) === 1) {
            return strtolower($match[1]);
        }

        throwClientException('Invalid fanfic link. Expected https://ficbook.net/readfic/<id> (UUID or numeric) or the ID itself.');
    }

    private function getProfile(): string
    {
        $profile = (string) $this->getInput('proxy');

        return in_array($profile, ['flaresolverr', 'trawl', 'direct'], true) === true ? $profile : 'flaresolverr';
    }

    private function fetchPage(string $url, string $profile, int $cacheTtl): \Dom\HTMLDocument
    {
        return getProtectedSimpleHTMLDOM($url, $profile, [
            'timeout' => self::PROXY_TIMEOUT_MS,
            'cache_ttl' => $cacheTtl,
        ]);
    }

    private function extractFicTitle(\Dom\HTMLDocument $dom): string
    {
        $title = strip_invisible_unicode(trim($dom->querySelector('h1[itemprop="name"]')->textContent ?? ''));
        if ($title !== '') {
            return $title;
        }

        $title = strip_invisible_unicode(trim($dom->querySelector('h1.heading')->textContent ?? ''));
        if ($title !== '') {
            return $title;
        }

        return strip_invisible_unicode(trim($dom->querySelector('[fanfic-title]')?->getAttribute('fanfic-title') ?? ''));
    }

    /**
     * @return list<string>
     */
    private function extractCategories(\Dom\HTMLDocument $dom): array
    {
        $categories = [];

        foreach ($dom->querySelectorAll('.description a[href^="/fanfiction/"], .description .tags a.tag') as $node) {
            $value = strip_invisible_unicode(trim($node->textContent ?? ''));
            if ($value !== '' && in_array($value, $categories, true) === false) {
                $categories[] = $value;
            }
        }

        return $categories;
    }

    /**
     * @return list<array{id: string, title: string, uri: string, date: int|null}>
     */
    private function extractChapters(\Dom\HTMLDocument $dom, string $ficId): array
    {
        $chapters = [];
        $seen = [];

        foreach ($dom->querySelectorAll('ul.list-of-fanfic-parts li.part') as $node) {
            $link = $node->querySelector('a.part-link');
            if ($link === null) {
                continue;
            }

            $href = $link->getAttribute('href') ?? '';
            if (preg_match('#/readfic/' . $ficId . '/(\d+)#', $href, $match) !== 1) {
                continue;
            }

            $id = $match[1];
            if (in_array($id, $seen, true) === true) {
                continue;
            }

            $title = strip_invisible_unicode(trim($node->querySelector('.part-title h3')->textContent ?? ''));
            if ($title === '') {
                continue;
            }

            $seen[] = $id;
            $chapters[] = [
                'id' => $id,
                'title' => $title,
                'uri' => urljoin(self::URI, $href),
                'date' => $this->extractDate($node),
            ];
        }

        return $chapters;
    }

    /**
     * @return list<array{id: string, title: string, uri: string, date: int|null}>
     */
    private function extractSingleChapter(\Dom\HTMLDocument $dom, string $ficId): array
    {
        $article = $dom->querySelector('article#part_content');
        if ($article === null) {
            return [];
        }

        $title = strip_invisible_unicode(trim($article->querySelector('.title-area h2')->textContent ?? ''));
        if ($title === '') {
            $title = $this->ficTitle !== '' ? $this->ficTitle : 'Часть 1';
        }

        return [
            [
                'id' => $ficId,
                'title' => $title,
                'uri' => self::URI . 'readfic/' . $ficId . '#part_content',
                'date' => $this->extractDate($article),
            ],
        ];
    }

    private function extractDate(\Dom\Element $node): ?int
    {
        $text = $node->querySelector('span[title]')?->getAttribute('title') ?? '';
        $timestamp = $this->parseRussianDate($text);
        if ($timestamp !== null) {
            return $timestamp;
        }

        $iso = $node->querySelector('[itemprop="datePublished"]')?->getAttribute('content') ?? '';
        if ($iso !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso) === 1) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $iso, new \DateTimeZone(self::TIMEZONE));

            return $date !== false ? $date->getTimestamp() : null;
        }

        return null;
    }

    private function parseRussianDate(string $text): ?int
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        if (preg_match('/^(\d{1,2})\s+(\S+)\s+(\d{4})\s*г\.,\s*(\d{1,2}):(\d{2})$/u', $text, $match) !== 1) {
            return null;
        }

        $month = self::MONTHS[mb_strtolower($match[2], 'UTF-8')] ?? 0;
        if ($month === 0) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat(
            '!Y-n-j G:i',
            sprintf('%d-%d-%d %d:%s', (int) $match[3], $month, (int) $match[1], (int) $match[4], $match[5]),
            new \DateTimeZone(self::TIMEZONE)
        );

        return $date !== false ? $date->getTimestamp() : null;
    }

    private function fetchChapterContent(string $uri, string $profile): string
    {
        try {
            $dom = $this->fetchPage($uri, $profile, self::CHAPTER_CACHE_TTL);
        } catch (\Throwable) {
            return '';
        }

        $node = $dom->querySelector('#content');
        if ($node === null) {
            return '';
        }

        foreach ($node->querySelectorAll('script, style, ins, img') as $junk) {
            $junk->remove();
        }

        foreach ($node->querySelectorAll('a[href]') as $anchor) {
            $href = $anchor->getAttribute('href') ?? '';
            if ($href !== '' && str_starts_with($href, 'http') === false && str_starts_with($href, '#') === false && str_starts_with($href, 'mailto:') === false) {
                $anchor->setAttribute('href', urljoin($uri, $href));
            }
        }

        return break_annoying_html_tags($this->normalizeChapterHtml($node->innerHTML), true);
    }

    private function normalizeChapterHtml(string $html): string
    {
        $html = str_replace(["\r\n", "\r"], "\n", $html);
        $html = preg_replace('/\n(?:[ \t]|&nbsp;)+\n/', "\n\n", $html) ?? $html;

        $tokens = preg_split('/(<\/?(?:' . self::BLOCK_TAGS . ')\b[^>]*>)/i', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($tokens === false) {
            return $html;
        }

        $result = '';
        $depth = 0;

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            if (preg_match('/^<(\/?)(?:' . self::BLOCK_TAGS . ')\b[^>]*>$/i', $token, $match) === 1) {
                if (preg_match('/^<\/?hr\b/i', $token) !== 1) {
                    $depth = $match[1] === '/' ? max(0, $depth - 1) : $depth + 1;
                }

                $result .= $token;
                continue;
            }

            $result .= $this->normalizeChapterText($token, $depth > 0);
        }

        return $result;
    }

    private function normalizeChapterText(string $text, bool $insideBlock): string
    {
        $groups = preg_split('/\n{2,}/', $text);
        if ($groups === false) {
            $groups = [$text];
        }

        $parts = [];

        foreach ($groups as $group) {
            $lines = [];
            foreach (explode("\n", $group) as $line) {
                $line = rtrim($line);
                if ($line !== '') {
                    $lines[] = $line;
                }
            }

            if ($lines === []) {
                continue;
            }

            $separator = $this->extractSeparator($lines);
            if ($separator !== null) {
                $parts[] = $insideBlock === true ? $separator : '<p style="text-align: center;">' . $separator . '</p>';
                continue;
            }

            $body = implode('<br>', $lines);
            $parts[] = $insideBlock === true ? $body : '<p>' . $body . '</p>';
        }

        return implode($insideBlock === true ? '<br><br>' : '', $parts);
    }

    /**
     * @param list<string> $lines
     */
    private function extractSeparator(array $lines): ?string
    {
        $text = html_entity_decode(strip_tags(implode('', $lines)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\s\x{00A0}\x{200B}-\x{200D}\x{FEFF}]+/u', '', $text) ?? $text;

        return preg_match('/^\*{2,}$/', $text) === 1 ? $text : null;
    }
}
