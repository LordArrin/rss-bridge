<?php

declare(strict_types=1);

class SearchFloorBridge extends BridgeAbstract
{
    public const MAINTAINER = 'LordArrin';
    public const NAME = 'SearchFloor';
    public const URI = 'https://searchfloor.org';
    public const DESCRIPTION = 'Returns updates to all books by an author or a single book by ID.';
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
        return match ($this->queriedContext) {
            'Author' => ($slug = $this->getInputString('author')) !== null
                ? self::URI . '/a/' . rawurlencode($slug)
                : self::URI,
            'Work' => self::URI . '/b/' . $this->getInputInt('book_id'),
            default => self::URI,
        };
    }

    public function collectData(): void
    {
        match ($this->queriedContext) {
            'Author' => $this->collectAuthor(),
            'Work' => $this->collectWork(),
            default => returnClientError('Unknown context.'),
        };
    }

    private function collectAuthor(): void
    {
        $author = (string) $this->getInputString('author');
        $limit = $this->getInputInt('limit', 10);

        $html = $this->loadHtml($this->getURI(), 'Failed to load author page.');
        $books = $this->extractBooks($html);

        if ($books === []) {
            returnClientError('No books found on the author page.');
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
            returnClientError('Failed to load book page or extract book data.');
        }

        $this->items[] = $this->buildItem($book, $book['author']);
    }

    private function loadHtml(string $url, string $errorMessage): \simple_html_dom
    {
        $html = getSimpleHTMLDOM($url);
        if (!$html) {
            returnClientError($errorMessage);
        }
        return defaultLinkTo($html, self::URI);
    }

    private function loadWorkBookData(): ?array
    {
        $bookId = $this->getInputInt('book_id');
        $url = self::URI . '/b/' . $bookId;
        $html = $this->loadHtml($url, 'Failed to load book page.');

        return $this->extractBookFromPage($html, $bookId);
    }

    private function extractBooks(\simple_html_dom $html): array
    {
        $books = [];

        foreach ($html->find('div.series-item') as $node) {
            $linkNode = $node->find('p.mb-0.fw-medium a', 0);
            if (!$linkNode || !preg_match('/\/b\/(\d+)/', $linkNode->href, $match)) {
                continue;
            }
            $dateNode = $node->find('span.date[data-date]', 0);
            $books[] = [
                'id' => (int) $match[1],
                'title' => $this->decodeEntities(trim($linkNode->plaintext)),
                'uri' => $linkNode->href,
                'date' => $dateNode ? strtotime($dateNode->getAttribute('data-date')) : null,
            ];
        }

        return $books;
    }

    private function extractBookFromPage(\simple_html_dom $html, int $bookId): ?array
    {
        $titleNode = $html->find('title', 0);
        if (!$titleNode) {
            return null;
        }

        $rawTitle = $this->decodeEntities(trim($titleNode->plaintext));
        $parts = explode('/', $rawTitle, 2);
        $title = trim($parts[0]);
        $author = trim($parts[1] ?? '');

        if ($title === '') {
            return null;
        }

        $dateNode = $html->find('span.badge.date[data-date]', 0);

        return [
            'id' => $bookId,
            'title' => $title,
            'author' => $author,
            'uri' => self::URI . '/b/' . $bookId,
            'date' => $dateNode ? strtotime($dateNode->getAttribute('data-date')) : null,
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
            'timestamp' => $book['date'],
            'author' => $author,
            'content' => $this->buildItemContent($book['title'], $meta['description'], $readerLink, $coverDataUri),
            'enclosures' => $coverDataUri !== '' ? [$coverDataUri] : [],
        ];
    }

    private function fetchBookMeta(int $bookId): array
    {
        $url = self::URI . '/b/' . $bookId;
        $html = getSimpleHTMLDOMCached($url);

        if (!$html) {
            return ['description' => '', 'chapter' => ''];
        }

        $html = defaultLinkTo($html, self::URI);

        $metaNode = $html->find('meta[name="description"]', 0);
        $description = ($metaNode?->content) ? trim($metaNode->content) : '';

        return [
            'description' => $description,
            'chapter' => $this->findChapter($html),
        ];
    }

    private function findChapter(\simple_html_dom $html): string
    {
        $chapterNode = $html->find('.alert.alert-warning.alert-dismissible.fade.show', 0)
            ?? $html->find('[data-bs-title="Последняя глава"]', 0);

        return $chapterNode ? trim($chapterNode->plaintext) : '';
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
        if ($data === '' || !function_exists('imagecreatefromstring')) {
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

        if (!$ok || $jpegData === false || $jpegData === '') {
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