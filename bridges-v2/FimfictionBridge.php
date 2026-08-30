<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class FimfictionBridge extends BridgeAbstract
{
    public const MAINTAINER = 'LordArrin';
    public const NAME = 'Fimfiction Updates';
    public const URI = 'https://www.fimfiction.net/';
    public const DESCRIPTION = 'Returns chapter updates for stories on Fimfiction';
    public const CACHE_TIMEOUT = 3600;

    private const PROXY_PROFILE = 'flaresolverr';
    private const MAX_RETRIES = 5;
    private const RETRY_DELAY_US = 1500000;

    public const CONFIGURATION = [
        'session_token' => ['required' => false],
        'signing_key' => ['required' => false],
    ];

    public const PARAMETERS = [
        [
            'story_id' => [
                'name' => 'Story ID',
                'type' => 'text',
                'required' => true,
                'exampleValue' => '582300',
            ],
            'full_content' => [
                'name' => 'Fetch full chapter content',
                'type' => 'checkbox',
                'defaultValue' => false,
            ],
        ],
    ];

    public const FETCH_LIMIT = 3;

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

        $dom = $this->fetchWithRetry($storyUrl, $options, 'story page');

        if ($this->validateStoryPage($dom) === false) {
            throwClientException('Received invalid story page structure. The story may be private, deleted, or the proxy returned unexpected content.');
        }

        $storyError = $this->detectStoryError($dom);
        if ($storyError !== null) {
            throwClientException($storyError);
        }

        $this->storyTitle = $this->extractStoryTitle($dom);
        $this->storyImage = $this->extractStoryImage($dom);
        $author = $this->extractAuthor($dom);
        $chaptersData = $this->extractChaptersList($dom, self::FETCH_LIMIT);

        foreach ($chaptersData as $data) {
            if ($this->getInput('full_content') === true) {
                $content = $this->buildFullContent($data['uri'], $options);
            } else {
                $content = $this->buildLinkContent($data['uri']);
            }

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

    private function fetchWithRetry(string $url, array $options, string $context = ''): \Dom\HTMLDocument
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $html = getProtectedContents($url, self::PROXY_PROFILE, $options);

                if ($html === '' || $html === null) {
                    throw new \Exception('Received empty HTML response');
                }

                libxml_use_internal_errors(true);
                $dom = \Dom\HTMLDocument::createFromString($html);
                libxml_use_internal_errors(false);

                if ($attempt > 1) {
                    $this->logger->info(sprintf(
                        'FimfictionBridge: %s succeeded on attempt %d/%d for %s',
                        $context,
                        $attempt,
                        self::MAX_RETRIES,
                        $url
                    ));
                }

                return $dom;
            } catch (\Throwable $e) {
                $lastException = $e;

                $this->logger->warning(sprintf(
                    'FimfictionBridge: %s attempt %d/%d failed for %s: %s',
                    $context,
                    $attempt,
                    self::MAX_RETRIES,
                    $url,
                    $e->getMessage()
                ));

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

    private function validateStoryPage(\Dom\HTMLDocument $dom): bool
    {
        $storyName = $dom->querySelector('a.story_name');
        $chapters = $dom->querySelector('ul.chapters');

        return $storyName !== null || $chapters !== null;
    }

    private function detectStoryError(\Dom\HTMLDocument $dom): ?string
    {
        $text = $dom->documentElement?->textContent ?? '';

        return match (true) {
            stripos($text, 'Story not found') !== false => 'This story has been deleted or does not exist.',
            stripos($text, 'This story is not available') !== false => 'This story is not available in your region.',
            stripos($text, 'You must be logged in') !== false => 'This story requires login to view.',
            stripos($text, 'Mature Content') !== false => 'This story contains mature content and requires authentication.',
            default => null,
        };
    }

    private function extractStoryTitle(\Dom\HTMLDocument $dom): string
    {
        $titleNode = $dom->querySelector('a.story_name');
        if ($titleNode !== null) {
            $title = trim($titleNode->textContent);
            if ($title !== '') {
                return $title;
            }
        }

        $pageTitleNode = $dom->querySelector('title');
        $pageTitle = $pageTitleNode !== null ? trim($pageTitleNode->textContent) : 'Unknown Story';
        return str_replace(' - Fimfiction', '', $pageTitle);
    }

    private function extractAuthor(\Dom\HTMLDocument $dom): string
    {
        $authorNode = $dom->querySelector('div.info-container a[href*="/user/"]') ?? $dom->querySelector('a[href*="/user/"]');

        if ($authorNode !== null) {
            $author = trim($authorNode->textContent);
            if ($author !== '') {
                return $author;
            }
        }

        return 'Unknown';
    }

    private function extractStoryImage(\Dom\HTMLDocument $dom): ?string
    {
        $container = $dom->querySelector('[class*="story_container__story_image"]');
        if ($container === null) {
            return null;
        }

        $img = $container->querySelector('img');
        return $img?->getAttribute('src');
    }

    private function extractChaptersList(\Dom\HTMLDocument $dom, int $limit): array
    {
        $chapterList = $dom->querySelector('ul.chapters');
        if ($chapterList === null) {
            throwClientException('Could not find chapter list (ul.chapters)');
        }

        $chapters = [];
        $index = 1;

        foreach ($chapterList->querySelectorAll('li') as $chapter) {
            $titleBox = $chapter->querySelector('.title-box');
            $link = $titleBox?->querySelector('a.chapter-title');

            if ($link === null) {
                $index++;
                continue;
            }

            $uri = $link->getAttribute('href') ?? '';
            if ($uri !== '' && str_starts_with($uri, 'http') === false) {
                $uri = self::URI . ltrim($uri, '/');
            }

            $chapters[] = [
                'title'     => trim($link->textContent),
                'uri'       => $uri,
                'timestamp' => $this->extractTimestamp($chapter) + $index,
            ];
            $index++;
        }

        usort($chapters, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);

        return array_slice($chapters, 0, $limit);
    }

    private function extractTimestamp(?\Dom\Element $chapterElem): int
    {
        if ($chapterElem === null) {
            return time();
        }

        $dateElem = $chapterElem->querySelector('.title-box .date');

        if ($dateElem === null) {
            return time();
        }

        $fullText = $dateElem->textContent;

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
            $dom = $this->fetchWithRetry($uri, $options, 'chapter');
        } catch (\Throwable $e) {
            return '<p style="' . self::CSS['error'] . '">Error loading chapter: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }

        $content = '<div style="' . self::CSS['wrapper'] . '">';
        $body = $dom->querySelector('#chapter-body .bbcode');

        if ($body !== null) {
            $bodyHtml = $body->innerHTML;
            $bodyHtml = $this->sanitizeContent($bodyHtml);
            $bodyHtml = $this->styleSceneBreaks($bodyHtml);
            $content .= $bodyHtml;
        } else {
            $content .= '<p style="' . self::CSS['error'] . '">Chapter content could not be loaded.</p>';
        }

        $content .= '</div>';

        return $content;
    }

    private function buildLinkContent(string $uri): string
    {
        $safeUri = htmlspecialchars($uri, ENT_QUOTES, 'UTF-8');
        return '<div style="' . self::CSS['wrapper'] . '"><p style="' . self::CSS['chapter-link'] . '">New chapter published - <a href="' . $safeUri . '">read full</a></p></div>';
    }

    private function sanitizeContent(string $content): string
    {
        $content = $this->removeDangerousTags($content);
        $content = $this->removeEventHandlers($content);
        $content = $this->removeJavascriptUrls($content);
        return $content;
    }

    private function removeDangerousTags(string $content): string
    {
        $dangerousTags = ['script', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select'];

        foreach ($dangerousTags as $tag) {
            $content = preg_replace('/<' . $tag . '[^>]*>.*?<\/' . $tag . '>/is', '', $content);
            $content = preg_replace('/<' . $tag . '[^>]*\/?>/is', '', $content);
        }

        return $content;
    }

    private function removeEventHandlers(string $content): string
    {
        $result = preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/i', '', $content);
        return $result !== null ? $result : $content;
    }

    private function removeJavascriptUrls(string $content): string
    {
        $result = preg_replace('/(href|src)\s*=\s*["\']javascript:[^"\']*["\']/i', '$1="#"', $content);
        return $result !== null ? $result : $content;
    }

    private function styleSceneBreaks(string $content): string
    {
        $result = preg_replace(
            '/<hr[^>]*>/i',
            '<p style="' . self::CSS['scene-break'] . '">• • •</p>',
            $content
        );
        return $result !== null ? $result : $content;
    }
}
