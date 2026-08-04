<?php

declare(strict_types=1);

class FimfictionBridge extends BridgeAbstract
{
    const MAINTAINER = 'LordArrin';
    const NAME = 'Fimfiction Updates';
    const URI = 'https://www.fimfiction.net/';
    const DESCRIPTION = 'Returns chapter updates for stories on Fimfiction';
    const CACHE_TIMEOUT = 3600;

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
    const CHAPTER_CACHE_TTL = 86400;
    const STORY_CACHE_TTL = 900;

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
        return $id ? self::URI . 'story/' . $id . '/' : self::URI;
    }

    public function getIcon(): string
    {
        return $this->storyImage ?? parent::getIcon();
    }

    public function collectData(): void
    {
        $storyId = $this->getInput('story_id');
        if (!$storyId) {
            throwClientException('Story ID is required');
        }

        $storyUrl = self::URI . 'story/' . $storyId . '/';
        $options = $this->buildProxyOptions();

        try {
            // ќдна строка Ч всЄ остальное делает прокси!
            $dom = getProtectedSimpleHTMLDOM($storyUrl, $options);
        } catch (\Exception $e) {
            throwClientException('Failed to load story: ' . $e->getMessage());
        }

        $storyError = $this->detectStoryError($dom);
        if ($storyError) {
            throwClientException($storyError);
        }

        $this->storyTitle = $this->extractStoryTitle($dom);
        $this->storyImage = $this->extractStoryImage($dom);
        $author = $this->extractAuthor($dom);
        $chaptersData = $this->extractChaptersList($dom, self::FETCH_LIMIT);

        foreach ($chaptersData as $data) {
            $content = $this->getInput('full_content')
                ? $this->buildFullContent($data['uri'], $options)
                : $this->buildLinkContent($data['uri']);

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

    private function buildProxyOptions(): array
    {
        $options = [
            'cookies' => [
                ['name' => 'view_mature', 'value' => 'true', 'domain' => 'www.fimfiction.net'],
            ],
        ];

        $sessionToken = $this->getOption('session_token');
        $signingKey = $this->getOption('signing_key');

        if (!empty($sessionToken) && !empty($signingKey)) {
            $options['cookies'][] = ['name' => 'session_token', 'value' => $sessionToken, 'domain' => 'www.fimfiction.net'];
            $options['cookies'][] = ['name' => 'signing_key', 'value' => $signingKey, 'domain' => 'www.fimfiction.net'];
        }

        return $options;
    }

    private function detectStoryError(\simple_html_dom $dom): ?string
    {
        $errorMessages = [
            'Story not found'         => 'This story has been deleted or does not exist.',
            'This story is not available' => 'This story is not available in your region.',
            'You must be logged in'   => 'This story requires login to view.',
            'Mature Content'          => 'This story contains mature content and requires authentication.',
        ];

        foreach ($errorMessages as $pattern => $message) {
            if (stripos($dom->plaintext, $pattern) !== false) {
                return $message;
            }
        }

        $storyName = $dom->find('a.story_name', 0);
        $chapters = $dom->find('ul.chapters', 0);

        if (!$storyName && !$chapters) {
            return 'Story page loaded but no story content found. The story may be private or restricted.';
        }

        return null;
    }

    private function extractStoryTitle(\simple_html_dom $dom): string
    {
        $titleElem = $dom->find('a.story_name', 0);
        if ($titleElem) {
            return trim($titleElem->plaintext);
        }
        return trim(str_replace(' - Fimfiction', '', $dom->find('title', 0)->plaintext ?? 'Unknown Story'));
    }

    private function extractAuthor(\simple_html_dom $dom): string
    {
        $authorElem = $dom->find('div.info-container a[href*=/user/]', 0) ?? $dom->find('a[href*=/user/]', 0);
        if ($authorElem) {
            return trim($authorElem->plaintext);
        }
        return 'Unknown';
    }

    private function extractStoryImage(\simple_html_dom $dom): ?string
    {
        $container = $dom->find('[class*=story_container__story_image]', 0);
        if (!$container) {
            return null;
        }

        $img = $container->find('img', 0);
        return ($img && $img->src) ? $img->src : null;
    }

    private function extractChaptersList(\simple_html_dom $dom, int $limit): array
    {
        $chapterList = $dom->find('ul.chapters', 0);
        if (!$chapterList) {
            throwClientException('Could not find chapter list (ul.chapters)');
        }

        $chapters = [];
        $index = 1;

        foreach ($chapterList->find('li') as $chapter) {
            $titleBox = $chapter->find('.title-box', 0);
            $link = $titleBox ? $titleBox->find('a.chapter-title', 0) : null;

            if (!$link) {
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
        $dateElem = $chapterElem ? $chapterElem->find('.title-box .date', 0) : null;

        if (!$dateElem) {
            return time();
        }

        $fullText = $dateElem->plaintext;

        if (preg_match('/(\d{1,2})(?:st|nd|rd|th)\s+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+(\d{4})/', $fullText, $matches)) {
            $timestamp = strtotime("{$matches[1]} {$matches[2]} {$matches[3]}");
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        return time();
    }

    private function buildFullContent(string $uri, array $options): string
    {
        try {
            $dom = getProtectedSimpleHTMLDOM($uri, $options);
        } catch (\Exception $e) {
            return '<p style="' . self::CSS['error'] . '">Error loading chapter: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }

        $content = '<div style="' . self::CSS['wrapper'] . '">';
        $body = $dom->find('#chapter-body .bbcode', 0);

        if ($body) {
            $this->sanitizeContent($body);
            $this->styleSceneBreaks($body);
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
        $dangerousTags = ['script', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select'];
        foreach ($dangerousTags as $tag) {
            foreach ($element->find($tag) as $dangerous) {
                $dangerous->outertext = '';
            }
        }

        foreach ($element->find('*') as $node) {
            $attributes = $node->getAllAttributes();
            if ($attributes) {
                foreach (array_keys($attributes) as $attr) {
                    if (stripos($attr, 'on') === 0) {
                        $node->removeAttribute($attr);
                    }
                    if (in_array(strtolower($attr), ['href', 'src'])) {
                        $value = $node->getAttribute($attr);
                        if ($value && stripos(trim($value), 'javascript:') === 0) {
                            $node->removeAttribute($attr);
                        }
                    }
                }
            }
        }
    }

    private function styleSceneBreaks(\simple_html_dom_node $element): void
    {
        foreach ($element->find('hr') as $hr) {
            $hr->outertext = '<p style="' . self::CSS['scene-break'] . '">Х Х Х</p>';
        }
    }
}