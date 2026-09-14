<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class MatreshkaBridge extends BridgeAbstract
{
    public const NAME = 'Matreshka.tv';
    public const URI = 'https://matreshka.tv/';
    public const DESCRIPTION = 'Returns latest videos from Matreshka.tv channels and playlists';
    public const MAINTAINER = 'LordArrin';
    public const CACHE_TIMEOUT = 3600;

    private const API_URL = 'https://matreshka.tv/api/v2/video';
    private const CHANNEL_API_URL = 'https://matreshka.tv/api/v2/channel';
    private const PLAYLIST_API_URL = 'https://matreshka.tv/api/v2/playlist';

    private const CSS = [
        'img' => 'display: block; float: none; clear: both; max-width: 1280px; width: auto; height: auto; margin: 16px 0 16px 0; padding: 0;',
        'text' => 'text-align: left;',
    ];

    public const PARAMETERS = [
        'By channel' => [
            'channel' => [
                'name' => 'Channel URL or ID',
                'type' => 'text',
                'required' => true,
                'exampleValue' => 'https://matreshka.tv/channel/-ABgPphiTAI/internal/videos',
                'title' => 'Full channel URL (e.g. https://matreshka.tv/channel/-ABgPphiTAI/internal/videos) or just the channel ID (e.g. -ABgPphiTAI)',
            ],
        ],
        'By playlist' => [
            'playlist' => [
                'name' => 'Playlist URL or ID',
                'type' => 'text',
                'required' => true,
                'exampleValue' => 'https://matreshka.tv/playlist/GgHALuP9YwY',
                'title' => 'Full playlist URL (e.g. https://matreshka.tv/playlist/GgHALuP9YwY) or just the playlist ID (e.g. GgHALuP9YwY)',
            ],
        ],
        'global' => [
            'limit' => [
                'name' => 'Limit',
                'type' => 'number',
                'defaultValue' => 10,
                'title' => 'Maximum number of videos to return',
            ],
            'hide_description' => [
                'name' => 'Hide description',
                'type' => 'checkbox',
                'defaultValue' => false,
                'title' => 'Hide video description text from feed items',
            ],
        ],
    ];

    private string $channelName = '';
    private string $channelAvatar = '';

    private function extractChannelId(string $input): string
    {
        if (preg_match('/^https?:\/\/matreshka\.tv\/channel\/([a-zA-Z0-9_-]+)/', $input, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/^[a-zA-Z0-9_-]+$/', $input) === 1) {
            return $input;
        }

        throw new \ClientException('Invalid channel input. Provide either a full Matreshka.tv channel URL or a channel ID.');
    }

    private function extractPlaylistId(string $input): string
    {
        if (preg_match('/^https?:\/\/matreshka\.tv\/playlist\/([a-zA-Z0-9_-]+)/', $input, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/^[a-zA-Z0-9_-]+$/', $input) === 1) {
            return $input;
        }

        throw new \ClientException('Invalid playlist input. Provide either a full Matreshka.tv playlist URL or a playlist ID.');
    }

    private function formatDuration(int $milliseconds): string
    {
        $totalSeconds = intdiv($milliseconds, 1000);
        $hours = intdiv($totalSeconds, 3600);
        $minutes = intdiv($totalSeconds % 3600, 60);
        $seconds = $totalSeconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
        }

        return sprintf('%d:%02d', $minutes, $seconds);
    }

    private function getBestThumbnail(array $cover): string
    {
        $sizes = ['1280x720', '1176x652', '714x396', '588x326', '360x200', '260x144', '180x100'];

        foreach ($sizes as $size) {
            if (isset($cover['png'][$size]) === true) {
                return $cover['png'][$size];
            }
            if (isset($cover['webp'][$size]) === true) {
                return $cover['webp'][$size];
            }
        }

        return '';
    }

    private function getBestAvatar(array $avatar): string
    {
        $sizes = ['480x480', '240x240', '200x200', '100x100', '80x80', '40x40'];

        foreach ($sizes as $size) {
            if (isset($avatar['png'][$size]) === true) {
                return $avatar['png'][$size];
            }
            if (isset($avatar['webp'][$size]) === true) {
                return $avatar['webp'][$size];
            }
        }

        return '';
    }

    private function fetchChannelInfo(string $channelId): void
    {
        $postData = json_encode([
            'field_mask' => ['id', 'name', 'avatar'],
            'filter' => [
                [
                    'is' => '=',
                    'field' => 'id',
                    'value' => $channelId,
                ],
            ],
        ]);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json, text/plain, */*',
        ];

        $curlOptions = [
            CURLOPT_POSTFIELDS => $postData,
        ];

        try {
            $response = getContents(self::CHANNEL_API_URL, $headers, $curlOptions);

            if ($response === '') {
                return;
            }

            $data = json_decode($response, true);

            if (is_array($data) === false || isset($data['data'][0]) === false) {
                return;
            }

            $channel = $data['data'][0];

            if (isset($channel['name']) === true) {
                $this->channelName = (string)$channel['name'];
            }

            if (isset($channel['avatar']) === true) {
                $this->channelAvatar = $this->getBestAvatar((array)$channel['avatar']);
            }
        } catch (\Exception $e) {
            return;
        }
    }

    private function fetchPlaylistInfo(string $playlistId): array
    {
        $postData = json_encode([
            'field_mask' => ['id', 'name', 'description', 'videos.id', 'videos'],
            'filter' => [
                [
                    'is' => '=',
                    'field' => 'id',
                    'value' => $playlistId,
                ],
            ],
        ]);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json, text/plain, */*',
        ];

        $curlOptions = [
            CURLOPT_POSTFIELDS => $postData,
        ];

        $response = getContents(self::PLAYLIST_API_URL, $headers, $curlOptions);

        if ($response === '') {
            throw new \Exception('Failed to fetch playlist info from Matreshka.tv API');
        }

        $data = json_decode($response, true);

        if (is_array($data) === false || isset($data['data'][0]) === false) {
            throw new \Exception('Invalid playlist API response from Matreshka.tv');
        }

        return $data['data'][0];
    }

    private function fetchVideosByChannel(string $channelId, int $limit): array
    {
        $postData = json_encode([
            'field_mask' => ['id', 'name', 'description', 'created_at', 'duration', 'cover'],
            'filter' => [
                [
                    'is' => '=',
                    'field' => 'channel_id',
                    'value' => $channelId,
                ],
            ],
            'sort' => [
                [
                    'field' => 'created_at',
                    'direction' => 'desc',
                ],
            ],
            'limit' => $limit,
        ]);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json, text/plain, */*',
        ];

        $curlOptions = [
            CURLOPT_POSTFIELDS => $postData,
        ];

        $response = getContents(self::API_URL, $headers, $curlOptions);

        if ($response === '') {
            throw new \Exception('Failed to fetch videos from Matreshka.tv API');
        }

        $data = json_decode($response, true);

        if (is_array($data) === false || isset($data['data']) === false) {
            throw new \Exception('Invalid API response from Matreshka.tv');
        }

        return array_slice($data['data'], 0, $limit);
    }

    private function fetchVideoById(string $videoId): ?array
    {
        $url = 'https://matreshka.tv/api/video-service/v1/video/' . $videoId;

        try {
            $response = getContents($url);

            if ($response === '') {
                return null;
            }

            $data = json_decode($response, true);

            if (is_array($data) === false || isset($data['data']) === false) {
                return null;
            }

            return $data['data'];
        } catch (\Exception $e) {
            return null;
        }
    }

    private function fetchVideosByIds(array $videoIds, int $limit): array
    {
        if (count($videoIds) === 0) {
            return [];
        }

        $limitedIds = array_slice($videoIds, 0, $limit);
        $videos = [];

        foreach ($limitedIds as $videoId) {
            $video = $this->fetchVideoById($videoId);
            if ($video !== null) {
                $videos[] = $video;
            }
        }

        return $videos;
    }

    private function applyStyles(string $html): string
    {
        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString('<div>' . $html . '</div>');
        libxml_clear_errors();

        $wrapper = $dom->querySelector('div');
        if ($wrapper === null) {
            return $html;
        }

        foreach ($wrapper->querySelectorAll('img') as $img) {
            if ($img instanceof \Dom\Element === true) {
                $img->removeAttribute('width');
                $img->removeAttribute('height');
                $img->removeAttribute('align');
                $img->setAttribute('style', self::CSS['img']);
            }
        }

        foreach ($wrapper->querySelectorAll('p, div, h1, h2, h3, h4, h5, h6') as $element) {
            if ($element instanceof \Dom\Element === false) {
                continue;
            }

            $style = (string)($element->getAttribute('style') ?? '');
            if ($style !== '') {
                $newStyle = preg_replace('/text-align\s*:\s*center\s*;?/i', '', $style);
                if (is_string($newStyle) === true && $newStyle !== $style) {
                    $newStyle = trim($newStyle);
                    if ($newStyle === '') {
                        $element->removeAttribute('style');
                    } else {
                        $element->setAttribute('style', $newStyle);
                    }
                }
            }

            $element->setAttribute('align', 'left');
        }

        return (string)$wrapper->innerHTML;
    }

    public function getName(): string
    {
        if ($this->channelName !== '') {
            return $this->channelName;
        }

        return parent::getName();
    }

    public function getIcon(): string
    {
        if ($this->channelAvatar !== '') {
            return $this->channelAvatar;
        }

        return parent::getIcon();
    }

    public function collectData(): void
    {
        $limitInput = $this->getInput('limit');
        $limit = (is_numeric($limitInput) === true) ? (int)$limitInput : 10;

        $hideDescriptionInput = $this->getInput('hide_description');
        $hideDescription = (is_string($hideDescriptionInput) === true && $hideDescriptionInput !== '') || $hideDescriptionInput === true;

        $videos = [];
        $isPlaylist = false;

        if ($this->queriedContext === 'By channel') {
            $channelInput = (string)$this->getInput('channel');
            $channelId = $this->extractChannelId($channelInput);

            $this->fetchChannelInfo($channelId);

            $videos = $this->fetchVideosByChannel($channelId, $limit);
        } elseif ($this->queriedContext === 'By playlist') {
            $isPlaylist = true;
            $playlistInput = (string)$this->getInput('playlist');
            $playlistId = $this->extractPlaylistId($playlistInput);

            $playlistInfo = $this->fetchPlaylistInfo($playlistId);

            if (isset($playlistInfo['name']) === true) {
                $this->channelName = (string)$playlistInfo['name'];
            }

            $videoIds = [];
            if (isset($playlistInfo['videos']) === true && is_array($playlistInfo['videos']) === true) {
                foreach ($playlistInfo['videos'] as $video) {
                    if (is_array($video) === true && isset($video['id']) === true) {
                        $videoIds[] = (string)$video['id'];
                    }
                }
            }

            $videoIds = array_reverse($videoIds);

            $videos = $this->fetchVideosByIds($videoIds, $limit);
        }

        $baseTimestamp = time();
        $playlistIndex = 0;

        foreach ($videos as $video) {
            if (is_array($video) === false) {
                continue;
            }

            $videoId = (string)($video['id'] ?? '');
            if ($videoId === '') {
                continue;
            }

            $title = (string)($video['name'] ?? '');
            $description = (string)($video['description'] ?? '');
            $createdAt = (string)($video['created_at'] ?? '');
            $duration = (int)($video['duration'] ?? 0);
            $cover = (array)($video['cover'] ?? []);

            $videoUrl = 'https://matreshka.tv/video/' . $videoId;
            $thumbnailUrl = $this->getBestThumbnail($cover);
            $durationFormatted = $this->formatDuration($duration);

            $content = '';
            if ($thumbnailUrl !== '') {
                $content .= sprintf(
                    '<p><a href="%s"><img src="%s" alt="%s" /></a></p>',
                    htmlspecialchars($videoUrl, ENT_QUOTES),
                    htmlspecialchars($thumbnailUrl, ENT_QUOTES),
                    htmlspecialchars($title, ENT_QUOTES)
                );
            }

            $content .= sprintf(
                '<p><strong>Duration:</strong> %s</p>',
                htmlspecialchars($durationFormatted, ENT_QUOTES)
            );

            if ($hideDescription === false && $description !== '') {
                $descriptionHtml = nl2br(htmlspecialchars($description, ENT_QUOTES));
                $content .= sprintf('<p>%s</p>', $descriptionHtml);
            }

            $content = $this->applyStyles($content);

            $timestamp = $baseTimestamp;
            if ($createdAt !== '') {
                $parsedTime = strtotime($createdAt);
                if ($parsedTime !== false) {
                    $timestamp = $parsedTime;
                }
            } elseif ($isPlaylist === true) {
                $timestamp = $baseTimestamp - $playlistIndex;
            }

            $this->items[] = [
                'title' => $title,
                'uri' => $videoUrl,
                'content' => $content,
                'timestamp' => $timestamp,
                'uid' => $videoId,
            ];

            $playlistIndex++;
        }
    }
}
