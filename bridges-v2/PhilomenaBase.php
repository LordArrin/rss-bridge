<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

abstract class PhilomenaBase extends BridgeAbstract
{
    public const NAME = 'Philomena';
    public const URI = 'https://github.com/derpibooru/philomena';
    public const DESCRIPTION = 'Base bridge for Philomena-based imageboards (use a site-specific bridge instead)';
    public const MAINTAINER = 'LordArrin';
    public const CACHE_TIMEOUT = 1800;

    public const PARAMETERS = [
        'Global' => [
            'q' => [
                'name' => 'Query (Tags)',
                'required' => true,
                'title' => 'Tags for search, separated by commas or spaces (e.g., "tag1, tag2" or "tag1 tag2")'
            ],
            'exclude_tags' => [
                'name' => 'Blacklist (Exclude tags)',
                'required' => false,
                'title' => 'Tags for exclude, separated by commas or spaces (e.g., "tag1, tag2" or "tag1 tag2"). Posts containing ANY of these tags will be excluded from the feed'
            ],
            'sf' => [
                'name' => 'Sort By',
                'type' => 'list',
                'values' => [
                    'Creation date' => 'created_at',
                    'Score' => 'score',
                    'Wilson score' => 'wilson_score',
                    'Favorites' => 'faves',
                    'Upvotes' => 'upvotes',
                    'Views' => 'views',
                    'Comments' => 'comments',
                    'Random' => 'random'
                ],
                'defaultValue' => 'created_at'
            ],
            'sd' => [
                'name' => 'Sort Direction',
                'type' => 'list',
                'values' => [
                    'Descending' => 'desc',
                    'Ascending' => 'asc'
                ],
                'defaultValue' => 'desc'
            ],
            'limit' => [
                'name' => 'Posts Limit',
                'type' => 'number',
                'required' => false,
                'title' => 'Maximum number of posts to fetch (API allows up to 50)',
                'defaultValue' => 10
            ],
            'hide_tags' => [
                'name' => 'Hide tags and sources',
                'type' => 'checkbox',
                'required' => false,
                'defaultValue' => 'checked'
            ]
        ]
    ];

    /**
     * @return array<string, int|string>
     */
    protected static function getAvailableFilters(): array
    {
        return [];
    }

    protected static function getDefaultFilterId(): int
    {
        return 0;
    }

    public function getParameters(): array
    {
        $params = parent::getParameters();
        $filters = static::getAvailableFilters();
        if ($filters !== []) {
            $params['Global']['f'] = [
                'name' => 'Content Filter',
                'type' => 'list',
                'values' => $filters,
                'defaultValue' => static::getDefaultFilterId()
            ];
        }
        return $params;
    }

    /**
     * @param mixed $url
     * @return array<string, string>|null
     */
    public function detectParameters(mixed $url): ?array
    {
        if (get_class($this) === self::class) {
            return null;
        }

        $host = parse_url(static::URI, PHP_URL_HOST);
        if (is_string($host) === false) {
            return null;
        }

        $escapedHost = preg_quote($host, '/');
        $params = [];

        $regex = '/^(https?:\/\/)?(www\.)?' . $escapedHost . '\/search(?:\?.*)?/';
        if (preg_match($regex, $url) > 0) {
            $parsedUrl = parse_url($url);
            if (isset($parsedUrl['query']) === true) {
                parse_str($parsedUrl['query'], $queryParams);
                if (isset($queryParams['q']) === true && is_string($queryParams['q']) === true) {
                    $params['q'] = $queryParams['q'];
                }
                if (isset($queryParams['sf']) === true && is_string($queryParams['sf']) === true) {
                    $params['sf'] = $queryParams['sf'];
                }
                if (isset($queryParams['sd']) === true && is_string($queryParams['sd']) === true) {
                    $params['sd'] = $queryParams['sd'];
                }
                return $params;
            }
        }

        $regex = '/^(https?:\/\/)?(www\.)?' . $escapedHost . '\/tags\/([^\/&?\n]+)/';
        if (preg_match($regex, $url, $matches) > 0) {
            if (isset($matches[3]) === true) {
                $params['q'] = str_replace('-colon-', ':', urldecode($matches[3]));
                return $params;
            }
        }

        return null;
    }

    private function getNormalizedQuery(): string
    {
        $q = trim($this->getInput('q') ?? '');
        $excludeTags = trim($this->getInput('exclude_tags') ?? '');

        $tagsArray = [];

        if ($q !== '') {
            $qNormalized = preg_replace('/[\s,]+/', ', ', $q) ?? $q;
            $tagsArray = array_filter(
                array_map('trim', explode(',', $qNormalized)),
                fn(string $v): bool => $v !== ''
            );
        }

        if ($excludeTags !== '') {
            $excludesNormalized = preg_replace('/[\s,]+/', ', ', $excludeTags) ?? $excludeTags;
            $excludesArray = array_filter(
                array_map('trim', explode(',', $excludesNormalized)),
                fn(string $v): bool => $v !== ''
            );
            foreach ($excludesArray as $tag) {
                $cleanTag = ltrim($tag, '-');
                if ($cleanTag !== '') {
                    $tagsArray[] = '-' . $cleanTag;
                }
            }
        }

        return implode(', ', $tagsArray);
    }

    public function getName(): string
    {
        $q = $this->getNormalizedQuery();
        if ($q !== '') {
            return static::NAME . ': ' . $q;
        }
        return parent::getName();
    }

    public function getURI(): string
    {
        $q = $this->getNormalizedQuery();
        if ($q !== '') {
            $url = static::URI . 'search?';
            $params = [
                'q' => $q,
                'sf' => $this->getInput('sf') ?? 'created_at',
                'sd' => $this->getInput('sd') ?? 'desc'
            ];
            if ($this->getInput('f') !== null) {
                $params['filter_id'] = $this->getInput('f');
            }
            return $url . http_build_query($params);
        }
        return parent::getURI();
    }

    public function collectData(): void
    {
        if (get_class($this) === self::class) {
            throwClientException('Base bridge class. Use a site-specific bridge instead (e.g., DerpibooruBridge or TrixiebooruBridge).');
        }

        $q = $this->getNormalizedQuery();

        if ($q === '') {
            throwClientException('Query cannot be empty.');
        }

        $f = $this->getInput('f') ?? static::getDefaultFilterId();
        $sf = $this->getInput('sf') ?? 'created_at';
        $sd = $this->getInput('sd') ?? 'desc';
        $limit = $this->getInput('limit') ?? 10;
        $hideTags = $this->getInput('hide_tags') ?? false;

        $limit = min(50, max(1, (int) $limit));

        $apiUrl = $this->buildApiUrl($q, (int) $f, $sf, $sd, $limit);

        try {
            $jsonString = getContents($apiUrl);
            $json = json_decode($jsonString, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throwClientException('Failed to parse API response: ' . $e->getMessage());
        }

        if (is_object($json) === false || isset($json->images) === false) {
            throwClientException('Invalid API response.');
        }

        if ($json->images === []) {
            return;
        }

        foreach ($json->images as $post) {
            if (isset($post->id) === false) {
                continue;
            }

            $postId = (int) $post->id;
            $postUri = static::URI . 'images/' . $postId;
            $artist = $this->extractArtist($post->tags ?? []);

            $title = $artist !== '' ? sprintf('Image %d by %s', $postId, $artist) : sprintf('Image %d', $postId);

            $timestamp = time();
            if (isset($post->created_at) === true && is_string($post->created_at) === true) {
                $parsed = strtotime($post->created_at);
                if ($parsed !== false) {
                    $timestamp = $parsed;
                }
            }

            $author = isset($post->uploader) === true ? (string) $post->uploader : 'Anonymous';

            $this->items[] = [
                'uri' => $postUri,
                'title' => $title,
                'timestamp' => $timestamp,
                'author' => $author,
                'content' => $this->buildPostHtml($post, $postUri, $postId, (bool) $hideTags),
            ];
        }
    }

    private function buildApiUrl(string $query, int $filter, string $sf, string $sd, int $limit): string
    {
        return sprintf(
            '%sapi/v1/json/search/images?filter_id=%s&q=%s&sf=%s&sd=%s&per_page=%d',
            static::URI,
            urlencode((string) $filter),
            urlencode($query),
            urlencode($sf),
            urlencode($sd),
            $limit
        );
    }

    /**
     * @param list<string> $tags
     */
    private function extractArtist(array $tags): string
    {
        foreach ($tags as $tag) {
            if (strpos($tag, 'artist:') === 0) {
                return substr($tag, 7);
            }
        }
        return '';
    }

    private function buildPostHtml(object $post, string $postUri, int $postId, bool $hideTags): string
    {
        $html = '';

        $hasMimeType = isset($post->mime_type) === true;
        $isVideoMimeType = $hasMimeType === true && strpos((string) $post->mime_type, 'video/') === 0;

        $hasFormat = isset($post->format) === true;
        $isVideoFormat = $hasFormat === true && in_array((string) $post->format, ['webm', 'mp4'], true) === true;

        $isVideo = $isVideoMimeType === true || $isVideoFormat === true;

        $mediaUrl = isset($post->representations->full) === true ? (string) $post->representations->full : '';
        $thumbUrl = isset($post->representations->medium) === true ? (string) $post->representations->medium : (isset($post->representations->small) === true ? (string) $post->representations->small : $mediaUrl);

        if ($isVideo === true && $mediaUrl !== '') {
            $html .= sprintf(
                '<p><a href="%s"><video controls loop muted preload="metadata" style="max-width:100%%;height:auto;" src="%s"></video></a></p>',
                $postUri,
                htmlspecialchars($mediaUrl)
            );
        } elseif ($thumbUrl !== '') {
            $html .= sprintf(
                '<p><a href="%s"><img src="%s" alt="Image %d"></a></p>',
                $postUri,
                htmlspecialchars($thumbUrl),
                $postId
            );
        }

        $hasDescription = isset($post->description) === true;
        if ($hasDescription === true && is_string($post->description) === true && $post->description !== '') {
            $cleanDesc = $this->cleanDescription($post->description);
            if ($cleanDesc !== '') {
                $html .= sprintf(
                    '<p><b>Description:</b><br>%s</p>',
                    nl2br(htmlspecialchars($cleanDesc))
                );
            }
        }

        $width = isset($post->width) === true ? (string) $post->width : '?';
        $height = isset($post->height) === true ? (string) $post->height : '?';
        $score = isset($post->score) === true ? (string) $post->score : 'N/A';

        $html .= sprintf(
            '<p><b>Size:</b> %sx%s | <b>Score:</b> %s</p>',
            $width,
            $height,
            $score
        );

        if ($hideTags === false) {
            $hasSources = isset($post->source_urls) === true;
            if ($hasSources === true && is_array($post->source_urls) === true && $post->source_urls !== []) {
                $sources = '';
                foreach ($post->source_urls as $source) {
                    $sources .= sprintf(
                        '<a href="%s" rel="noopener noreferrer">%s</a><br>',
                        htmlspecialchars((string) $source),
                        htmlspecialchars((string) $source)
                    );
                }
                $html .= sprintf('<p><b>Sources:</b><br>%s</p>', $sources);
            }

            $hasTags = isset($post->tags) === true;
            if ($hasTags === true && is_array($post->tags) === true && $post->tags !== []) {
                $html .= sprintf(
                    '<p><b>Tags:</b> %s</p>',
                    htmlspecialchars(implode(', ', $post->tags))
                );
            }
        }

        return $html;
    }

    private function cleanDescription(string $text): string
    {
        $patterns = [
            '/\[([^\]]+)\]\([^)]+\)/' => '$1',
            '/!\[([^\]]*)\]\([^)]+\)/' => '',
            '/^#+\s+/m' => '',
            '/^>\s+/m' => '',
            '/```([^`]+)```/' => '$1',
            '/`([^`]+)`/' => '$1',
            '/^[-*_]{3,}$/m' => '',
        ];

        $text = preg_replace(array_keys($patterns), array_values($patterns), $text) ?? $text;
        $text = str_replace(['*', '\\'], '', $text);
        $text = preg_replace('/[^\x00-\x7F\x{0400}-\x{04FF}\p{P}\s]/u', '', $text) ?? $text;
        $text = preg_replace(['/\n{3,}/', '/ {2,}/'], ["\n\n", ' '], $text) ?? $text;

        return trim($text);
    }
}
