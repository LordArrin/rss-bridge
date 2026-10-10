<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

use function RSSBridge\Exceptions\throwServerException;

final class VkVideoBridge extends BridgeAbstract
{
    public const NAME = 'VK Video';
    public const URI = 'https://vkvideo.ru';
    public const DESCRIPTION = 'Returns videos from a VK Video channel or playlist';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        'Channel' => [
            'u' => [
                'name' => 'Channel',
                'exampleValue' => '@thoisoi',
                'required' => true,
            ],
            'skip_description' => [
                'name' => 'Skip full video description',
                'type' => 'checkbox',
                'defaultValue' => false,
                'title' => 'Hide full video description (hashtags will still be extracted)',
            ],
        ],
        'Playlist' => [
            'p' => [
                'name' => 'Playlist',
                'exampleValue' => '-142758151_-4',
                'required' => true,
            ],
            'skip_description' => [
                'name' => 'Skip full video description',
                'type' => 'checkbox',
                'defaultValue' => false,
                'title' => 'Hide full video description (hashtags will still be extracted)',
            ],
        ],
    ];

    private const USER_AGENT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
    private const MAX_IMAGE_WIDTH = 1600;

    private ?string $feedName = null;

    public function getURI(): string
    {
        $channel = $this->getInput('u');
        if (is_string($channel) === true && $channel !== '') {
            return self::URI . '/' . $this->extractChannelHandle($channel);
        }

        $playlist = $this->getInput('p');
        if (is_string($playlist) === true && $playlist !== '') {
            return self::URI . '/playlist/' . $this->extractPlaylistId($playlist);
        }

        return parent::getURI();
    }

    public function getName(): string
    {
        return $this->feedName ?? parent::getName();
    }

    public function detectParameters(string $url): ?array
    {
        if (str_contains($url, '/playlist/') === true) {
            return ['p' => $this->extractPlaylistId($url)];
        }

        if (str_contains($url, '/@') === true) {
            return ['u' => $this->extractChannelHandle($url)];
        }

        return null;
    }

    public function collectData(): void
    {
        $html = $this->fetchHtml($this->getURI());

        if ($html === '') {
            throwServerException('Failed to fetch page content from VK Video');
        }

        $gallery = $this->extractVideoGallery($html);
        if ($gallery === null) {
            throwServerException('Could not find video data on the page. VK Video may have changed their page structure.');
        }

        $channelInput = $this->getInput('u');
        if (is_string($channelInput) === true && $channelInput !== '') {
            $this->feedName = $gallery['name'] ?? null;
            $this->items = $this->collectChannelItems($gallery);
        } else {
            $this->feedName = $this->extractPageTitle($html);
            $this->items = $this->collectPlaylistItems($gallery, $html);
        }
    }

    private function collectChannelItems(array $gallery): array
    {
        $skipDescription = (bool)$this->getInput('skip_description');
        $items = [];
        foreach ($gallery['video'] as $video) {
            $uri = $video['contentUrl'] ?? null;
            if ($uri === null || isset($items[$uri]) === true) {
                continue;
            }
            $items[$uri] = $this->buildItem($video, $uri, $skipDescription);
        }

        usort($items, function (array $a, array $b): int {
            $tsA = $a['timestamp'] ?? 0;
            $tsB = $b['timestamp'] ?? 0;
            return $tsB <=> $tsA;
        });

        return array_values($items);
    }

    private function collectPlaylistItems(array $gallery, string $html): array
    {
        $skipDescription = (bool)$this->getInput('skip_description');
        preg_match_all('#<a[^>]*href="([^"]+)"[^>]*data-video-thumb="true"#', $html, $matches);
        $hrefs = array_values(array_unique($matches[1] ?? []));

        $items = [];
        foreach ($gallery['video'] as $i => $video) {
            if (isset($hrefs[$i]) === false) {
                continue;
            }

            $uri = urljoin(self::URI, $hrefs[$i]);
            if (isset($items[$uri]) === true) {
                continue;
            }

            $items[$uri] = $this->buildItem($video, $uri, $skipDescription);
        }

        return array_values($items);
    }

    private function buildItem(array $video, string $uri, bool $skipDescription): array
    {
        $rawTitle = $video['name'] ?? 'Untitled';
        $thumbnail = $video['thumbnailUrl'] ?? null;
        $rawDescription = $video['description'] ?? '';

        $title = '';
        if (is_string($rawTitle) === true && $rawTitle !== '') {
            $title = html_entity_decode($rawTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if ($title === '') {
            $title = 'Untitled';
        }

        $description = '';
        if (is_string($rawDescription) === true && $rawDescription !== '') {
            $description = html_entity_decode($rawDescription, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if ($description !== '') {
            $description = preg_replace('/<br\s*\/?>/i', "\n", $description);
            if ($description === null) {
                $description = '';
            }
        }

        $categories = $this->extractHashtags($description);

        if ($description !== '') {
            $description = $this->removeHashtags($description);
        }

        $content = '';

        if ($thumbnail !== null && $thumbnail !== '') {
            $imgStyle = sprintf('max-width: %dpx; width: auto;', self::MAX_IMAGE_WIDTH);
            $content .= sprintf(
                '<p><a href="%s"><img src="%s" style="%s"></a></p>',
                e($uri),
                e($thumbnail),
                e($imgStyle)
            );
        }

        if ($skipDescription === false && $description !== '') {
            $descriptionWithLinks = $this->linkifyUrls($description);
            $formattedDescription = $this->formatDescription($descriptionWithLinks);
            $content .= '<p>' . $formattedDescription . '</p>';
        }

        $timestamp = null;
        if (isset($video['uploadDate']) === true && $video['uploadDate'] !== '') {
            $parsedTime = strtotime($video['uploadDate']);
            if ($parsedTime !== false) {
                $timestamp = $parsedTime;
            }
        }

        return [
            'uri' => $uri,
            'title' => $title,
            'timestamp' => $timestamp,
            'content' => $content !== '' ? $content : e($title),
            'categories' => $categories,
        ];
    }

    private function linkifyUrls(string $text): string
    {
        $pattern = '/(https?:\/\/[^\s<>"\']+)/iu';

        $result = preg_replace_callback($pattern, function (array $matches): string {
            $url = $matches[1];
            return '<a href="' . e($url) . '">' . e($url) . '</a>';
        }, $text);

        if ($result === null) {
            return $text;
        }

        return $result;
    }

    private function formatDescription(string $text): string
    {
        $parts = preg_split('/(<a[^>]*>.*?<\/a>)/is', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return e($text);
        }

        $result = '';
        foreach ($parts as $part) {
            if (preg_match('/^<a[^>]*>.*<\/a>$/i', $part) === 1) {
                $result .= $part;
            } else {
                $escaped = e($part);
                $result .= nl2br($escaped, false);
            }
        }

        return $result;
    }

    private function extractHashtags(string $text): array
    {
        if (preg_match_all('/#([\p{L}\p{N}_]+)/u', $text, $matches) === 0) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    private function removeHashtags(string $text): string
    {
        $result = preg_replace('/\s*#[\p{L}\p{N}_]+/u', '', $text);
        if ($result === null) {
            return $text;
        }

        return trim($result);
    }

    private function fetchHtml(string $url): string
    {
        $html = getContents($url, [], [CURLOPT_USERAGENT => self::USER_AGENT]);

        if ($html === '') {
            return '';
        }

        if (preg_match('//u', $html) !== 1) {
            $converted = @mb_convert_encoding($html, 'UTF-8', 'windows-1251');
            if ($converted !== false && $converted !== '') {
                $html = $converted;
            }
        }

        return $html;
    }

    private function extractVideoGallery(string $html): ?array
    {
        if (preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches) === 0) {
            return null;
        }

        foreach ($matches[1] as $json) {
            $data = json_decode($json, true);
            if (is_array($data) === false) {
                continue;
            }

            $type = $data['@type'] ?? null;
            $video = $data['video'] ?? null;

            if ($type === 'VideoGallery' && is_array($video) === true && $video !== []) {
                return $data;
            }
        }

        return null;
    }

    private function extractPageTitle(string $html): ?string
    {
        if (preg_match('#<h1[^>]*>([^<]+)#', $html, $matches) === 1) {
            $title = trim(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'));
            return $title !== '' ? $title : null;
        }

        return null;
    }

    private function extractChannelHandle(string $input): string
    {
        if (preg_match('#vkvideo\.ru/(@[\w.]+)#', $input, $matches) === 1) {
            return $matches[1];
        }

        $input = trim($input);
        return str_starts_with($input, '@') === true ? $input : '@' . $input;
    }

    private function extractPlaylistId(string $input): string
    {
        if (preg_match('#vkvideo\.ru/playlist/([\d_-]+)#', $input, $matches) === 1) {
            return $matches[1];
        }

        return trim($input);
    }
}
