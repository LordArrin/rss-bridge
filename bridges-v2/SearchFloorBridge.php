<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

use function urljoin;

final class SearchFloorBridge extends BridgeAbstract
{
    public const NAME = 'SearchFloor';
    public const URI = 'https://searchfloor.org';
    public const DESCRIPTION = 'Returns updates to all books by an author, a single book by ID, or shared Boosty posts by an author';
    public const MAINTAINER = 'LordArrin';
    public const CACHE_TIMEOUT = 3600;

    private const BOOSTY_CACHE_TTL = 86400;
    private const COVER_CACHE_TTL = 604800;

    public const PARAMETERS = [
        'Author' => [
            'author' => [
                'name' => 'Author',
                'required' => true,
                'exampleValue' => 'Boroda',
            ],
            'limit' => [
                'name' => 'Number of books in feed',
                'type' => 'number',
                'required' => false,
                'exampleValue' => 5,
                'defaultValue' => 5,
            ],
        ],
        'Work' => [
            'book_id' => [
                'name' => 'Book ID',
                'required' => true,
                'type' => 'number',
                'exampleValue' => 14144,
            ],
        ],
        'Boosty Shared' => [
            'boosty_author' => [
                'name' => 'Boosty author',
                'required' => true,
                'exampleValue' => 'sedrik-rakot',
                'title' => 'Author nickname or link like https://searchfloor.org/boosty/sedrik-rakot',
            ],
            'limit' => [
                'name' => 'Number of posts in feed',
                'type' => 'number',
                'required' => false,
                'exampleValue' => 5,
            ],
            'full_content' => [
                'name' => 'Try to fetch full chapter content',
                'type' => 'checkbox',
                'defaultValue' => false,
                'title' => 'Download the full post text into the feed; by default only a link to the post is included',
            ],
            'filter' => [
                'name' => 'Title filter',
                'type' => 'text',
                'required' => false,
                'exampleValue' => 'Нальфейн*, -*Восход*',
                'title' => 'Comma-separated rules: * is a wildcard, a leading - excludes, + joins parts with AND, \\ escapes special characters. Without * a part matches as a substring, case-insensitive',
            ],
        ],
    ];

    public function getIcon(): string
    {
        return self::URI . '/static/apple-touch-icon.png';
    }

    public function getName(): string
    {
        if ($this->queriedContext === 'Author') {
            return $this->getInputString('author') ?? self::NAME;
        }

        if ($this->queriedContext === 'Work') {
            $book = $this->loadWorkBookData();
            if ($book !== null) {
                $suffix = $book['author'] !== '' ? ' - ' . $book['author'] : '';
                return $book['title'] . $suffix;
            }
        }

        if ($this->queriedContext === 'Boosty Shared') {
            $slug = $this->getBoostySlug();
            if ($slug === '') {
                return self::NAME;
            }

            $cached = $this->loadCacheValue('boosty_name_' . $slug);
            $name = is_string($cached) === true && $cached !== '' ? $cached : $slug;

            return $name . ' (Boosty)';
        }

        return self::NAME;
    }

    public function getURI(): string
    {
        if ($this->queriedContext === 'Author') {
            $slug = $this->getInputString('author');
            return $slug !== null ? self::URI . '/a/' . rawurlencode($slug) : self::URI;
        }

        if ($this->queriedContext === 'Work') {
            return self::URI . '/b/' . $this->getInputInt('book_id');
        }

        if ($this->queriedContext === 'Boosty Shared') {
            $slug = $this->getBoostySlug();
            return $slug !== '' ? self::URI . '/boosty/' . rawurlencode($slug) : self::URI;
        }

        return self::URI;
    }

    public function collectData(): void
    {
        match ($this->queriedContext) {
            'Author' => $this->collectAuthor(),
            'Work' => $this->collectWork(),
            'Boosty Shared' => $this->collectBoostyShared(),
            default => throw new \Exception('Unknown context.'),
        };
    }

    private function collectAuthor(): void
    {
        $author = (string) $this->getInputString('author');
        $limit = $this->getInputInt('limit', 10);

        $html = $this->loadHtml($this->getURI(), 'Failed to load author page.');
        $books = $this->extractBooks($html);

        if ($books === []) {
            throw new \Exception('No books found on the author page.');
        }

        $books = $this->sortByDateDesc($books);
        $books = array_slice($books, 0, $limit);

        foreach ($books as $book) {
            $this->items[] = $this->buildItem($book, $author);
        }
    }

    private function collectWork(): void
    {
        $book = $this->loadWorkBookData();

        if ($book === null) {
            throw new \Exception('Failed to load book page or extract book data.');
        }

        $this->items[] = $this->buildItem($book, $book['author']);
    }

    private function collectBoostyShared(): void
    {
        $slug = $this->getBoostySlug();
        if ($slug === '') {
            throwClientException('Invalid Boosty author: use a nickname or a https://searchfloor.org/boosty/... link.');
        }

        $limit = $this->getInputInt('limit', 10);
        $limit = min(50, max(1, $limit));
        $fullContent = (bool) $this->getInput('full_content');
        $filter = trim((string) $this->getInput('filter'));

        $baseUri = self::URI . '/boosty/' . rawurlencode($slug);
        $dom = getSimpleHTMLDOM($baseUri);

        $authorName = trim($dom->querySelector('h1')->textContent ?? '');
        if ($authorName !== '') {
            $this->saveCacheValue('boosty_name_' . $slug, $authorName, self::BOOSTY_CACHE_TTL);
        }

        $posts = [];
        $seen = [];
        $page = 1;

        while (true) {
            $batch = $this->extractBoostyPosts($dom);
            if ($batch === []) {
                break;
            }

            $added = 0;
            foreach ($batch as $post) {
                if (in_array($post['id'], $seen, true) === true) {
                    continue;
                }

                $seen[] = $post['id'];
                $added++;
                if ($filter === '' || matches_keyword_rules($post['title'], $filter) === true) {
                    $posts[] = $post;
                }
            }

            if ($added === 0 || count($posts) >= $limit || $page >= 10) {
                break;
            }

            $nextAttr = $dom->querySelector('#btn-next-page')?->getAttribute('data-page');
            if ($nextAttr === null || preg_match('/^\d+$/', $nextAttr) !== 1) {
                break;
            }

            $next = (int) $nextAttr;
            if ($next <= $page) {
                break;
            }

            try {
                $fragment = (string) getContents($baseUri . '?page=' . $next);
            } catch (\Throwable) {
                break;
            }

            if (strlen(trim($fragment)) <= 10) {
                break;
            }

            $dom = parseHtmlDocument($fragment);
            $page = $next;
        }

        if ($posts === []) {
            throwServerException('No Boosty posts found for this author. Check the nickname and the title filter.');
        }

        usort($posts, static fn (array $a, array $b): int => ($b['date'] ?? 0) <=> ($a['date'] ?? 0));
        $posts = array_slice($posts, 0, $limit);

        foreach ($posts as $post) {
            $content = '<p><a href="' . e($post['uri']) . '">Read on SearchFloor</a></p>';
            if ($fullContent === true) {
                $full = $this->fetchBoostyPostContent($post['uri']);
                if ($full !== '') {
                    $content = $full;
                }
            }

            $this->items[] = [
                'uri' => $post['uri'],
                'title' => $post['title'],
                'timestamp' => $post['date'] ?? time(),
                'author' => $authorName !== '' ? $authorName : $slug,
                'uid' => 'boosty-' . $post['id'],
                'categories' => $post['tags'],
                'content' => $content,
            ];
        }
    }

    private function getBoostySlug(): string
    {
        $input = trim((string) $this->getInput('boosty_author'));
        if ($input === '') {
            return '';
        }

        if (str_contains($input, '/') === true) {
            if (preg_match('#(?:^|/)boosty/([A-Za-z0-9_.-]+)#', $input, $match) === 1) {
                return $match[1];
            }

            return '';
        }

        return preg_match('/^[A-Za-z0-9_.-]+$/', $input) === 1 ? $input : '';
    }

    /**
     * @return list<array{id: int, title: string, uri: string, date: int|null, tags: list<string>}>
     */
    private function extractBoostyPosts(\Dom\HTMLDocument $dom): array
    {
        $posts = [];

        foreach ($dom->querySelectorAll('div.card') as $card) {
            $rawId = $card->getAttribute('id') ?? '';
            if (preg_match('/^\d+$/', $rawId) !== 1) {
                continue;
            }

            $title = strip_invisible_unicode(trim($card->querySelector('h5.card-title')->textContent ?? ''));
            if ($title === '') {
                continue;
            }

            $date = null;
            $dateAttr = $card->querySelector('p.date[data-date]')?->getAttribute('data-date');
            if ($dateAttr !== null && $dateAttr !== '') {
                $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $dateAttr, new \DateTimeZone('Europe/Moscow'));
                if ($parsed !== false) {
                    $date = $parsed->getTimestamp();
                }
            }

            $tags = [];
            foreach ($card->querySelectorAll('#post_tags a') as $tagNode) {
                $tag = trim($tagNode->textContent ?? '');
                if ($tag !== '' && in_array($tag, $tags, true) === false) {
                    $tags[] = $tag;
                }
            }

            $posts[] = [
                'id' => (int) $rawId,
                'title' => $title,
                'uri' => self::URI . '/boosty/post/' . $rawId,
                'date' => $date,
                'tags' => $tags,
            ];
        }

        return $posts;
    }

    private function fetchBoostyPostContent(string $postUri): string
    {
        $cacheKey = 'boosty_post_' . md5($postUri);
        $cached = $this->loadCacheValue($cacheKey);
        if (is_string($cached) === true && $cached !== '') {
            return $cached;
        }

        try {
            $dom = getSimpleHTMLDOM($postUri);
        } catch (\Throwable) {
            return '';
        }

        $node = $dom->querySelector('#postContent');
        if ($node === null) {
            return '';
        }

        foreach ($node->querySelectorAll('img[src]') as $img) {
            $src = $img->getAttribute('src') ?? '';
            if ($src !== '' && str_starts_with($src, 'http') === false && str_starts_with($src, 'data:') === false) {
                $img->setAttribute('src', urljoin($postUri, $src));
            }
        }

        foreach ($node->querySelectorAll('a[href]') as $anchor) {
            $href = $anchor->getAttribute('href') ?? '';
            if ($href !== '' && str_starts_with($href, 'http') === false && str_starts_with($href, '#') === false && str_starts_with($href, 'mailto:') === false) {
                $anchor->setAttribute('href', urljoin($postUri, $href));
            }
        }

        $content = break_annoying_html_tags($node->innerHTML);
        if ($content !== '') {
            $this->saveCacheValue($cacheKey, $content, self::BOOSTY_CACHE_TTL);
        }

        return $content;
    }

    private function loadHtml(string $url, string $errorMessage): \Dom\HTMLDocument
    {
        $html = getContents($url);
        if ($html === '') {
            throw new \Exception($errorMessage);
        }

        $dom = parseHtmlDocument($html);

        return $dom;
    }

    /**
     * @return array{id: int, title: string, author: string, uri: string, date: int|null}|null
     */
    private function loadWorkBookData(): ?array
    {
        $bookId = $this->getInputInt('book_id');
        $url = self::URI . '/b/' . $bookId;
        $html = $this->loadHtml($url, 'Failed to load book page.');

        return $this->extractBookFromPage($html, $bookId);
    }

    /**
     * @return list<array{id: int, title: string, uri: string, date: int|null}>
     */
    private function extractBooks(\Dom\HTMLDocument $html): array
    {
        $books = [];

        foreach ($html->querySelectorAll('div.series-item') as $node) {
            $linkNode = $node->querySelector('p.mb-0.fw-medium a');
            if ($linkNode === null) {
                continue;
            }

            $href = (string) $linkNode->getAttribute('href');
            if (preg_match('/\/b\/(\d+)/', $href, $match) === 1) {
                $dateNode = $node->querySelector('span.date[data-date]');
                $dateAttr = $dateNode !== null ? $dateNode->getAttribute('data-date') : null;
                $timestamp = $dateAttr !== null ? strtotime($dateAttr) : null;

                $books[] = [
                    'id' => (int) $match[1],
                    'title' => $this->decodeEntities(trim((string) $linkNode->textContent)),
                    'uri' => urljoin(self::URI, $href),
                    'date' => $timestamp !== false ? $timestamp : null,
                ];
            }
        }

        return $books;
    }

    /**
     * @return array{id: int, title: string, author: string, uri: string, date: int|null}|null
     */
    private function extractBookFromPage(\Dom\HTMLDocument $html, int $bookId): ?array
    {
        $titleNode = $html->querySelector('title');
        if ($titleNode === null) {
            return null;
        }

        $rawTitle = $this->decodeEntities(trim((string) $titleNode->textContent));
        $parts = explode('/', $rawTitle, 2);
        $title = trim($parts[0]);
        $author = trim($parts[1] ?? '');

        if ($title === '') {
            return null;
        }

        $dateNode = $html->querySelector('span.badge.date[data-date]');
        $dateAttr = $dateNode !== null ? $dateNode->getAttribute('data-date') : null;
        $timestamp = $dateAttr !== null ? strtotime($dateAttr) : null;

        return [
            'id' => $bookId,
            'title' => $title,
            'author' => $author,
            'uri' => self::URI . '/b/' . $bookId,
            'date' => $timestamp !== false ? $timestamp : null,
        ];
    }

    /**
     * @param list<array{id: int, title: string, uri: string, date: int|null}> $books
     * @return list<array{id: int, title: string, uri: string, date: int|null}>
     */
    private function sortByDateDesc(array $books): array
    {
        usort($books, static fn (array $a, array $b): int => ($b['date'] ?? 0) <=> ($a['date'] ?? 0));
        return $books;
    }

    /**
     * @param array{id: int, title: string, uri: string, date: int|null} $book
     * @return array{uri: string, title: string, timestamp: int, author: string, content: string}
     */
    private function buildItem(array $book, string $author): array
    {
        $meta = $this->fetchBookMeta($book['id']);
        $coverUri = media_embed_url_to_data_uri(
            self::URI . '/cover/' . $book['id'],
            $this->cache,
            self::COVER_CACHE_TTL,
            media_embed_parse_size('2m'),
            null,
            $this->logger,
            1
        );
        $readerLink = self::URI . '/book-reader/' . $book['id'];

        return [
            'uri' => $book['uri'],
            'title' => $this->buildItemTitle($book['title'], $meta['chapter']),
            'timestamp' => $book['date'] ?? time(),
            'author' => $author,
            'content' => $this->buildItemContent($book['title'], $meta['description'], $readerLink, $coverUri),
        ];
    }

    /**
     * @return array{description: string, chapter: string}
     */
    private function fetchBookMeta(int $bookId): array
    {
        $cacheKey = 'searchfloor_book_meta_' . $bookId;

        $cached = $this->loadCacheValue($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $url = self::URI . '/b/' . $bookId;

        try {
            $htmlContent = getContents($url);
            if ($htmlContent === '') {
                return ['description' => '', 'chapter' => ''];
            }

            $html = parseHtmlDocument($htmlContent);
        } catch (\Throwable) {
            return ['description' => '', 'chapter' => ''];
        }

        $metaNode = $html->querySelector('meta[name="description"]');
        $description = $metaNode !== null ? trim((string) $metaNode->getAttribute('content')) : '';

        $meta = [
            'description' => $description,
            'chapter' => $this->findChapter($html),
        ];

        $this->saveCacheValue($cacheKey, $meta, self::CACHE_TIMEOUT);

        return $meta;
    }

    private function findChapter(\Dom\HTMLDocument $html): string
    {
        $chapterNode = $html->querySelector('.alert.alert-warning.alert-dismissible.fade.show') ?? $html->querySelector('[data-bs-title="Последняя глава"]');

        return $chapterNode !== null ? trim((string) $chapterNode->textContent) : '';
    }

    private function buildItemTitle(string $bookTitle, string $chapter): string
    {
        $prefix = $chapter !== '' ? $chapter : 'New chapter';
        return $prefix . ' - ' . $bookTitle;
    }

    private function buildItemContent(string $bookTitle, string $description, string $readerLink, string $coverUri): string
    {
        $parts = [];

        if ($coverUri !== '') {
            $parts[] = sprintf('<img src="%s" alt="%s" />', e($coverUri), $this->escape($bookTitle));
        }
        if ($description !== '') {
            $parts[] = '<p>' . $this->escape($description) . '</p>';
        }
        $parts[] = '<p><a href="' . $this->escape($readerLink) . '">Read online</a></p>';

        return implode(' ', $parts);
    }

    private function decodeEntities(string $text): string
    {
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function escape(string $text): string
    {
        $decoded = $this->decodeEntities($text);
        return htmlspecialchars($decoded, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function getInputString(string $key): ?string
    {
        $value = $this->getInput($key);
        return $value !== null ? (string) $value : null;
    }

    private function getInputInt(string $key, int $default = 0): int
    {
        $value = $this->getInput($key);
        return $value !== null ? (int) $value : $default;
    }
}
