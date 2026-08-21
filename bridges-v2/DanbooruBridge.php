<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class DanbooruBridge extends BridgeAbstract
{
    public const NAME = 'Danbooru';
    public const URI = 'https://danbooru.donmai.us/';
    public const DESCRIPTION = 'Returns images from danbooru.donmai.us search';
    public const MAINTAINER = 'LordArrin';
    public const CACHE_TIMEOUT = 3600;

    private const EMBED_CACHE_TTL = 604800;

    private const MIME_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
    ];

    public const CONFIGURATION = [
        'login' => [
            'required' => false,
        ],
        'api_key' => [
            'required' => false,
        ],
    ];

    public const PARAMETERS = [
        'global' => [
            'q' => [
                'name' => 'Query (Tags)',
                'type' => 'text',
                'required' => false,
                'title' => 'Space-separated tags. Danbooru supports: "tag1 tag2" (AND), "tag1 ~tag2" (OR), "-tag" (exclude), "tag*" (wildcard).',
                'exampleValue' => 'cosplay',
            ],
            'l' => [
                'name' => 'Posts limit',
                'type' => 'number',
                'required' => false,
                'title' => 'Maximum number of posts to fetch (API hard limit is 200). Every item carries a base64 image, so keep this modest.',
                'defaultValue' => 20,
            ],
            'login' => [
                'name' => 'Login',
                'type' => 'text',
                'required' => false,
                'title' => 'Your Danbooru username. Leave empty to use server default',
            ],
            'api_key' => [
                'name' => 'API Key',
                'type' => 'text',
                'required' => false,
                'title' => 'Your Danbooru API key. Leave empty to use server default',
            ],
        ],
        0 => []
    ];

    private string $login = '';
    private string $apiKey = '';

    private function resolveCredential(string $name): string
    {
        $input = $this->getInput($name);
        if ($input !== null && $input !== '') {
            return (string)$input;
        }

        $option = $this->getOption($name);
        if ($option !== null && $option !== '') {
            return (string)$option;
        }

        return '';
    }

    private function buildHeaders(): array
    {
        if ($this->login !== '' && $this->apiKey !== '') {
            return ['User-Agent: RSSBridge/1.0 (RSS-Bridge; user #' . $this->login . ')'];
        }
        return ['User-Agent: RSSBridge/1.0 (RSS-Bridge anonymous)'];
    }

    public function collectData(): void
    {
        $this->login = $this->resolveCredential('login');
        $this->apiKey = $this->resolveCredential('api_key');

        $query = $this->normalizeQuery((string)($this->getInput('q') ?? ''));

        $limit = (int)($this->getInput('l') ?? 20);
        if ($limit <= 0) {
            $limit = 20;
        }
        if ($limit > 200) {
            $limit = 200;
        }

        $params = [
            'limit' => $limit,
            'page' => 1,
        ];
        if ($query !== '') {
            $params['tags'] = $query;
        }
        if ($this->login !== '') {
            $params['login'] = $this->login;
        }
        if ($this->apiKey !== '') {
            $params['api_key'] = $this->apiKey;
        }

        $url = self::URI . 'posts.json?' . http_build_query($params);

        $jsonString = getContents($url, $this->buildHeaders());
        if ($jsonString === '') {
            throwServerException(sprintf('Failed to fetch %s', $url));
        }

        $posts = json_decode($jsonString, true);
        if (is_array($posts) === false) {
            throwServerException('Invalid JSON response from Danbooru API');
        }

        if (isset($posts['success']) === true && $posts['success'] === false) {
            throwServerException('API error: ' . ($posts['message'] ?? $posts['reason'] ?? 'Unknown error'));
        }

        foreach ($posts as $post) {
            if (is_array($post) === false) {
                continue;
            }
            $item = $this->getItemFromElement($post);
            if ($item !== null) {
                $this->items[] = $item;
            }
        }
    }

    private function normalizeQuery(string $query): string
    {
        $query = trim($query);
        if ($query === '') {
            return '';
        }
        return preg_replace('/[,\s]+/', ' ', $query) ?? $query;
    }

    private function pickVariant(array $post): array
    {
        $byType = [];
        foreach ((array)($post['media_asset']['variants'] ?? []) as $variant) {
            if (is_array($variant) === true && isset($variant['type'], $variant['url']) === true) {
                $byType[(string)$variant['type']] = $variant;
            }
        }

        foreach (['sample', '360x360', '720x720', '180x180', 'original'] as $type) {
            if (isset($byType[$type]) === true) {
                return [
                    (string)$byType[$type]['url'],
                    (string)($byType[$type]['file_ext'] ?? ''),
                ];
            }
        }

        $fallbackUrl = (string)($post['large_file_url'] ?? '');
        if ($fallbackUrl === '') {
            $fallbackUrl = (string)($post['file_url'] ?? '');
        }
        return [$fallbackUrl, (string)($post['file_ext'] ?? '')];
    }

    private function fetchImageAsDataUri(string $url, string $ext): string
    {
        if ($ext === '') {
            $ext = strtolower(pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        }
        $mime = self::MIME_TYPES[$ext] ?? '';
        if ($mime === '') {
            return '';
        }

        $cacheKey = 'embed_' . md5($url);
        $cached = $this->loadCacheValue($cacheKey);
        if (is_string($cached) === true && $cached !== '') {
            return $cached;
        }

        try {
            $binary = getContents($url, $this->buildHeaders());
        } catch (\Throwable $e) {
            return '';
        }

        if ($binary === '') {
            return '';
        }

        $dataUri = sprintf('data:%s;base64,%s', $mime, base64_encode($binary));
        $this->saveCacheValue($cacheKey, $dataUri, self::EMBED_CACHE_TTL);
        return $dataUri;
    }

    private function getItemFromElement(array $post): ?array
    {
        $id = $post['id'] ?? null;
        if ($id === null) {
            return null;
        }

        $id = (int)$id;
        $pageUrl = self::URI . 'posts/' . $id;

        $createdAt = $post['created_at'] ?? null;
        $timestamp = null;
        if (is_string($createdAt) === true && $createdAt !== '') {
            $parsed = strtotime($createdAt);
            if ($parsed !== false) {
                $timestamp = $parsed;
            }
        }

        $originalUrl = (string)($post['file_url'] ?? '');
        $width = (int)($post['image_width'] ?? 0);
        $height = (int)($post['image_height'] ?? 0);

        [$variantUrl, $variantExt] = $this->pickVariant($post);

        $src = '';
        if ($variantUrl !== '') {
            $src = $this->fetchImageAsDataUri($variantUrl, $variantExt);
        }
        if ($src === '' && $originalUrl !== '') {
            $src = $originalUrl;
        }

        $content = '';
        if ($src !== '') {
            $content .= sprintf(
                '<p><a href="%s"><img src="%s" referrerpolicy="origin" alt="Post %d" style="max-width:100%%;height:auto;" /></a></p>',
                htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($src, ENT_QUOTES, 'UTF-8'),
                $id
            );
            $content .= sprintf('<p><b>Dimensions:</b> %d x %d</p>', $width, $height);
        }

        $rating = (string)($post['rating'] ?? '');
        $score = $post['score'] ?? null;
        if (is_array($score) === true) {
            $score = $score['up'] ?? null;
        }
        $meta = [];
        if ($rating !== '') {
            $meta[] = 'Rating: ' . strtoupper($rating);
        }
        if (is_numeric($score) === true) {
            $meta[] = 'Score: ' . (int)$score;
        }
        if ($meta !== []) {
            $content .= '<p><em>' . htmlspecialchars(implode(' • ', $meta), ENT_QUOTES, 'UTF-8') . '</em></p>';
        }

        return [
            'uri' => $pageUrl,
            'uid' => 'danbooru-' . $id,
            'title' => sprintf('Image %d', $id),
            'content' => $content,
            'timestamp' => $timestamp,
        ];
    }

    public function getName(): string
    {
        $query = $this->normalizeQuery((string)($this->getInput('q') ?? ''));
        return $query !== '' ? $query : parent::getName();
    }
}
