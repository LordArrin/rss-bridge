<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;
use RSSBridge\Utils\Json;

final class AuthorTodayBridge extends BridgeAbstract
{
    public const NAME = 'Author Today';
    public const URI = 'https://author.today';
    public const DESCRIPTION = 'Returns updates for stories by chapter';
    public const MAINTAINER = 'LordArrin';
    public const CACHE_TIMEOUT = 900;

    public const CONFIGURATION = [
        'login_cookie' => ['required' => false],
        'trusted_token' => ['required' => false],
        'ng_login_cookie' => ['required' => false],
        'user_id' => ['required' => false],
    ];

    public const PARAMETERS = [
        '' => [
            'work' => [
                'name' => 'Story ID',
                'type' => 'text',
                'required' => true,
                'exampleValue' => '230933',
            ],
            'notags' => [
                'name' => 'Disable tags',
                'type' => 'checkbox',
                'required' => false,
                'defaultValue' => 'checked',
            ],
            'fulltext' => [
                'name' => 'Fetch full chapters',
                'type' => 'checkbox',
                'required' => false,
                'title' => 'Feed contains only full chapter texts instead of previews. Paid chapters are skipped.',
            ],
            'fullcount' => [
                'name' => 'Chapters with full text (max 3)',
                'type' => 'number',
                'required' => false,
                'defaultValue' => 3,
                'title' => 'Used only when Fetch full chapters is enabled. Paid ones are remembered for a day.',
            ],
        ],
    ];

    public const CSS = [
        'status' => 'margin-bottom:10px',
        'label' => 'display:inline-block;padding:2px 6px;border-radius:3px;color:#ffffff;white-space:nowrap',
        'label_primary' => 'background:#337ab7',
        'label_success' => 'background:#5cb85c',
        'label_danger' => 'background:#d9534f',
        'label_default' => 'background:#777777',
        'meta' => 'white-space:nowrap',
        'separator' => 'color:#999999',
        'cover' => 'display:block;max-width:300px;height:auto;border-radius:4px',
        'text' => 'font-size:16px;line-height:1.7;word-wrap:break-word',
        'para' => 'margin:0 0 10px 0;text-indent:0',
        'notice' => 'margin:12px 0 0 0;padding:8px 12px;border-left:3px solid #777777',
        'figure' => 'text-align:left;margin:12px 0',
        'img' => 'display:block;float:none;max-width:100%;height:auto;margin:8px 0',
        'imglink' => 'display:block',
        'code' => 'white-space:pre-wrap;overflow-wrap:break-word;word-break:break-word;margin:8px 0;padding:8px;font-family:monospace;font-size:13px',
    ];

    private const ITEM_LIMIT = 5;
    private const FULL_TEXT_LIMIT = 3;
    private const MAX_CHAPTER_REQUESTS = 8;
    private const CHAPTER_CACHE_TTL = 604800;
    private const PAID_CACHE_TTL = 86400;
    private const MIN_DELAY_US = 1000000;
    private const MAX_DELAY_US = 3000000;
    private const FOOTER_SEARCH_DEPTH = 8;
    private const ADULT_COOKIE = 'AdultUser=true';
    private const COLOR_STYLE_PATTERN = '/(?<![-\w])(?:color|background|background-color)\s*:[^;"\']*(?:;|$)/i';
    private const CHAPTER_TAG_PATTERN = '/<(p|br|div|span|em|strong|i|b|img|hr|pre|ul|ol|li|blockquote|h[1-6])\b/i';
    private const MOJIBAKE_PATTERN = '/[\x{0460}-\x{04FF}\x{A640}-\x{A69F}]/u';

    private const AUTH_COOKIES = [
        'login_cookie' => 'LoginCookie',
        'trusted_token' => 'TrustedToken',
        'ng_login_cookie' => 'ngLoginCookie',
    ];

    private string $feedTitle = '';
    private string $userId = '';
    private bool $hasAuthCookies = false;
    private int $chapterRequests = 0;

    public function getURI(): string
    {
        $workId = $this->workId();

        if ($workId === null) {
            return self::URI;
        }

        return self::URI . '/work/' . $workId;
    }

    public function getName(): string
    {
        return $this->feedTitle !== '' ? $this->feedTitle : self::NAME;
    }

    public function getIcon(): string
    {
        return self::URI . '/favicon.ico';
    }

    public function collectData(): void
    {
        $workId = $this->workId();

        if ($workId === null) {
            throwClientException('Story ID must be a number or a URL containing /work/{id}');
        }

        $this->hasAuthCookies = $this->configuredCookieNames() !== [];
        $this->logAuthState();

        $html = $this->loadPage($workId);
        $this->feedTitle = $this->extractFeedTitle($html);
        $this->userId = $this->extractUserId($html);
        $items = $this->parseChapters($html);

        if ($items === []) {
            throwServerException('Unable to parse chapters.');
        }

        $this->addSortedItems($items);
    }

    /**
     * @return list<string>
     */
    private function configuredCookieNames(): array
    {
        $names = [];

        foreach (self::AUTH_COOKIES as $option => $name) {
            if ($this->cookieValue($option, $name) !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function logAuthState(): void
    {
        $names = $this->configuredCookieNames();

        if ($names === []) {
            $this->logger?->info('AuthorTodayBridge: no auth cookies configured, running anonymously');

            return;
        }

        $this->logger?->info('AuthorTodayBridge: auth cookies configured: ' . implode(', ', $names));
    }

    /**
     * @return list<string>
     */
    private function requestHeaders(string $referer): array
    {
        return ['Referer: ' . $referer, 'Cookie: ' . $this->cookieHeader()];
    }

    private function cookieHeader(): string
    {
        $parts = [];

        foreach (self::AUTH_COOKIES as $option => $name) {
            $value = $this->cookieValue($option, $name);

            if ($value !== '') {
                $parts[] = $name . '=' . $value;
            }
        }

        $parts[] = self::ADULT_COOKIE;

        return implode('; ', $parts);
    }

    private function cookieValue(string $option, string $name): string
    {
        $value = trim((string)$this->getOption($option));

        if (stripos($value, $name . '=') === 0) {
            $value = substr($value, strlen($name) + 1);
        }

        $value = (string)preg_replace(pattern: '/[\r\n;\s]+/', replacement: '', subject: $value);

        return trim($value, '"\'');
    }

    private function extractUserId(\Dom\HTMLDocument $html): string
    {
        $configured = trim((string)$this->getOption('user_id'));

        if ($configured !== '' && ctype_digit($configured) === true) {
            $this->logger?->info('AuthorTodayBridge: using user_id from configuration: ' . $configured);

            return $configured;
        }

        $source = $html->saveHtml();

        if (preg_match(pattern: '/userId\s*:\s*(\d+)/', subject: $source, matches: $matches) === 1) {
            $this->logger?->info('AuthorTodayBridge: user_id detected on the work page: ' . $matches[1]);

            return $matches[1];
        }

        if ($this->hasAuthCookies === true) {
            $this->logger?->warning('AuthorTodayBridge: auth cookies are set but the page reports no user_id - the session is probably expired');
        } else {
            $this->logger?->info('AuthorTodayBridge: no user_id on the page, anonymous session');
        }

        return '';
    }

    private function loadPage(string $workId): \Dom\HTMLDocument
    {
        $url = self::URI . '/work/' . $workId;
        $html = getContents($url, $this->requestHeaders(self::URI . '/'));

        if ($html === '') {
            throwServerException("Unable to load page: {$url}");
        }

        $dom = parseHtmlDocument($html);

        return $dom;
    }

    private function extractFeedTitle(\Dom\HTMLDocument $html): string
    {
        $titleNode = $html->querySelector('h1.book-title');

        if ($titleNode === null) {
            return '';
        }

        return trim($titleNode->textContent ?? '');
    }

    /**
     * @return list<array{uri: string, title: string, uid: string, content: string, _position: int, timestamp?: int, author?: string, categories?: list<string>}>
     */
    private function parseChapters(\Dom\HTMLDocument $html): array
    {
        $authorNode = $html->querySelector('.book-authors a');
        $author = $authorNode !== null ? trim($authorNode->textContent ?? '') : '';

        $coverNode = $html->querySelector('img.cover-image');
        $coverUrl = $coverNode !== null ? $this->absoluteUrl((string)$coverNode->getAttribute('src')) : '';

        $statusHtml = $this->statusHtml($html);
        $tags = $this->getInput('notags') === true ? [] : $this->tags($html);

        $chapters = $html->querySelectorAll('#tab-chapters ul.table-of-content li');

        if ($chapters->length === 0) {
            throwServerException('Chapter list not found. The work may be unavailable or markup has changed.');
        }

        $items = [];
        $chaptersArray = iterator_to_array($chapters);

        foreach (array_reverse($chaptersArray) as $position => $chapter) {
            if ($chapter instanceof \Dom\Element === false) {
                continue;
            }
            $item = $this->buildChapterItem($chapter, $position, $statusHtml, $coverUrl, $author, $tags);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param list<string> $tags
     * @return array{uri: string, title: string, uid: string, content: string, _position: int, timestamp?: int, author?: string, categories?: list<string>}|null
     */
    private function buildChapterItem(
        \Dom\Element $chapter,
        int $position,
        string $statusHtml,
        string $coverUrl,
        string $author,
        array $tags
    ): ?array {
        $link = $chapter->querySelector('a');

        if ($link === null) {
            return null;
        }

        $title = trim($link->textContent ?? '');
        $uri = $this->absoluteUrl((string)$link->getAttribute('href'));
        $timeNode = $chapter->querySelector('[data-time]');
        $timestamp = $timeNode !== null ? $this->timestamp((string)$timeNode->getAttribute('data-time')) : null;

        $content = $statusHtml;

        if ($coverUrl !== '') {
            $escapedUri = htmlspecialchars($uri, ENT_QUOTES, 'UTF-8');
            $escapedCover = htmlspecialchars($coverUrl, ENT_QUOTES, 'UTF-8');
            $coverStyle = self::CSS['cover'];
            $content .= "<p><a href=\"{$escapedUri}\"><img src=\"{$escapedCover}\" alt=\"Cover\" style=\"{$coverStyle}\"></a></p>";
        }

        return [
            'uri' => $uri,
            'title' => $title !== '' ? $title : 'Chapter',
            'uid' => $uri,
            'content' => $content,
            '_position' => $position,
            ...($timestamp !== null ? ['timestamp' => $timestamp] : []),
            ...($author !== '' ? ['author' => $author] : []),
            ...($tags !== [] ? ['categories' => $tags] : []),
        ];
    }

    /**
     * @param list<array{uri: string, title: string, uid: string, content: string, _position: int, timestamp?: int, author?: string, categories?: list<string>}> $items
     */
    private function addSortedItems(array $items): void
    {
        usort($items, function (array $a, array $b): int {
            $timeComparison = ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0);

            return $timeComparison !== 0 ? $timeComparison : $a['_position'] <=> $b['_position'];
        });

        if ($this->getInput('fulltext') === true) {
            $this->addFullTextItems($items);

            return;
        }

        foreach (array_slice($items, 0, self::ITEM_LIMIT) as $item) {
            unset($item['_position']);
            $this->items[] = $item;
        }
    }

    /**
     * @param list<array{uri: string, title: string, uid: string, content: string, _position: int, timestamp?: int, author?: string, categories?: list<string>}> $items
     */
    private function addFullTextItems(array $items): void
    {
        $count = (int)($this->getInput('fullcount') ?? self::FULL_TEXT_LIMIT);

        if ($count === 0) {
            $count = self::FULL_TEXT_LIMIT;
        }

        $count = max(1, min(self::FULL_TEXT_LIMIT, $count));

        foreach ($items as $item) {
            if (count($this->items) >= $count || $this->chapterRequests >= self::MAX_CHAPTER_REQUESTS) {
                break;
            }

            if (preg_match(pattern: '#/reader/(\d+)/(\d+)#', subject: $item['uri'], matches: $matches) !== 1) {
                continue;
            }

            $result = $this->chapterText($matches[1], $matches[2]);

            if ($result['full'] !== true) {
                $this->logger?->info('AuthorTodayBridge: chapter ' . $matches[2] . ' is not readable, skipped');

                continue;
            }

            unset($item['_position']);
            $item['content'] = $result['html'];
            $this->items[] = $item;
        }

        if ($this->items === []) {
            throwServerException('No freely readable chapters found. Paid chapters are re-checked once a day.');
        }
    }

    /**
     * @return array{html: string, full: bool}
     */
    private function chapterText(string $workId, string $chapterId): array
    {
        $identity = $this->userId !== '' ? $this->userId : 'anon';
        $cacheKey = 'chapter_v2_' . $workId . '_' . $chapterId . '_' . $identity;
        $cached = $this->cachedChapter($this->loadCacheValue($cacheKey));

        if ($cached !== null) {
            $this->logger?->debug('AuthorTodayBridge: chapter ' . $chapterId . ' served from cache (' . $identity . ')');

            return $cached;
        }

        $this->chapterRequests++;
        usleep(random_int(self::MIN_DELAY_US, self::MAX_DELAY_US));

        $url = self::URI . '/reader/' . $workId . '/chapter?id=' . $chapterId;
        $referer = self::URI . '/reader/' . $workId . '/' . $chapterId;
        $response = getContents($url, $this->requestHeaders($referer), [], true);
        $data = Json::decode($response->getBody());

        if (is_array($data) === false) {
            throwServerException('Author.Today returned an unexpected chapter response');
        }

        if (($data['isSuccessful'] ?? false) !== true) {
            $messages = $data['messages'] ?? [];
            $reason = is_array($messages) === true && isset($messages[0]) === true && is_string($messages[0]) === true ? $messages[0] : '';
            $this->logger?->info('AuthorTodayBridge: chapter ' . $chapterId . ' rejected by the site: ' . ($reason !== '' ? $reason : 'unknown reason'));

            return $this->rememberChapter($cacheKey, ['html' => $this->noticeHtml($reason), 'full' => false]);
        }

        $payload = $data['data'] ?? null;
        $encoded = is_array($payload) === true ? ($payload['text'] ?? null) : null;

        if (is_string($encoded) === false || $encoded === '') {
            return $this->rememberChapter($cacheKey, ['html' => $this->noticeHtml('Empty'), 'full' => false]);
        }

        $secret = $response->getHeader('Reader-Secret');

        if (is_string($secret) === false || $secret === '') {
            throwServerException('Author.Today did not return the Reader-Secret header');
        }

        $text = $this->decodeChapter($encoded, $secret, $chapterId);
        $html = $this->sanitizeChapter($text);

        if ($html === '') {
            return $this->rememberChapter($cacheKey, ['html' => $this->noticeHtml('Empty'), 'full' => false]);
        }

        return $this->rememberChapter($cacheKey, ['html' => $html, 'full' => true]);
    }

    /**
     * @return array{html: string, full: bool}|null
     */
    private function cachedChapter(mixed $cached): ?array
    {
        if (is_array($cached) === false) {
            return null;
        }

        $html = $cached['html'] ?? null;
        $full = $cached['full'] ?? null;

        if (is_string($html) === false || $html === '' || is_bool($full) === false) {
            return null;
        }

        return ['html' => $html, 'full' => $full];
    }

    /**
     * @param array{html: string, full: bool} $result
     * @return array{html: string, full: bool}
     */
    private function rememberChapter(string $cacheKey, array $result): array
    {
        $ttl = $result['full'] === true ? self::CHAPTER_CACHE_TTL : self::PAID_CACHE_TTL;
        $this->saveCacheValue($cacheKey, $result, $ttl);

        return $result;
    }

    private function noticeHtml(string $reason): string
    {
        $label = match ($reason) {
            'Paid' => 'This chapter is paid and is not available for free reading yet.',
            'Unauthorized' => 'This chapter requires signing in.',
            'Suspended' => 'This work is suspended by the site.',
            'Unadulted' => 'This chapter is marked 18+ and requires age confirmation on the site.',
            'Empty' => 'The chapter text is empty.',
            default => 'The chapter is not available for free reading.',
        };

        $noticeStyle = self::CSS['notice'];
        $escapedLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

        return "<p style=\"{$noticeStyle}\">{$escapedLabel}</p>";
    }

    private function decodeChapter(string $encoded, string $secret, string $chapterId): string
    {
        $prefix = strrev($secret) . '@_@';
        $candidates = [[$this->userId !== '' ? 'account' : 'anonymous', $prefix . $this->userId]];

        if ($this->userId !== '') {
            $candidates[] = ['anonymous', $prefix];
        }

        foreach ($candidates as $candidate) {
            $text = $this->xorDecode($encoded, $candidate[1]);

            if ($this->looksLikeChapter($text) === false) {
                continue;
            }

            $this->logDecodeMode($candidate[0], $chapterId);

            return $text;
        }

        $this->logger?->error('AuthorTodayBridge: chapter ' . $chapterId . ' could not be decoded with any key');

        throwServerException('Failed to decode the chapter text. When auth cookies are used, set user_id in the bridge configuration.');
    }

    private function logDecodeMode(string $mode, string $chapterId): void
    {
        if ($mode === 'account') {
            $this->logger?->info('AuthorTodayBridge: chapter ' . $chapterId . ' decoded with the account key (user_id ' . $this->userId . ')');

            return;
        }

        if ($this->hasAuthCookies === true) {
            $this->logger?->warning('AuthorTodayBridge: chapter ' . $chapterId . ' fell back to the anonymous key - auth cookies did not take effect');

            return;
        }

        $this->logger?->info('AuthorTodayBridge: chapter ' . $chapterId . ' decoded with the anonymous key');
    }

    private function looksLikeChapter(string $html): bool
    {
        if (preg_match(pattern: self::CHAPTER_TAG_PATTERN, subject: $html) !== 1) {
            return false;
        }

        if (preg_match('//u', $html) !== 1) {
            return false;
        }

        return preg_match(pattern: self::MOJIBAKE_PATTERN, subject: $html) === 0;
    }

    private function xorDecode(string $text, string $key): string
    {
        $units = unpack('v*', mb_convert_encoding($text, 'UTF-16LE', 'UTF-8'));
        $keyUnits = unpack('v*', mb_convert_encoding($key, 'UTF-16LE', 'UTF-8'));

        if ($units === false || $keyUnits === false) {
            return '';
        }

        $keyUnits = array_values($keyUnits);
        $keyLength = count($keyUnits);

        if ($keyLength === 0) {
            return '';
        }

        $decoded = [];
        $index = 0;

        foreach ($units as $unit) {
            $decoded[] = $unit ^ $keyUnits[$index % $keyLength];
            $index++;
        }

        $result = mb_convert_encoding(pack('v*', ...$decoded), 'UTF-8', 'UTF-16LE');

        return is_string($result) === true ? $result : '';
    }

    private function sanitizeChapter(string $html): string
    {
        $html = (string)preg_replace(pattern: self::COLOR_STYLE_PATTERN, replacement: '', subject: $html);

        $fragment = parseHtmlFragment($html);

        if ($fragment === null) {
            return break_annoying_html_tags($html);
        }

        $wrapper = $fragment->querySelector('#rss-bridge-temp-wrapper');

        if ($wrapper === null) {
            return break_annoying_html_tags($html);
        }

        foreach ($wrapper->querySelectorAll('font') as $font) {
            $parent = $font->parentNode;

            if ($parent === null) {
                continue;
            }

            while ($font->firstChild !== null) {
                $parent->insertBefore($font->firstChild, $font);
            }

            $parent->removeChild($font);
        }

        foreach ($wrapper->querySelectorAll('[color]') as $node) {
            $node->removeAttribute('color');
        }

        foreach ($wrapper->querySelectorAll('[bgcolor]') as $node) {
            $node->removeAttribute('bgcolor');
        }

        foreach ($wrapper->querySelectorAll('[text]') as $node) {
            $node->removeAttribute('text');
        }

        foreach ($wrapper->querySelectorAll('[style]') as $node) {
            $style = (string)$node->getAttribute('style');
            $style = (string)preg_replace(pattern: self::COLOR_STYLE_PATTERN, replacement: '', subject: $style);
            $style = trim(trim($style), ';');

            if ($style === '') {
                $node->removeAttribute('style');
            } else {
                $node->setAttribute('style', $style);
            }
        }

        foreach ($wrapper->querySelectorAll('img') as $img) {
            $src = (string)$img->getAttribute('src');

            if ($src !== '') {
                $img->setAttribute('src', $this->absoluteUrl($src));
            }

            $img->setAttribute('style', self::CSS['img']);
            $img->setAttribute('loading', 'lazy');
            $img->removeAttribute('align');

            $parent = $img->parentElement;

            if ($parent !== null && $parent->localName === 'a' && trim($parent->textContent ?? '') === '') {
                $parent->setAttribute('style', self::CSS['imglink']);
            }
        }

        foreach ($wrapper->querySelectorAll('a[href]') as $link) {
            $href = (string)$link->getAttribute('href');

            if ($href !== '' && str_starts_with($href, '#') === false) {
                $link->setAttribute('href', $this->absoluteUrl($href));
            }

            $link->setAttribute('rel', 'noopener');
        }

        foreach ($wrapper->querySelectorAll('p') as $paragraph) {
            $paragraph->setAttribute('style', self::CSS['para']);
        }

        foreach ($wrapper->querySelectorAll('pre') as $pre) {
            $pre->setAttribute('style', self::CSS['code']);
        }

        foreach ($wrapper->querySelectorAll('center') as $center) {
            $block = $fragment->createElement('div');
            $block->setAttribute('style', self::CSS['figure']);

            while ($center->firstChild !== null) {
                $block->appendChild($center->firstChild);
            }

            $center->parentNode?->replaceChild($block, $center);
        }

        $body = break_annoying_html_tags($wrapper->innerHTML);
        $textStyle = self::CSS['text'];

        return "<div style=\"{$textStyle}\">{$body}</div>";
    }

    private function workId(): ?string
    {
        $value = trim((string)$this->getInput('work'));

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value) === true) {
            return $value;
        }

        if (preg_match(pattern: '#^(\d+)/?$#', subject: $value, matches: $matches) === 1) {
            return $matches[1];
        }

        if (str_contains($value, '://') === true || str_starts_with($value, 'www.') === true) {
            $parts = parse_url($value);

            if (is_array($parts) === false) {
                return null;
            }

            $host = strtolower($parts['host'] ?? '');

            if (in_array($host, ['author.today', 'www.author.today'], true) === false) {
                return null;
            }

            $value = $parts['path'] ?? '';
        }

        if (preg_match(pattern: '#/(?:work|reader)/(\d+)#', subject: $value, matches: $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function absoluteUrl(string $url): string
    {
        return $url
            |> trim(...)
            |> (fn($u) => $u === '' || preg_match(pattern: '#^https?://#i', subject: $u) === 1 ? ($u !== '' ? $u : self::URI) : self::URI . '/' . ltrim($u, '/'))(...);
    }

    private function timestamp(string $value): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $value = (string)preg_replace(pattern: '/\.\d+/', replacement: '', subject: $value);

        if (preg_match(pattern: '/(Z|[+-]\d{2}:?\d{2})$/', subject: $value) !== 1) {
            $value .= 'Z';
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $value);

        if ($date === false) {
            $date = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $value);
        }

        if ($date === false) {
            return null;
        }

        return $date->getTimestamp();
    }

    private function plainText(\Dom\Node $node): string
    {
        $innerHtml = '';
        $doc = $node->ownerDocument;

        foreach ($node->childNodes as $child) {
            if ($doc instanceof \Dom\HTMLDocument) {
                $innerHtml .= $doc->saveHtml($child);
            } elseif ($child instanceof \Dom\Element) {
                $innerHtml .= $child->outerHTML;
            } else {
                $innerHtml .= $child->textContent ?? '';
            }
        }

        $text = html_entity_decode(strip_tags($innerHtml), ENT_QUOTES, 'UTF-8');

        return trim((string)preg_replace(pattern: '/\s+/u', replacement: ' ', subject: $text));
    }

    /**
     * @return list<string>
     */
    private function tags(\Dom\HTMLDocument $html): array
    {
        $tags = [];

        foreach ($html->querySelectorAll('.mb-v-lg .tags a') as $node) {
            if ($node instanceof \Dom\Element === false) {
                continue;
            }
            $tag = $this->plainText($node);

            if ($tag !== '') {
                $tags[] = $tag;
            }
        }

        return array_values(array_unique($tags));
    }

    private function statusIcon(string $class): string
    {
        return match (true) {
            str_contains($class, 'icon-pencil') => '&#9998;',
            str_contains($class, 'icon-check') => '&#10004;',
            default => '&#8226;',
        };
    }

    private function labelStyle(string $class): string
    {
        $color = match (true) {
            str_contains($class, 'label-success') => self::CSS['label_success'],
            str_contains($class, 'label-primary') => self::CSS['label_primary'],
            str_contains($class, 'label-danger') => self::CSS['label_danger'],
            default => self::CSS['label_default'],
        };

        return self::CSS['label'] . ';' . $color;
    }

    private function isInsideFooter(\Dom\Node $node): bool
    {
        $current = $node;
        for ($i = 0; $i < self::FOOTER_SEARCH_DEPTH && $current !== null; $i++) {
            if ($current instanceof \Dom\Element && $current->localName === 'footer') {
                return true;
            }

            $current = $current->parentNode;
        }

        return false;
    }

    private function adultText(\Dom\HTMLDocument $html): string
    {
        foreach ($html->querySelectorAll('.label-adult-only') as $node) {
            if ($node instanceof \Dom\Element === false) {
                continue;
            }
            if ($this->isInsideFooter($node) === true) {
                continue;
            }

            $text = $this->plainText($node);

            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private function likeCount(\Dom\HTMLDocument $html): string
    {
        $source = $html->saveHtml();

        if (preg_match(pattern: '/likeCount["\']?\s*:\s*["\']?(\d+)/i', subject: $source, matches: $matches) === 1) {
            return $matches[1];
        }

        return '';
    }

    private function statusHtml(\Dom\HTMLDocument $html): string
    {
        $label = $html->querySelector('.book-meta-panel .label');
        $time = $html->querySelector('.book-meta-panel [data-format="calendar-short"]');

        if ($label === null && $time === null) {
            return '';
        }

        $parts = [];
        $labelText = $label !== null ? $this->plainText($label) : '';

        if ($labelText !== '') {
            $parts[] = $this->buildLabelSpan($label, $labelText);
        }

        $adultText = $this->adultText($html);

        if ($adultText !== '') {
            $labelStyle = $this->labelStyle('label-danger');
            $escapedAdult = htmlspecialchars($adultText, ENT_QUOTES, 'UTF-8');
            $parts[] = "<span style=\"{$labelStyle}\">{$escapedAdult}</span>";
        }

        $likes = $this->likeCount($html);

        if ($likes !== '') {
            $metaStyle = self::CSS['meta'];
            $escapedLikes = htmlspecialchars($likes, ENT_QUOTES, 'UTF-8');
            $parts[] = "<span style=\"{$metaStyle}\">&#9829;&#160;{$escapedLikes}</span>";
        }

        if ($time !== null) {
            $timeSpan = $this->buildTimeSpan($time);

            if ($timeSpan !== '') {
                $parts[] = $timeSpan;
            }
        }

        $sizeText = $this->extractSizeText($label, $labelText, $adultText);

        if ($sizeText !== '') {
            $escapedSize = htmlspecialchars($sizeText, ENT_QUOTES, 'UTF-8');
            $parts[] = "<span>{$escapedSize}</span>";
        }

        if ($parts === []) {
            return '';
        }

        $separatorStyle = self::CSS['separator'];
        $separator = "<span style=\"{$separatorStyle}\">&#160;|&#160;</span>";

        $statusStyle = self::CSS['status'];

        return "<div style=\"{$statusStyle}\">" . implode($separator, $parts) . '</div>';
    }

    private function buildLabelSpan(\Dom\Element $label, string $labelText): string
    {
        $iconNode = $label->querySelector('i');
        $iconClass = $iconNode !== null ? (string)$iconNode->getAttribute('class') : '';
        $labelClass = (string)$label->getAttribute('class');

        $labelStyle = $this->labelStyle($labelClass);
        $statusIcon = $this->statusIcon($iconClass);
        $escapedLabel = htmlspecialchars($labelText, ENT_QUOTES, 'UTF-8');

        return "<span style=\"{$labelStyle}\">{$statusIcon}&#160;{$escapedLabel}</span>";
    }

    private function buildTimeSpan(\Dom\Element $time): string
    {
        $timestamp = $this->timestamp((string)$time->getAttribute('data-time'));

        if ($timestamp === null) {
            return '';
        }

        $formattedDate = htmlspecialchars(date('d.m.Y H:i', $timestamp), ENT_QUOTES, 'UTF-8');

        return "<span>{$formattedDate}</span>";
    }

    private function extractSizeText(?\Dom\Element $label, string $labelText, string $adultText): string
    {
        $statusNode = $label !== null ? $label->parentElement : null;

        if ($statusNode === null) {
            return '';
        }

        $sizeText = $this->plainText($statusNode);

        if ($sizeText !== '' && $labelText !== '') {
            $sizeText = trim(str_replace($labelText, '', $sizeText), " \f\n\r\t\v\0|");
        }

        if ($sizeText !== '' && $adultText !== '') {
            $sizeText = trim(str_replace($adultText, '', $sizeText), " \f\n\r\t\v\0|");
        }

        return $sizeText;
    }
}
