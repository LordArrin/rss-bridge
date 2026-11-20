<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;
use RSSBridge\VKClient;

final class VkVideoBridge extends BridgeAbstract
{
    public const NAME = 'VK Video';
    public const URI = 'https://vkvideo.ru';
    public const DESCRIPTION = 'Returns videos from a VK Video channel or playlist';
    public const MAINTAINER = 'LordArrin';
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
    private const API_DENIAL_TTL = 3600;

    private ?string $feedName = null;
    private ?VKClient $vkClient = null;

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

    /**
     * @return array<string, string>|null
     */
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
        $apiItems = $this->tryCollectViaApi();
        if ($apiItems !== null) {
            $this->items = $apiItems;
            return;
        }

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

    private function vk(): VKClient
    {
        if ($this->vkClient === null) {
            if ($this->cache === null || $this->logger === null) {
                throw new \RuntimeException('Cache and logger are required for VK API requests');
            }
            $this->vkClient = new VKClient($this->cache, $this->logger);
        }
        return $this->vkClient;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function tryCollectViaApi(): ?array
    {
        if ($this->loadCacheValue('vkvideo_api_denied') === true) {
            return null;
        }

        $skipDescription = (bool) $this->getInput('skip_description');

        try {
            $client = $this->vk();
            if ($client->hasToken() === false) {
                return null;
            }

            $channelInput = $this->getInput('u');
            if (is_string($channelInput) === true && $channelInput !== '') {
                $handle = ltrim($this->extractChannelHandle($channelInput), '@');
                $owner = $client->resolveOwner($handle);
                $this->feedName = $owner['name'] !== '' ? $owner['name'] : null;
                $videos = $client->fetchVideos($owner['ownerId']);
                $items = $this->buildApiItems($videos['items'], $skipDescription);
                if ($items === []) {
                    return null;
                }
                $this->saveCacheValue('vkvideo_api_denied', false, self::API_DENIAL_TTL);
                return $items;
            }

            $playlistInput = (string) $this->getInput('p');
            $parts = $this->splitPlaylistId($this->extractPlaylistId($playlistInput));
            if ($parts === null) {
                return null;
            }

            [$ownerId, $albumId] = $parts;
            $videos = $client->fetchVideos($ownerId, $albumId);
            $items = $this->buildApiItems($videos['items'], $skipDescription);
            if ($items === []) {
                return null;
            }
            $this->saveCacheValue('vkvideo_api_denied', false, self::API_DENIAL_TTL);
            return $items;
        } catch (\Throwable) {
            $this->saveCacheValue('vkvideo_api_denied', true, self::API_DENIAL_TTL);
            if ($this->logger !== null) {
                $this->logger->info('vkvideo: API path unavailable, falling back to HTML scraping');
            }
            return null;
        }
    }

    /**
     * @param list<array<string, mixed>> $videos
     * @return list<array<string, mixed>>
     */
    private function buildApiItems(array $videos, bool $skipDescription): array
    {
        $items = [];
        foreach ($videos as $video) {
            $item = $this->buildApiItem($video, $skipDescription);
            if ($item === null) {
                continue;
            }
            $uri = (string) $item['uri'];
            if (isset($items[$uri]) === true) {
                continue;
            }
            $items[$uri] = $item;
        }

        usort($items, static function (array $a, array $b): int {
            return ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0);
        });

        return $items;
    }

    /**
     * @param array<string, mixed> $video
     * @return array<string, mixed>|null
     */
    private function buildApiItem(array $video, bool $skipDescription): ?array
    {
        $ownerId = $video['owner_id'] ?? null;
        $id = $video['id'] ?? null;
        if (is_int($ownerId) === false || is_int($id) === false || $id === 0) {
            return null;
        }

        $uri = self::URI . '/video' . $ownerId . '_' . $id;
        $accessKey = $video['access_key'] ?? null;
        if (is_string($accessKey) === true && $accessKey !== '') {
            $uri .= '?access_key=' . urlencode($accessKey);
        }

        $title = trim((string) ($video['title'] ?? ''));
        if ($title === '') {
            $title = 'Untitled';
        }

        $description = (string) ($video['description'] ?? '');
        $categories = $this->extractHashtags($description);
        if ($description !== '') {
            $description = $this->removeHashtags($description);
        }

        $images = $video['image'] ?? [];
        $thumbnail = $this->largestImage(is_array($images) === true ? $images : []);

        $content = '';
        if ($thumbnail !== '') {
            $imgStyle = sprintf('max-width: %dpx; width: auto;', self::MAX_IMAGE_WIDTH);
            $content .= sprintf(
                '<p><a href="%s"><img src="%s" style="%s"></a></p>',
                htmlspecialchars($uri, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($thumbnail, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($imgStyle, ENT_QUOTES, 'UTF-8')
            );
        }

        if ($skipDescription === false && $description !== '') {
            $content .= '<p>' . $this->formatDescription($this->linkifyUrls($description)) . '</p>';
        }

        $date = $video['date'] ?? null;
        $timestamp = is_int($date) === true && $date > 0 ? $date : null;

        return [
            'uri' => $uri,
            'title' => $title,
            'timestamp' => $timestamp,
            'content' => $content !== '' ? $content : htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
            'categories' => $categories,
            'uid' => 'vk:video' . $ownerId . '_' . $id,
        ];
    }

    /**
     * @param array<array-key, mixed> $images
     */
    private function largestImage(array $images): string
    {
        $best = '';
        $bestWidth = 0;
        foreach ($images as $image) {
            if (is_array($image) === false) {
                continue;
            }
            $url = $image['url'] ?? null;
            $width = $image['width'] ?? null;
            if (is_string($url) === true && $url !== '' && is_int($width) === true && $width > $bestWidth) {
                $bestWidth = $width;
                $best = $url;
            }
        }
        return $best;
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function splitPlaylistId(string $input): ?array
    {
        if (preg_match('/^(-?\d+)_(-?\d+)$/', $input, $matches) === 1) {
            return [(int) $matches[1], (int) $matches[2]];
        }
        return null;
    }

    /**
     * @param array<string, mixed> $gallery
     * @return list<array<string, mixed>>
     */
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

        return $items;
    }

    /**
     * @param array<string, mixed> $gallery
     * @return list<array<string, mixed>>
     */
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

    /**
     * @param array<string, mixed> $video
     * @return array<string, mixed>
     */
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
                htmlspecialchars($uri, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($thumbnail, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($imgStyle, ENT_QUOTES, 'UTF-8')
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
            'content' => $content !== '' ? $content : htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
            'categories' => $categories,
        ];
    }

    private function linkifyUrls(string $text): string
    {
        $pattern = '/(https?:\/\/[^\s<>"\']+)/iu';

        $result = preg_replace_callback($pattern, function (array $matches): string {
            $url = $matches[1];
            return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '</a>';
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
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        }

        $result = '';
        foreach ($parts as $part) {
            if (preg_match('/^<a[^>]*>.*<\/a>$/i', $part) === 1) {
                $result .= $part;
            } else {
                $escaped = htmlspecialchars($part, ENT_QUOTES, 'UTF-8');
                $result .= nl2br($escaped, false);
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
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

    /**
     * @return array<string, mixed>|null
     */
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
