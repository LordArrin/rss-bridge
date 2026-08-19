<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

abstract class GelbooruBase extends BridgeAbstract
{
    const NAME = 'Gelbooru';
    const URI = 'https://gelbooru.com/';
    const DESCRIPTION = 'Base bridge for Gelbooru-based imageboards (use a site-specific bridge instead)';
    const MAINTAINER = 'LordArrin';
    const CACHE_TIMEOUT = 1800;

    const PARAMETERS = [
        'global' => [
            'api_key' => [
                'name' => 'API Key',
                'type' => 'text',
                'required' => false,
                'title' => 'Your API key (if required)'
            ],
            'user_id' => [
                'name' => 'User ID',
                'type' => 'number',
                'required' => false,
                'title' => 'Your user ID (if required)'
            ],
            'p' => [
                'name' => 'page',
                'defaultValue' => 0,
                'type' => 'number'
            ],
            't' => [
                'name' => 'tags',
                'exampleValue' => 'solo',
                'title' => 'Tags to search for'
            ],
            'l' => [
                'name' => 'limit',
                'exampleValue' => 100,
                'title' => 'How many posts to retrieve (hard limit of 1000)'
            ]
        ],
        0 => []
    ];

    public function collectData(): void
    {
        $content = getContents($this->getFullURI());

        if ($content === '') {
            return;
        }

        $this->processResponse($content);
    }

    protected function getFullURI(): string
    {
        $params = [
            'page' => 'dapi',
            's' => 'post',
            'q' => 'index',
            'json' => 1,
            'pid' => $this->getInput('p'),
            'limit' => $this->getInput('l'),
            'tags' => $this->normalizeQuery((string) ($this->getInput('t') ?? '')),
            'api_key' => $this->getInput('api_key'),
            'user_id' => $this->getInput('user_id'),
        ];

        return $this->getURI() . 'index.php?' . http_build_query($params);
    }

    protected function processResponse(string $content): void
    {
        $data = \Json::decode($content, false);
        $posts = $this->extractPosts($data);

        if (is_iterable($posts) === false) {
            return;
        }

        foreach ($posts as $post) {
            if ($post instanceof \stdClass) {
                $this->items[] = $this->getItemFromElement($post);
            }
        }
    }

    protected function extractPosts(mixed $data): array|\stdClass
    {
        return $data->post ?? $data ?? [];
    }

    protected function buildThumbnailURI(\stdClass $element): string
    {
        return $this->getURI() . 'thumbnails/' . $element->directory
            . '/thumbnail_' . $element->md5 . '.jpg';
    }

    protected function getItemFromElement(\stdClass $element): array
    {
        $postId = (int) ($element->id ?? 0);
        $thumbnailUri = $element->preview_url ?? $this->buildThumbnailURI($element);
        $source = $element->source ?? null;
        $timestamp = $this->getTimestamp($element);

        $item = [
            'uri' => $this->getURI() . 'index.php?page=post&s=view&id=' . $postId,
            'postid' => $postId,
            'author' => (string) ($element->owner ?? 'unknown'),
            'timestamp' => $timestamp,
            'tags' => (string) ($element->tags ?? ''),
            'title' => sprintf('%s | %d', $this->getName(), $postId),
        ];

        $content = sprintf(
            '<a href="%s"><img src="%s" /></a><br><br><b>Dimensions:</b> %d x %d<br><br><b>Tags:</b> %s',
            $item['uri'],
            $thumbnailUri,
            (int) ($element->width ?? 0),
            (int) ($element->height ?? 0),
            $item['tags']
        );

        if ($source !== null && $source !== '') {
            $content .= sprintf('<br><br><b>Source: </b><a href="%1$s">%1$s</a>', $source);
        }

        $item['content'] = $content;

        return $item;
    }

    protected function getTimestamp(\stdClass $element): string
    {
        if (isset($element->change) === true) {
            $dateTime = new \DateTimeImmutable('@' . (int) $element->change);
            return $dateTime->format('d F Y H:i:s');
        }

        if (isset($element->created_at) === true) {
            if (is_numeric($element->created_at) === true) {
                $dateTime = new \DateTimeImmutable('@' . (int) $element->created_at);
                return $dateTime->format('d F Y H:i:s');
            }

            try {
                $dateTime = new \DateTimeImmutable((string) $element->created_at);
                return $dateTime->format('d F Y H:i:s');
            } catch (\Exception) {
                // Fall through to default
            }
        }

        return (new \DateTimeImmutable())->format('d F Y H:i:s');
    }

    protected function normalizeQuery(string $query): string
    {
        $query = str_replace(',', ' ', $query);
        return trim(preg_replace('/\s+/', ' ', $query) ?? '');
    }
}
