<?php

declare(strict_types=1);

enum ProxyProfile: string
{
    case FlareSolverr = 'flaresolverr';
}

enum ChapterFetchMode: int
{
    case LinkOnly = 0;
    case FullContent = 1;
}

class FimfictionBridge extends BridgeAbstract
{
    const MAINTAINER = 'LordArrin';
    const NAME = 'Fimfiction Updates';
    const URI = 'https://www.fimfiction.net/';
    const DESCRIPTION = 'Returns chapter updates for stories on Fimfiction';
    const CACHE_TIMEOUT = 3600;

    private const PROXY_PROFILE = ProxyProfile::FlareSolverr;
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY_US = 500000; // 0.5 секунды

    const CONFIGURATION = [
        'session_token' => ['required' => false],
        'signing_key' => ['required' => false],
    ];

    const PARAMETERS = [
        [
            'story_id' => [
                'name' => 'Story ID',
                'type' => 'text',
                'required' => true,
                'exampleValue' => '550684'
            ],
            'full_content' => [
                'name' => 'Fetch full chapter content',
                'type' => 'checkbox',
                'defaultValue' => false,
            ],
        ]
    ];

    const FETCH_LIMIT = 3;

    private const CSS = [
        'wrapper'      => 'font-size:14px; line-height:1.6; word-wrap:break-word;',
        'scene-break'  => 'text-align:center; color:#888; margin:1.5em 0; font-size:1.2em; letter-spacing:0.5em;',
        'chapter-link' => 'margin:0.5em 0;',
        'error'        => 'color:#b00; font-style:italic;',
    ];

    private ?string $storyTitle = null;
    private ?string $storyImage = null;

    public function getName(): string
    {
        return $this->storyTitle ?? parent::getName();
    }

    public function getURI(): string
    {
        $id = $this->getInput('story_id');
        return $id === '' ? self::URI : self::URI . 'story/' . $id . '/';
    }

    public function getIcon(): string
    {
        return $this->storyImage ?? parent::getIcon();
    }

    public function collectData(): void
    {
        $storyId = $this->getInput('story_id');
        if ($storyId === '' || $storyId === null) {
            throwClientException('Story ID is required');
        }

        $storyUrl = self::URI . 'story/' . $storyId . '/';
        $options = $this->buildProxyOptions();

        $dom = $this->fetchWithRetry(url: $storyUrl, options: $options);

        if ($this->validateStoryPage($dom) === false) {
            throwClientException(
                'Received invalid story page structure. The story may be private, ' .
                'deleted, or the proxy returned unexpected content.'
            );
        }

        $storyError = $this->detectStoryError($dom);
        if ($storyError !== null) {
            throwClientException($storyError);
        }

        $this->storyTitle = $this->extractStoryTitle($dom);
        $this->storyImage = $this->extractStoryImage($dom);
        $author = $this->extractAuthor($dom);
        $chaptersData = $this->extractChaptersList($dom, self::FETCH_LIMIT);

        $fetchMode = $this->getInput('full_content') === true
            ? ChapterFetchMode::FullContent
            : ChapterFetchMode::LinkOnly;

        foreach ($chaptersData as $data) {
            $content = match ($fetchMode) {
                ChapterFetchMode::FullContent => $this->buildFullContent(uri: $data['uri'], options: $options),
                ChapterFetchMode::LinkOnly => $this->buildLinkContent(uri: $data['uri']),
            };

            $this->items[] = [
                'title'     => $data['title'],
                'uri'       => $data['uri'],
                'author'    => $author,
                'content'   => $content,
                'timestamp' => $data['timestamp'],
                'uid'       => $data['uri'],
            ];
        }
    }

    private function fetchWithRetry(string $url, array $options): \simple_html_dom
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                return getProtectedSimpleHTMLDOM(
                    $url,
                    self::PROXY_PROFILE->value,
                    $options
                );
            } catch (\Exception $e) {
                $lastException = $e;

                if ($attempt < self::MAX_RETRIES) {
                    $delay = self::RETRY_DELAY_US * (2 ** ($attempt - 1));
                    usleep($delay);
                }
            }
        }

        throw $lastException;
    }

    private function buildProxyOptions(): array
    {
        $options = [
            'cookies' => [
                ['name' => 'view_mature', 'value' => 'true', 'domain' => 'www.fimfiction.net'],
            ],
            'cache_ttl' => 900,
        ];

        $sessionToken = $this->getOption('session_token');
        $signingKey = $this->getOption('signing_key');

        if ($sessionToken !== null && $sessionToken !== '' && $signingKey !== null && $signingKey !== '') {
            $options['cookies'][] = ['name' => 'session_token', 'value' => $sessionToken, 'domain' => 'www.fimfiction.net'];
            $options['cookies'][] = ['name' => 'signing_key', 'value' => $signingKey, 'domain' => 'www.fimfiction.net'];
        }

        return $options;
    }

    private function validateStoryPage(\simple_html_dom $dom): bool
    {
        $storyName = $dom->find('a.story_name', 0);
        $chapters = $dom->find('ul.chapters', 0);

        return $storyName !== null || $chapters !== null;
    }

    private function detectStoryError(\simple_html_dom $dom): ?string
    {
        $text = $dom->plaintext;

        return match (true) {
            stripos($text, 'Story not found') !== false => 'This story has been deleted or does not exist.',
            stripos($text, 'This story is not available') !== false => 'This story is not available in your region.',
            stripos($text, 'You must be logged in') !== false => 'This story requires login to view.',
            stripos($text, 'Mature Content') !== false => 'This story contains mature content and requires authentication.',
            default => null,
        };
    }

    private function extractStoryTitle(\simple_html_dom $dom): string
    {
        $title = $dom->find('a.story_name', 0)?->plaintext;
        if ($title !== null && $title !== '') {
            return trim($title);
        }

        $pageTitle = $dom->find('title', 0)?->plaintext ?? 'Unknown Story';
        return trim(str_replace(' - Fimfiction', '', $pageTitle));
    }

    private function extractAuthor(\simple_html_dom $dom): string
    {
        $author = $dom->find('div.info-container a[href*=/user/]', 0)?->plaintext
               ?? $dom->find('a[href*=/user/]', 0)?->plaintext;

        return $author !== null && $author !== '' ? trim($author) : 'Unknown';
    }

    private function extractStoryImage(\simple_html_dom $dom): ?string
    {
        $container = $dom->find('[class*=story_container__story_image]', 0);
        if ($container === null) {
            return null;
        }

        $img = $container->find('img', 0);
        return $img?->src ?: null;
    }

    private function extractChaptersList(\simple_html_dom $dom, int $limit): array
    {
        $chapterList = $dom->find('ul.chapters', 0);
        if ($chapterList === null) {
            throwClientException('Could not find chapter list (ul.chapters)');
        }

        $chapters = [];
        $index = 1;

        foreach ($chapterList->find('li') as $chapter) {
            $titleBox = $chapter->find('.title-box', 0);
            $link = $titleBox?->find('a.chapter-title', 0);

            if ($link === null) {
                $index++;
                continue;
            }

            $uri = $link->href;
            if (strpos($uri, 'http') !== 0) {
                $uri = self::URI . ltrim($uri, '/');
            }

            $chapters[] = [
                'title'     => trim($link->plaintext),
                'uri'       => $uri,
                'timestamp' => $this->extractTimestamp($chapter) + $index,
            ];
            $index++;
        }

        usort($chapters, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);

        return array_slice($chapters, 0, $limit);
    }

    private function extractTimestamp($chapterElem): int
    {
        $dateElem = $chapterElem?->find('.title-box .date', 0);

        if ($dateElem === null) {
            return time();
        }

        $fullText = $dateElem->plaintext;

        if (preg_match('/(\d{1,2})(?:st|nd|rd|th)\s+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+(\d{4})/', $fullText, $matches) === 1) {
            $timestamp = strtotime("{$matches[1]} {$matches[2]} {$matches[3]}");
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        return time();
    }

    private function buildFullContent(string $uri, array $options): string
    {
        $options['cache_ttl'] = 604800;

        try {
            $dom = $this->fetchWithRetry(url: $uri, options: $options);
        } catch (\Exception $e) {
            return '<p style="' . self::CSS['error'] . '">Error loading chapter: '
                . htmlspecialchars($e->getMessage()) . '</p>';
        }

        $content = '<div style="' . self::CSS['wrapper'] . '">';
        $body = $dom->find('#chapter-body .bbcode', 0);

        if ($body !== null) {
            $this->sanitizeContent(element: $body);
            $this->styleSceneBreaks(element: $body);
            $content .= $body->innertext;
        } else {
            $content .= '<p style="' . self::CSS['error'] . '">Chapter content could not be loaded.</p>';
        }

        $content .= '</div>';

        return $content;
    }

    private function buildLinkContent(string $uri): string
    {
        $safeUri = htmlspecialchars($uri, ENT_QUOTES, 'UTF-8');
        return '<div style="' . self::CSS['wrapper'] . '"><p style="'
            . self::CSS['chapter-link'] . '">New chapter published - <a href="'
            . $safeUri . '">read full</a></p></div>';
    }

    private function sanitizeContent(\simple_html_dom_node $element): void
    {
        $content = $element->innertext;

        $element->innertext = $content
            |> $this->removeDangerousTags(...)
            |> $this->removeEventHandlers(...)
            |> $this->removeJavascriptUrls(...);
    }

    private function removeDangerousTags(string $content): string
    {
        $dangerousTags = ['script', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select'];

        foreach ($dangerousTags as $tag) {
            $content = preg_replace(
                '/<' . $tag . '[^>]*>.*?<\/' . $tag . '>/is',
                '',
                $content
            );
            $content = preg_replace(
                '/<' . $tag . '[^>]*\/?>/is',
                '',
                $content
            );
        }

        return $content;
    }

    private function removeEventHandlers(string $content): string
    {
        return preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/i', '', $content);
    }

    private function removeJavascriptUrls(string $content): string
    {
        return preg_replace('/(href|src)\s*=\s*["\']javascript:[^"\']*["\']/i', '$1="#"', $content);
    }

    private function styleSceneBreaks(\simple_html_dom_node $element): void
    {
        foreach ($element->find('hr') as $hr) {
            $hr->outertext = '<p style="' . self::CSS['scene-break'] . '">Х Х Х</p>';
        }
    }
}