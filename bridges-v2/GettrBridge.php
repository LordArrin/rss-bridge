<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class GettrBridge extends BridgeAbstract
{
    public const NAME = 'Gettr.com';
    public const URI = 'https://gettr.com';
    public const DESCRIPTION = 'Fetches the latest posts from a GETTR user';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 900;

    public const PARAMETERS = [
        [
            'user' => [
                'name' => 'User',
                'type' => 'text',
                'required' => true,
                'exampleValue' => 'americafirstongettr',
            ],
            'limit' => [
                'name' => 'Limit',
                'type' => 'number',
                'title' => 'Maximum number of items to return (maximum 20)',
                'defaultValue' => 5,
                'required' => true,
            ],
        ]
    ];

    public function collectData(): void
    {
        $user = (string) ($this->getInput('user') ?? '');

        if ($user === '') {
            \throwClientException('User is required');
        }

        $limit = (int) ($this->getInput('limit') ?? 5);
        $maxLimit = min($limit, 20);

        $api = sprintf(
            'https://api.gettr.com/u/user/%s/posts?offset=0&max=%s&dir=fwd&incl=posts&fp=f_uo',
            $user,
            $maxLimit
        );

        try {
            $json = getContents($api);
        } catch (\HttpException $e) {
            if ($e->getCode() === 400) {
                $response = $e->response;
                $body = ($response !== null) ? $response->getBody() : '';
                if (str_contains($body, 'E_USER_NOTFOUND') === true) {
                    \throwClientException('User not found: ' . $user);
                }
            }
            \throwServerException('Failed to fetch posts: ' . $e->getMessage());
        }

        $data = json_decode($json, false);

        if (is_object($data) === false) {
            \throwServerException('Invalid API response format');
        }

        if (isset($data->result) === false || is_object($data->result) === false) {
            return;
        }
        if (isset($data->result->aux) === false || is_object($data->result->aux) === false) {
            return;
        }
        if (isset($data->result->aux->post) === false) {
            return;
        }

        $posts = $data->result->aux->post;
        $postsIterable = [];

        if (is_array($posts) === true) {
            $postsIterable = $posts;
        } elseif (is_object($posts) === true) {
            $postsIterable = array_values((array) $posts);
        }

        foreach ($postsIterable as $post) {
            if (is_object($post) === false) {
                continue;
            }

            $txt = (string) ($post->txt ?? '');
            $uid = (string) ($post->uid ?? '');
            $postId = (string) ($post->_id ?? '');

            $title = ($txt !== '') ? mb_substr($txt, 0, 100) : $uid . '@gettr.com';

            $cdate = (string) ($post->cdate ?? '');
            $timestamp = ($cdate !== '') ? (int) substr($cdate, 0, strlen($cdate) - 3) : time();

            $hashtags = $post->htgs ?? [];
            if (is_array($hashtags) === false) {
                $hashtags = [];
            }

            $this->items[] = [
                'title' => $title,
                'uri' => sprintf('https://gettr.com/post/%s', $postId),
                'author' => $uid,
                'timestamp' => $timestamp,
                'uid' => $postId,
                'categories' => $hashtags,
                'content' => $this->createContent($post),
            ];
        }
    }

    private function createContent(\stdClass $post): string
    {
        $content = '';

        $txt = $post->txt ?? null;
        if (is_string($txt) === true && $txt !== '') {
            $userInput = (string) ($this->getInput('user') ?? '');
            $postUid = (string) ($post->uid ?? '');
            $isRepost = ($userInput !== '' && $userInput !== $postUid);

            if ($isRepost === true) {
                $content .= 'Reposted by ' . $userInput . '@gettr.com<br><br>';
            }
            $content .= htmlspecialchars($txt) . '<br><br>';
        }

        $previmg = $post->previmg ?? null;
        if (is_string($previmg) === true && $previmg !== '') {
            $prevsrc = (string) ($post->prevsrc ?? '');
            if ($prevsrc === '') {
                $prevsrc = $previmg;
            }

            $content .= '<a href="' . htmlspecialchars($prevsrc) . '" target="_blank">';
            $content .= '<img src="' . htmlspecialchars($previmg) . '" alt="Unable to load image" loading="lazy">';
            $content .= '</a><br><br>';
        }

        $imgs = $post->imgs ?? [];
        if (is_array($imgs) === true) {
            foreach ($imgs as $imageUrl) {
                if (is_string($imageUrl) === true && $imageUrl !== '') {
                    $fullUrl = 'https://media.gettr.com/' . $imageUrl;
                    $content .= '<img src="' . htmlspecialchars($fullUrl) . '" alt="Unable to load image"><br><br>';
                }
            }
        }

        $ovid = $post->ovid ?? null;
        if (is_string($ovid) === true && $ovid !== '') {
            $mainImage = (string) ($post->main ?? '');
            $posterUrl = ($mainImage !== '') ? 'https://media.gettr.com/' . $mainImage : '';
            $videoUrl = 'https://media.gettr.com/' . $ovid;

            $content .= '<video style="max-width: 100%" controls preload="none"';
            if ($posterUrl !== '') {
                $content .= ' poster="' . htmlspecialchars($posterUrl) . '"';
            }
            $content .= '>';
            $content .= '<source src="' . htmlspecialchars($videoUrl) . '" type="video/mp4">';
            $content .= 'Your browser does not support the video element. Kindly update it to latest version.';
            $content .= '</video>';
        }

        $this->processMetadata($post);

        return $content;
    }

    public function getIcon(): string
    {
        return 'https://gettr.com/favicon.ico';
    }

    private function processMetadata(\stdClass $post): void
    {
        $textLanguage = (string) ($post->txt_lang ?? 'en');
        $replies = (int) ($post->cm ?? 0);
        $likes = (int) ($post->lkbpst ?? 0);
        $reposts = (int) ($post->shbpst ?? 0);
        $visibility = (string) ($post->vis ?? 'p');
    }
}
