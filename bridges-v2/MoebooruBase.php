<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;
use RSSBridge\Utils\Json;

use function RSSBridge\Exceptions\throwClientException;
use function RSSBridge\Exceptions\throwServerException;

abstract class MoebooruBase extends BridgeAbstract
{
    public const CACHE_TIMEOUT = 1800;

    public const PARAMETERS = [
        '' => [
            'tags' => [
                'name' => 'Tags',
                'type' => 'text',
                'required' => false,
                'exampleValue' => 'rating:safe',
                'title' => 'Space-separated tag search query. Supports meta-tags like rating:safe, rating:questionable, rating:explicit, order:score, order:mpixels, user:username, etc.',
            ],
            'limit' => [
                'name' => 'Limit',
                'type' => 'number',
                'required' => false,
                'defaultValue' => 10,
                'exampleValue' => 10,
                'title' => 'Number of posts to fetch (api limit is 100)',
            ],
            'image_size' => [
                'name' => 'Image size',
                'type' => 'list',
                'values' => [
                    'Sample (optimized)' => 'sample',
                    'Original (full resolution)' => 'original',
                ],
                'defaultValue' => 'sample',
                'title' => 'Which image version to embed in the feed',
            ],
            'min_score' => [
                'name' => 'Minimum score',
                'type' => 'number',
                'required' => false,
                'defaultValue' => 0,
                'exampleValue' => 5,
                'title' => 'Filter out posts below this score (0 = no filter)',
            ],
            'include_tags' => [
                'name' => 'Include tags in title',
                'type' => 'checkbox',
                'defaultValue' => false,
                'title' => 'Append first tags to post title for quick filtering at a glance',
            ],
            'embed_images' => [
                'name' => 'Embed images',
                'type' => 'checkbox',
                'defaultValue' => false,
                'title' => 'Download images and embed them as data URIs in the feed',
            ],
        ],
    ];

    abstract protected static function getBaseApiUrl(): string;

    abstract protected static function getPostUrlTemplate(): string;

    abstract protected static function getBaseCategory(): string;

    public function collectData(): void
    {
        $tags = (string)$this->getInput('tags');

        $limitRaw = $this->getInput('limit');
        $limit = $limitRaw !== null && $limitRaw !== '' ? (int)$limitRaw : 20;

        $imageSizeRaw = $this->getInput('image_size');
        $imageSize = $imageSizeRaw !== null && $imageSizeRaw !== '' ? (string)$imageSizeRaw : 'sample';

        $includeTags = (bool)$this->getInput('include_tags');

        $minScoreRaw = $this->getInput('min_score');
        $minScore = $minScoreRaw !== null && $minScoreRaw !== '' ? (int)$minScoreRaw : 0;

        $embedImages = (bool)$this->getInput('embed_images');

        $limit = max(1, min(100, $limit));

        if ($embedImages === true) {
            $imageSize = 'sample';
        }

        $params = [
            'limit' => $limit,
            'page' => 1,
        ];

        if ($tags !== '') {
            $params['tags'] = $tags;
        }

        $apiUrl = static::getBaseApiUrl() . '?' . http_build_query($params);

        try {
            $response = getContents($apiUrl, ['Accept: application/json']);
        } catch (\Exception $e) {
            throwServerException('Failed to fetch data from API: ' . $e->getMessage());
        }

        try {
            $posts = Json::decode($response);
        } catch (\JsonException $e) {
            throwServerException('Invalid JSON response from API: ' . $e->getMessage());
        }

        if (is_array($posts) === false) {
            throwServerException('Unexpected response structure from API');
        }

        if (count($posts) === 0) {
            throwClientException('No posts found for the given criteria. Check your tags and filters.');
        }

        $itemsAdded = 0;
        foreach ($posts as $post) {
            if (is_array($post) === false) {
                continue;
            }

            $score = (int)($post['score'] ?? 0);
            if ($score < $minScore) {
                continue;
            }

            $status = (string)($post['status'] ?? '');
            if ($status === 'deleted' || $status === 'flagged') {
                continue;
            }

            $this->items[] = $this->createFeedItem($post, $imageSize, $includeTags, $embedImages);
            $itemsAdded++;
        }

        if ($itemsAdded === 0) {
            throwClientException('All posts were filtered out. Try lowering the minimum score or changing your tag query.');
        }
    }

    private function createFeedItem(array $post, string $imageSize, bool $includeTags, bool $embedImages): array
    {
        $id = (int)($post['id'] ?? 0);
        $author = (string)($post['author'] ?? 'Anonymous');
        $tags = (string)($post['tags'] ?? '');
        $rating = (string)($post['rating'] ?? 's');
        $createdAt = (int)($post['created_at'] ?? time());

        $title = "Post #{$id} by {$author}";
        if ($includeTags === true && $tags !== '') {
            $topTags = implode(', ', array_slice(explode(' ', $tags), 0, 5));
            $title .= " [{$topTags}]";
        }

        $imageUrl = $this->getImageUrl($post, $imageSize);

        $content = $this->buildContent($post, $imageUrl, $embedImages);

        $ratingText = $this->mapRating($rating);

        $categories = [static::getBaseCategory(), $ratingText];
        if ($tags !== '') {
            $tagArray = explode(' ', $tags);
            $cleanTags = array_values(array_filter($tagArray, fn($tag) => str_contains($tag, ':') === false));
            $categories = array_merge($categories, $cleanTags);
        }

        $md5 = (string)($post['md5'] ?? '');
        $uid = $md5 !== '' ? $md5 : (string)$id;

        return [
            'title' => $title,
            'uri' => static::getPostUrlTemplate() . $id,
            'content' => $content,
            'timestamp' => $createdAt,
            'author' => $author,
            'uid' => $uid,
            'categories' => $categories,
        ];
    }

    private function getImageUrl(array $post, string $size): string
    {
        $url = match ($size) {
            'original' => (string)($post['file_url'] ?? ''),
            default => (string)($post['sample_url'] ?? ''),
        };

        if ($url === '' && $size === 'sample') {
            $url = (string)($post['file_url'] ?? '');
        } elseif ($url === '' && $size === 'original') {
            $url = (string)($post['sample_url'] ?? '');
        }

        return $url;
    }

    private function mapRating(string $rating): string
    {
        return match ($rating) {
            's' => 'Safe',
            'q' => 'Questionable',
            'e' => 'Explicit',
            default => 'Unknown',
        };
    }

    private function buildContent(array $post, string $imageUrl, bool $embedImages): string
    {
        $id = (int)($post['id'] ?? 0);
        $width = (int)($post['width'] ?? 0);
        $height = (int)($post['height'] ?? 0);
        $score = (int)($post['score'] ?? 0);
        $source = (string)($post['source'] ?? '');
        $rating = (string)($post['rating'] ?? 's');
        $fileSize = (int)($post['file_size'] ?? 0);
        $fileExt = (string)($post['file_ext'] ?? '');

        $ratingText = $this->mapRating($rating);

        $parts = [];

        if ($imageUrl !== '') {
            $displayUrl = $imageUrl;

            if ($embedImages === true) {
                try {
                    $dataUri = media_embed_url_to_data_uri($imageUrl);
                    if ($dataUri !== '') {
                        $displayUrl = $dataUri;
                    }
                } catch (\Exception $e) {
                    $displayUrl = $imageUrl;
                }
            }

            $imgTag = '<img src="' . e($displayUrl) . '" alt="Post #' . e((string)$id) . '"';
            if ($width > 0 && $height > 0) {
                $displayWidth = min($width, 800);
                $imgTag .= ' width="' . e((string)$displayWidth) . '"';
            }
            $imgTag .= ' loading="lazy" />';

            $parts[] = '<p><a href="' . e(static::getPostUrlTemplate() . $id) . '">' . $imgTag . '</a></p>';
        }

        $meta = [];
        $meta[] = '<strong>Rating:</strong> ' . e($ratingText);
        $meta[] = '<strong>Score:</strong> ' . e((string)$score);

        if ($width > 0 && $height > 0) {
            $meta[] = '<strong>Dimensions:</strong> ' . e((string)$width) . '&times;' . e((string)$height);
        }

        if ($fileSize > 0) {
            $sizeStr = $this->formatFileSize($fileSize);
            if ($fileExt !== '') {
                $sizeStr .= ' (.' . e($fileExt) . ')';
            }
            $meta[] = '<strong>File size:</strong> ' . $sizeStr;
        }

        if ($source !== '') {
            $meta[] = '<strong>Source:</strong> <a href="' . e($source) . '">' . e($this->truncateUrl($source)) . '</a>';
        }

        $parts[] = '<p>' . implode('<br />', $meta) . '</p>';

        return implode("\n", $parts);
    }

    private function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
        }
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }

    private function truncateUrl(string $url): string
    {
        $parsed = parse_url($url);
        if ($parsed === false) {
            return $url;
        }

        $host = $parsed['host'] ?? '';
        $path = $parsed['path'] ?? '';

        $display = $host . $path;
        if (strlen($display) > 60) {
            return substr($display, 0, 57) . '...';
        }

        return $display;
    }
}
