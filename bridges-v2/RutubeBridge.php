<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class RutubeBridge extends BridgeAbstract
{
    public const NAME = 'Rutube';
    public const URI = 'https://rutube.ru';
    public const DESCRIPTION = 'Returns the newest videos by channel/playlist/search';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        'By channel' => [
            'c' => [
                'name' => 'Channel ID',
                'exampleValue' => 32869212,
                'type' => 'number',
                'required' => true,
            ],
            'hide_description' => [
                'name' => 'Hide video description',
                'type' => 'checkbox',
                'title' => 'Do not include video description in the feed content',
            ],
        ],
        'By playlist' => [
            'p' => [
                'name' => 'Playlist ID',
                'exampleValue' => 1413624,
                'type' => 'number',
                'required' => true,
            ],
            'hide_description' => [
                'name' => 'Hide video description',
                'type' => 'checkbox',
                'title' => 'Do not include video description in the feed content',
            ],
        ],
        'From search' => [
            's' => [
                'name' => 'Search query',
                'exampleValue' => 'Spider Man',
                'required' => true,
            ],
            'hide_description' => [
                'name' => 'Hide video description',
                'type' => 'checkbox',
                'title' => 'Do not include video description in the feed content',
            ],
        ],
    ];

    private const REDUX_REGEX = '/window\.reduxState = (.*);/';
    private const LINK_REGEX = '$(https?://[a-z0-9_./?=&#-]+)(?![^<>]*>)$i';

    private const ESCAPE_MAP = [
        '\x26' => '&',
        '\x3c' => '<',
        '\x3d' => '=',
        '\x3e' => '>',
        '\x3f' => '?',
    ];

    private const CSS = [
        'image' => 'display: block; max-width: 600px; height: auto; margin: 20px 0;',
    ];

    private ?string $title = null;

    public function getURI()
    {
        $c = $this->getInput('c');
        $p = $this->getInput('p');
        $s = $this->getInput('s');

        if ($c !== null) {
            return self::URI . '/channel/' . (string)$c . '/videos/';
        }

        if ($p !== null) {
            return self::URI . '/plst/' . (string)$p . '/';
        }

        if ($s !== null && $s !== '') {
            return self::URI . '/search/?suggest=1&query=' . rawurlencode((string)$s);
        }

        return parent::getURI();
    }

    public function getName()
    {
        if ($this->title !== null && $this->title !== '') {
            return $this->title . ' - ' . parent::getName();
        }

        return parent::getName();
    }

    public function collectData()
    {
        $c = $this->getInput('c');
        $p = $this->getInput('p');
        $s = $this->getInput('s');

        if ($c !== null || $p !== null) {
            $videos = $this->getVideosFromReduxState();
        } elseif ($s !== null && $s !== '') {
            $videos = $this->getVideosFromSearchAPI();
        } else {
            $videos = [];
        }

        if (is_array($videos) === false) {
            return;
        }

        $hideDescription = $this->getInput('hide_description') === true;

        foreach ($videos as $video) {
            if (is_object($video) === false) {
                continue;
            }

            $videoTitle = (string)($video->title ?? '');
            $videoUrl = (string)($video->video_url ?? '');

            if ($videoTitle === '' || $videoUrl === '') {
                continue;
            }

            $thumbnailUrl = (string)($video->thumbnail_url ?? '');
            $description = (string)($video->description ?? '');
            $authorName = isset($video->author->name) ? (string)$video->author->name : null;
            $publicationTs = $video->publication_ts ?? null;

            $item = [];
            $item['title'] = $videoTitle;
            $item['uri'] = $videoUrl;
            $item['content'] = $this->buildItemContent($videoUrl, $thumbnailUrl, $description, $hideDescription);
            $item['author'] = $authorName;

            if ($publicationTs !== null) {
                if (is_numeric($publicationTs) === true) {
                    $item['timestamp'] = (int)$publicationTs;
                } elseif (is_string($publicationTs) === true && $publicationTs !== '') {
                    $item['timestamp'] = $publicationTs;
                }
            }

            $this->items[] = $item;
        }
    }

    private function getVideosFromReduxState(): array
    {
        $html = getContents($this->getURI());
        $reduxState = $this->getJSONData($html);

        if (is_object($reduxState) === false) {
            return [];
        }

        $c = $this->getInput('c');
        $p = $this->getInput('p');
        $s = $this->getInput('s');

        $videos = [];

        if ($c !== null) {
            $cStr = (string)$c;
            $videosMethod1 = 'allVideos(' . $cStr . ',)';
            $videosMethod2 = 'videos(' . $cStr . ',)';

            if (isset($reduxState->api->queries->$videosMethod1->data->results) === true) {
                $videos = $reduxState->api->queries->$videosMethod1->data->results;
            } elseif (isset($reduxState->api->queries->$videosMethod2->data->results) === true) {
                $videos = $reduxState->api->queries->$videosMethod2->data->results;
            }

            $channelInfoMethod = 'channelInfo({"userChannelId":' . $cStr . '})';
            if (isset($reduxState->api->queries->$channelInfoMethod->data->name) === true) {
                $this->title = (string)$reduxState->api->queries->$channelInfoMethod->data->name;
            }
        } elseif ($p !== null) {
            $pStr = (string)$p;
            $playListVideosMethod = 'getPlaylistVideos(' . $pStr . ',)';
            $playListMethod = 'getPlaylist(' . $pStr . ')';

            if (isset($reduxState->api->queries->$playListVideosMethod->data->results) === true) {
                $videos = $reduxState->api->queries->$playListVideosMethod->data->results;
            }

            if (isset($reduxState->api->queries->$playListMethod->data->title) === true) {
                $this->title = (string)$reduxState->api->queries->$playListMethod->data->title;
            }
        } elseif ($s !== null && $s !== '') {
            $this->title = 'Поиск ' . (string)$s;
        }

        return is_array($videos) ? $videos : [];
    }

    private function getVideosFromSearchAPI(): array
    {
        $s = (string)$this->getInput('s');

        if ($s === '') {
            return [];
        }

        $url = self::URI . '/api/search/video/?suggest=1&client=wdp&query=' . rawurlencode($s);

        $contents = getContents($url);
        $json = json_decode($contents);

        if (is_object($json) === false || isset($json->results) === false || is_array($json->results) === false) {
            return [];
        }

        return $json->results;
    }

    private function getJSONData(string $html): ?object
    {
        if (preg_match(self::REDUX_REGEX, $html, $matches) !== 1) {
            throwServerException('Could not find reduxState');
        }

        $jsonString = str_replace(
            array_keys(self::ESCAPE_MAP),
            array_values(self::ESCAPE_MAP),
            $matches[1]
        );

        $decoded = json_decode($jsonString, false);

        if (is_object($decoded) === false) {
            throwServerException('Failed to decode reduxState JSON');
        }

        return $decoded;
    }

    private function buildItemContent(
        string $videoUrl,
        string $thumbnailUrl,
        string $description,
        bool $hideDescription
    ): string {
        $content = '';

        if ($thumbnailUrl !== '') {
            $content .= '<a href="' . e($videoUrl) . '">';
            $content .= '<img src="' . e($thumbnailUrl) . '" style="' . self::CSS['image'] . '" />';
            $content .= '</a><br/>';
        }

        if ($hideDescription === false && $description !== '') {
            $descriptionWithLinks = preg_replace(
                self::LINK_REGEX,
                ' <a href="$1" target="_blank">$1</a> ',
                $description . ' '
            );

            if ($descriptionWithLinks !== null) {
                $content .= nl2br($descriptionWithLinks);
            }
        }

        return $content;
    }
}
