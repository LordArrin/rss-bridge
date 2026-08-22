<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

use function urljoin;

final class SearchFloorBridge extends BridgeAbstract
{
    public const NAME = 'SearchFloor';
    public const URI = 'https://searchfloor.org';
    public const DESCRIPTION = 'Returns updates to all books by an author or a single book by ID.';
    public const MAINTAINER = 'LordArrin';
    public const CACHE_TIMEOUT = 3600;

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

        return self::URI;
    }

    public function collectData(): void
    {
        match ($this->queriedContext) {
            'Author' => $this->collectAuthor(),
            'Work' => $this->collectWork(),
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

    private function loadHtml(string $url, string $errorMessage): \Dom\HTMLDocument
    {
        $html = getContents($url);
        if ($html === '' || $html === null) {
            throw new \Exception($errorMessage);
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        return $dom;
    }

    private function loadWorkBookData(): ?array
    {
        $bookId = $this->getInputInt('book_id');
        $url = self::URI . '/b/' . $bookId;
        $html = $this->loadHtml($url, 'Failed to load book page.');

        return $this->extractBookFromPage($html, $bookId);
    }

    private function extractBooks(\Dom\HTMLDocument $html): array
    {
        $books = [];

        foreach ($html->querySelectorAll('div.series-item') as $node) {
            $linkNode = $node->querySelector('p.mb-0.fw-medium a');
            if ($linkNode === null) {
                continue;
            }

            $href = (string) $linkNode->getAttribute('href');
            if (preg_match('/\/b\/(\d+)/', $href, $match) === 0) {
                continue;
            }

            $dateNode = $node->querySelector('span.date[data-date]');
            $dateAttr = $dateNode !== null ? $dateNode->getAttribute('data-date') : null;
            $timestamp = $dateAttr !== null ? strtotime($dateAttr) : null;

            $books[] = [
                'id' => (int) $match[1],
                'title' => $this->decodeEntities(trim($linkNode->textContent)),
                'uri' => urljoin(self::URI, $href),
                'date' => $timestamp !== false ? $timestamp : null,
            ];
        }

        return $books;
    }

    private function extractBookFromPage(\Dom\HTMLDocument $html, int $bookId): ?array
    {
        $titleNode = $html->querySelector('title');
        if ($titleNode === null) {
            return null;
        }

        $rawTitle = $this->decodeEntities(trim($titleNode->textContent));
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

    private function sortByDateDesc(array $books): array
    {
        usort($books, static fn (array $a, array $b): int => ($b['date'] ?? 0) <=> ($a['date'] ?? 0));
        return $books;
    }

    private function buildItem(array $book, string $author): array
    {
        $meta = $this->fetchBookMeta($book['id']);
        $coverDataUri = $this->processCover($this->fetchCoverBytes($book['id']));
        $readerLink = self::URI . '/book-reader/' . $book['id'];

        return [
            'uri' => $book['uri'],
            'title' => $this->buildItemTitle($book['title'], $meta['chapter']),
            'timestamp' => $book['date'] ?? time(),
            'author' => $author,
            'content' => $this->buildItemContent($book['title'], $meta['description'], $readerLink, $coverDataUri),
            'enclosures' => $coverDataUri !== '' ? [$coverDataUri] : [],
        ];
    }

    private function fetchBookMeta(int $bookId): array
    {
        $cacheKey = 'searchfloor_book_meta_' . $bookId;
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $url = self::URI . '/b/' . $bookId;

        try {
            $htmlContent = getContents($url);
            if ($htmlContent === '' || $htmlContent === null) {
                return ['description' => '', 'chapter' => ''];
            }

            libxml_use_internal_errors(true);
            $html = \Dom\HTMLDocument::createFromString($htmlContent);
            libxml_use_internal_errors(false);
        } catch (\Throwable) {
            return ['description' => '', 'chapter' => ''];
        }

        $metaNode = $html->querySelector('meta[name="description"]');
        $description = $metaNode !== null ? trim((string) $metaNode->getAttribute('content')) : '';

        $meta = [
            'description' => $description,
            'chapter' => $this->findChapter($html),
        ];

        $this->cache->set($cacheKey, $meta, self::CACHE_TIMEOUT);

        return $meta;
    }

    private function findChapter(\Dom\HTMLDocument $html): string
    {
        $chapterNode = $html->querySelector('.alert.alert-warning.alert-dismissible.fade.show') ?? $html->querySelector('[data-bs-title="Последняя глава"]');

        return $chapterNode !== null ? trim($chapterNode->textContent) : '';
    }

    private function buildItemTitle(string $bookTitle, string $chapter): string
    {
        $prefix = $chapter !== '' ? $chapter : 'New chapter';
        return $prefix . ' - ' . $bookTitle;
    }

    private function buildItemContent(string $bookTitle, string $description, string $readerLink, string $coverDataUri): string
    {
        $parts = [];

        if ($coverDataUri !== '') {
            $parts[] = sprintf('<img src="%s" alt="%s" />', $coverDataUri, $this->escape($bookTitle));
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

    private function fetchCoverBytes(int $bookId): string
    {
        try {
            return getContents(self::URI . '/cover/' . $bookId);
        } catch (\Exception) {
            return '';
        }
    }

    private function detectMime(string $data): string
    {
        $signature = substr($data, 0, 8);
        $prefix = substr($data, 0, 16);

        return match (true) {
            str_starts_with($signature, "\x89PNG") => 'image/png',
            str_starts_with($signature, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($signature, 'GIF') => 'image/gif',
            str_starts_with($signature, 'RIFF') && str_contains($prefix, 'WEBP') => 'image/webp',
            default => 'image/png',
        };
    }

    private function fallbackDataUri(string $data): string
    {
        return 'data:' . $this->detectMime($data) . ';base64,' . base64_encode($data);
    }

    private function processCover(string $data): string
    {
        if ($data === '' || function_exists('imagecreatefromstring') === false) {
            return $data !== '' ? $this->fallbackDataUri($data) : '';
        }

        $source = @imagecreatefromstring($data);
        if ($source === false) {
            return $this->fallbackDataUri($data);
        }

        $origW = imagesx($source);
        $origH = imagesy($source);

        if ($origW < 1 || $origH < 1) {
            imagedestroy($source);
            return $this->fallbackDataUri($data);
        }

        [$newW, $newH] = $this->calculateDimensions($origW, $origH);
        $resized = imagecreatetruecolor($newW, $newH);

        if ($resized === false) {
            imagedestroy($source);
            return $this->fallbackDataUri($data);
        }

        $white = imagecolorallocate($resized, 255, 255, 255);
        imagefill($resized, 0, 0, $white);
        imagecopyresampled($resized, $source, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

        ob_start();
        $ok = imagejpeg($resized, null, 90);
        $jpegData = ob_get_clean();

        imagedestroy($source);
        imagedestroy($resized);

        if ($ok === false || $jpegData === false || $jpegData === '') {
            return $this->fallbackDataUri($data);
        }

        return 'data:image/jpeg;base64,' . base64_encode($jpegData);
    }

    private function calculateDimensions(int $origW, int $origH): array
    {
        if ($origH <= 300) {
            return [$origW, $origH];
        }

        $newW = max(1, (int) round($origW * (300 / $origH)));
        return [$newW, 300];
    }
}
