<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class YouTubeCommunityTabBridge extends BridgeAbstract
{
    public const NAME = 'YouTube Posts Tab';
    public const URI = 'https://www.youtube.com';
    public const DESCRIPTION = 'Returns posts from a channel\'s posts tab';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        'By channel ID' => [
            'channel' => [
                'name' => 'Channel ID',
                'type' => 'text',
                'required' => true,
                'exampleValue' => 'UCULkRHBdLC5ZcEQBaL0oYHQ',
            ],
        ],
        'By username' => [
            'username' => [
                'name' => 'Username',
                'type' => 'text',
                'required' => true,
                'exampleValue' => 'YouTubeUK',
            ],
        ],
    ];

    private string $feedUrl = '';
    private string $feedName = '';

    private const URL_REGEX = '/youtube\.com\/(channel|user|c)\/([\w]+)\/posts/';
    private const JSON_REGEX = '/var ytInitialData = ([^<]*);<\/script>/';

    /**
     * @param string $url
     * @return array|null
     */
    public function detectParameters($url)
    {
        if (is_string($url) === false) {
            return null;
        }

        if (preg_match(self::URL_REGEX, $url, $matches) === 1) {
            if ($matches[1] === 'channel') {
                return [
                    'context' => 'By channel ID',
                    'channel' => $matches[2],
                ];
            }
            if ($matches[1] === 'user' || $matches[1] === 'c') {
                return [
                    'context' => 'By username',
                    'username' => $matches[2],
                ];
            }
        }
        return null;
    }

    public function collectData()
    {
        $username = $this->getInput('username');
        $channel = $this->getInput('channel');

        if ($username !== null) {
            try {
                $this->feedUrl = $this->buildPostsUri((string)$username, 'c');
                $html = getContents($this->feedUrl);
            } catch (\Exception $e) {
                $this->feedUrl = $this->buildPostsUri((string)$username, 'user');
                $html = getContents($this->feedUrl);
            }
        } else {
            $this->feedUrl = $this->buildPostsUri((string)$channel, 'channel');
            $html = getContents($this->feedUrl);
        }

        $json = $this->extractJson($html);

        $this->feedName = $json->header->c4TabbedHeaderRenderer->title ?? $json->header->pageHeaderRenderer->pageTitle ?? $json->metadata->channelMetadataRenderer->title ?? $json->microformat->microformatDataRenderer->title ?? '';

        if ($this->hasPostsTab($json) === false) {
            throwServerException('Channel does not have a posts tab');
        }

        $posts = $this->getPosts($json);
        if ($posts === null) {
            return;
        }

        foreach ($posts as $key => $post) {
            if (isset($post->backstagePostThreadRenderer) === false) {
                continue;
            }

            $details = $post->backstagePostThreadRenderer->post->backstagePostRenderer ?? $post->backstagePostThreadRenderer->post->sharedPostRenderer ?? null;

            if ($details === null) {
                continue;
            }

            $postUri = self::URI . '/post/' . $details->postId;
            $author = $details->authorText->runs[0]->text ?? null;
            $content = $postUri;
            $title = '';

            if (isset($details->contentText->runs) === true) {
                $text = $this->getText($details->contentText->runs);
                $title = $this->ellipsisTitle($text);
                $content = $text;
            }

            $attachment = $this->getAttachments($details);
            $content .= $attachment['content'];

            if ($title === '' && $attachment['title'] !== '') {
                $title = $attachment['title'];
            }

            $item = [
                'uri' => $postUri,
                'author' => is_string($author) === true ? $author : null,
                'title' => $title,
                'content' => $content,
            ];

            $dateText = $details->publishedTimeText->runs[0]->text ?? null;
            if (is_string($dateText) === true) {
                $date = strtotime(str_replace(' (edited)', '', $dateText));
                if ($date !== false) {
                    $item['timestamp'] = $date - $key * 60;
                }
            }

            $this->items[] = $item;
        }
    }

    public function getURI()
    {
        if ($this->feedUrl !== '') {
            return $this->feedUrl;
        }
        return parent::getURI();
    }

    public function getName()
    {
        if ($this->feedName !== '') {
            return $this->feedName . ' - YouTube Posts Tab';
        }
        return parent::getName();
    }

    private function buildPostsUri(string $value, string $type): string
    {
        return self::URI . '/' . $type . '/' . $value . '/posts';
    }

    private function extractJson(string $html): object
    {
        if (preg_match(self::JSON_REGEX, $html, $parts) !== 1) {
            throwServerException('Failed to extract data from page');
        }

        $data = json_decode($parts[1]);
        if ($data === null || is_object($data) === false) {
            throwServerException('Failed to decode extracted data');
        }

        return $data;
    }

    private function hasPostsTab(object $json): bool
    {
        $tabs = $json->contents->twoColumnBrowseResultsRenderer->tabs ?? [];
        foreach ($tabs as $tab) {
            $url = $tab->tabRenderer?->endpoint?->commandMetadata?->webCommandMetadata?->url ?? '';
            if (is_string($url) === true && str_ends_with($url, 'posts') === true) {
                return true;
            }
        }
        return false;
    }

    private function getPosts(object $json): ?array
    {
        $tabs = $json->contents->twoColumnBrowseResultsRenderer->tabs ?? [];
        foreach ($tabs as $tab) {
            $url = $tab->tabRenderer?->endpoint?->commandMetadata?->webCommandMetadata?->url ?? '';
            if (is_string($url) === true && str_ends_with($url, 'posts') === true) {
                return $tab->tabRenderer->content->sectionListRenderer->contents[0]->itemSectionRenderer->contents ?? null;
            }
        }
        return null;
    }

    private function getText(array $runs): string
    {
        $text = '';
        foreach ($runs as $part) {
            $url = $part->navigationEndpoint->browseEndpoint->canonicalBaseUrl ?? $part->navigationEndpoint->urlEndpoint->url ?? $part->navigationEndpoint->commandMetadata?->webCommandMetadata?->url ?? null;

            $text .= $this->formatUrls((string)($part->text ?? ''), $url);
        }
        return nl2br($text);
    }

    private function getAttachments(object $details): array
    {
        $result = ['content' => '', 'title' => ''];

        if (isset($details->backstageAttachment) === false) {
            return $result;
        }

        $attachments = $details->backstageAttachment;

        if (isset($attachments->videoRenderer->videoId) === true) {
            $result['title'] = $this->feedName . ' posted a video';
            $result['content'] = handleYoutube($attachments->videoRenderer->videoId);
            return $result;
        }

        if (isset($attachments->backstageImageRenderer) === true) {
            $result['title'] = $this->feedName . ' posted an image';
            $thumbnails = $attachments->backstageImageRenderer->image->thumbnails ?? [];
            $lastThumb = end($thumbnails);
            if ($lastThumb !== false && isset($lastThumb->url) === true) {
                $result['content'] = '<p><img src="' . e($lastThumb->url) . '"></p>';
            }
            return $result;
        }

        if (isset($attachments->pollRenderer) === true) {
            $result['title'] = $this->feedName . ' posted a poll';
            $choices = '';
            foreach ($attachments->pollRenderer->choices ?? [] as $choice) {
                $choiceText = $choice->text->runs[0]->text ?? '';
                $choices .= '<li>' . e((string)$choiceText) . '</li>';
            }
            $votes = $attachments->pollRenderer->totalVotes->simpleText ?? '';
            $result['content'] = '<hr><p>Poll (' . e((string)$votes) . ')<br><ul>' . $choices . '</ul></p>';
            return $result;
        }

        if (isset($attachments->postMultiImageRenderer->images) === true) {
            $images = $attachments->postMultiImageRenderer->images;
            if (is_array($images) === true) {
                $result['title'] = $this->feedName . ' posted ' . count($images) . ' images';
                foreach ($images as $image) {
                    $thumbnails = $image->backstageImageRenderer->image->thumbnails ?? [];
                    $lastThumb = end($thumbnails);
                    if ($lastThumb !== false && isset($lastThumb->url) === true) {
                        $result['content'] .= '<p><img src="' . e($lastThumb->url) . '"></p>';
                    }
                }
            }
            return $result;
        }

        return $result;
    }

    private function ellipsisTitle(string $text): string
    {
        $length = 100;
        $plainText = strip_tags($text);
        if (mb_strlen($plainText) > $length) {
            $wrapped = wordwrap($plainText, $length, '<br>', true);
            $parts = explode('<br>', $wrapped);
            return $parts[0] . '...';
        }
        return $plainText;
    }

    private function formatUrls(string $content, ?string $url): string
    {
        if ($url === null) {
            $url = $content;
        }

        if (str_starts_with($url, '/') === true) {
            $url = self::URI . $url;
        } elseif (str_starts_with($url, 'https://www.youtube.com/redirect?') === true) {
            $query = substr($url, strlen('https://www.youtube.com/redirect?'));
            parse_str($query, $params);
            $q = $params['q'] ?? '';
            if (is_string($q) === true && str_starts_with($q, rtrim($content, '.')) === true) {
                $url = $q;
            }
        }

        if (filter_var($url, FILTER_VALIDATE_URL) !== false) {
            return '<a href="' . e($url) . '" target="_blank">' . e($content) . '</a>';
        }

        return e($content);
    }
}
