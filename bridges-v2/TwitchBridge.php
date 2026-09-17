<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;
use Json;

final class TwitchBridge extends BridgeAbstract
{
    public const NAME = 'Twitch';
    public const URI = 'https://www.twitch.tv/';
    public const DESCRIPTION = 'Returns Twitch channel videos';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 900;

    private const CLIENT_ID = 'kimne78kx3ncx6brgo4mv6wki5h1ko';
    private const GRAPHQL_ENDPOINT = 'https://gql.twitch.tv/gql';
    private const MAX_LIMIT = 25;

    public const PARAMETERS = [
        '' => [
            'channel' => [
                'name' => 'Channel',
                'type' => 'text',
                'required' => true,
                'exampleValue' => 'criticalrole',
                'title' => 'Lowercase channel name as seen in channel URL',
            ],
            'limit' => [
                'name' => 'Limit',
                'type' => 'number',
                'defaultValue' => 5,
            ],
        ],
    ];

    private const BROADCAST_TYPES_ALL = [
        'ARCHIVE',
        'HIGHLIGHT',
        'UPLOAD',
        'PAST_PREMIERE',
        'PREMIERE_UPLOAD',
    ];

    private const QUERY = <<<'EOD'
query VODList($channel: String!, $types: [BroadcastType!], $limit: Int!) {
  user(login: $channel) {
    displayName
    videos(types: $types, sort: TIME, first: $limit) {
      edges {
        node {
          id
          title
          publishedAt
          lengthSeconds
          viewCount
          thumbnailURLs(width: 640, height: 360)
          previewThumbnailURL(width: 640, height: 360)
          description
          tags
          contentTags {
            isLanguageTag
            localizedName
          }
          game {
            displayName
          }
          moments(momentRequestType: VIDEO_CHAPTER_MARKERS) {
            edges {
              node {
                description
                positionMilliseconds
              }
            }
          }
        }
      }
    }
  }
}
EOD;

    public function collectData(): void
    {
        $channel = (string)$this->getInput('channel');

        $limitInput = $this->getInput('limit');
        $limit = $limitInput !== null && $limitInput !== '' ? (int)$limitInput : self::MAX_LIMIT;

        if ($limit > self::MAX_LIMIT) {
            $limit = self::MAX_LIMIT;
        }

        if ($limit < 1) {
            $limit = 1;
        }

        if ($channel === '') {
            throwClientException('Channel name is required');
        }

        $variables = [
            'channel' => $channel,
            'types' => self::BROADCAST_TYPES_ALL,
            'limit' => $limit,
        ];

        $data = $this->apiRequest(self::QUERY, $variables);

        if (isset($data->data) === false) {
            throwServerException('Invalid GraphQL response: missing data field');
        }

        if (isset($data->data->user) === false || $data->data->user === null) {
            throwClientException(sprintf('Unable to find channel `%s`', e($channel)));
        }

        $user = $data->data->user;

        if (isset($user->videos) === false || $user->videos === null) {
            return;
        }

        if (isset($user->videos->edges) === false) {
            return;
        }

        $displayName = isset($user->displayName) === true ? (string)$user->displayName : 'Unknown';

        foreach ($user->videos->edges as $edge) {
            if (isset($edge->node) === false) {
                continue;
            }

            $video = $edge->node;
            $item = $this->buildItem($video, $displayName);

            if ($item !== null) {
                $this->items[] = $item;
            }
        }
    }

    private function buildItem(object $video, string $displayName): ?array
    {
        if (isset($video->id) === false) {
            return null;
        }

        $url = 'https://www.twitch.tv/videos/' . $video->id;
        $title = isset($video->title) === true ? (string)$video->title : 'Untitled';
        $publishedAt = isset($video->publishedAt) === true ? strtotime((string)$video->publishedAt) : time();

        if ($publishedAt === false) {
            $publishedAt = time();
        }

        $categories = [];
        if (isset($video->tags) === true && is_array($video->tags) === true) {
            foreach ($video->tags as $tag) {
                if (is_string($tag) === true && $tag !== '') {
                    $categories[] = $tag;
                }
            }
        }

        if (isset($video->game) === true && $video->game !== null) {
            if (isset($video->game->displayName) === true) {
                $categories[] = (string)$video->game->displayName;
            }
        }

        if (isset($video->contentTags) === true && is_array($video->contentTags) === true) {
            foreach ($video->contentTags as $tag) {
                $isLang = isset($tag->isLanguageTag) === true ? (bool)$tag->isLanguageTag : false;
                if ($isLang === false) {
                    if (isset($tag->localizedName) === true) {
                        $categories[] = (string)$tag->localizedName;
                    }
                }
            }
        }

        $categories = array_values(array_unique($categories));

        $content = $this->buildVideoContent($video, $url);

        return [
            'uri' => $url,
            'title' => $title,
            'timestamp' => $publishedAt,
            'author' => $displayName,
            'content' => $content,
            'categories' => $categories,
            'uid' => $url,
        ];
    }

    private function buildVideoContent(object $video, string $url): string
    {
        $html = '';

        if (isset($video->previewThumbnailURL) === true && is_string($video->previewThumbnailURL) === true) {
            $html .= sprintf(
                '<p><a href="%s"><img src="%s" alt="Preview" /></a></p>',
                e($url),
                e($video->previewThumbnailURL)
            );
        }

        if (isset($video->description) === true && is_string($video->description) === true) {
            $descriptionHtml = markdownToHtml($video->description, ['breaksEnabled' => true]);
            $html .= sprintf('<div class="description">%s</div>', $descriptionHtml);
        }

        $lengthSeconds = isset($video->lengthSeconds) === true ? (int)$video->lengthSeconds : 0;
        $viewCount = isset($video->viewCount) === true ? (int)$video->viewCount : 0;

        $html .= '<p>';
        $html .= sprintf('<b>Duration:</b> %s<br/>', $this->formatTimestampTime($lengthSeconds));
        $html .= sprintf('<b>Views:</b> %d', $viewCount);
        $html .= '</p>';

        $chaptersHtml = $this->buildChaptersHtml($video, $url);
        if ($chaptersHtml !== '') {
            $html .= $chaptersHtml;
        }

        return break_annoying_html_tags($html);
    }

    private function buildChaptersHtml(object $video, string $url): string
    {
        $html = '<p><b>Played games:</b><ul>';

        $momentEdges = [];
        if (isset($video->moments) === true && $video->moments !== null) {
            if (isset($video->moments->edges) === true && is_array($video->moments->edges) === true) {
                $momentEdges = $video->moments->edges;
            }
        }

        $hasMoments = count($momentEdges) > 0;

        if ($hasMoments === true) {
            foreach ($momentEdges as $momentEdge) {
                if (isset($momentEdge->node) === false) {
                    continue;
                }

                $moment = $momentEdge->node;
                $positionMs = isset($moment->positionMilliseconds) === true ? (int)$moment->positionMilliseconds : 0;
                $description = isset($moment->description) === true ? (string)$moment->description : 'Unknown';
                $positionSeconds = intdiv($positionMs, 1000);

                $html .= sprintf(
                    '<li><a href="%s?t=%s">%s</a> - %s</li>',
                    e($url),
                    e($this->formatQueryTime($positionSeconds)),
                    e($this->formatTimestampTime($positionSeconds)),
                    e($description)
                );
            }
        } else {
            $gameName = 'No Game';
            if (isset($video->game) === true && $video->game !== null) {
                if (isset($video->game->displayName) === true) {
                    $gameName = (string)$video->game->displayName;
                }
            }

            $html .= sprintf(
                '<li><a href="%s">%s</a> - %s</li>',
                e($url),
                e($this->formatTimestampTime(0)),
                e($gameName)
            );
        }

        $html .= '</ul></p>';

        return $html;
    }

    private function formatTimestampTime(int $seconds): string
    {
        if ($seconds < 0) {
            $seconds = 0;
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }

    private function formatQueryTime(int $seconds): string
    {
        if ($seconds < 0) {
            $seconds = 0;
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        return sprintf('%dh%dm%ds', $hours, $minutes, $secs);
    }

    private function apiRequest(string $query, array $variables): object
    {
        $cacheKey = 'twitch_gql_' . md5($query . json_encode($variables));

        $success = false;
        $cached = apcu_fetch($cacheKey, $success);

        if ($success === true && is_string($cached) === true) {
            $json = $cached;
        } else {
            $request = [
                'query' => $query,
                'variables' => $variables,
            ];

            $headers = [
                'Content-Type: application/json',
                'Client-ID: ' . self::CLIENT_ID,
            ];

            $curlOptions = [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($request),
            ];

            $json = getContents(self::GRAPHQL_ENDPOINT, $headers, $curlOptions);

            apcu_store($cacheKey, $json, self::CACHE_TIMEOUT);
        }

        try {
            $result = Json::decode($json, false);
        } catch (\JsonException $ex) {
            throwServerException('Failed to decode Twitch GraphQL response: ' . $ex->getMessage());
        }

        if ($result instanceof \stdClass === false) {
            throwServerException('Invalid Twitch GraphQL response format');
        }

        if (isset($result->errors) === true && is_array($result->errors) === true) {
            $messages = [];
            foreach ($result->errors as $error) {
                if (isset($error->message) === true) {
                    $messages[] = (string)$error->message;
                }
            }
            throwServerException('Twitch GraphQL error: ' . implode('; ', $messages));
        }

        return $result;
    }

    public function getName(): string
    {
        $channel = $this->getInput('channel');
        if ($channel !== null && $channel !== '') {
            return sprintf('%s Twitch videos', (string)$channel);
        }

        return parent::getName();
    }

    public function getURI(): string
    {
        $channel = $this->getInput('channel');
        if ($channel !== null && $channel !== '') {
            return self::URI . (string)$channel;
        }

        return parent::getURI();
    }
}
