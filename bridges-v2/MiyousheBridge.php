<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;
use Json;

final class MiyousheBridge extends BridgeAbstract
{
    public const NAME = 'MiHoYo Community (米游社)';
    public const URI = 'https://www.miyoushe.com/';
    public const DESCRIPTION = 'Official announcements from miHoYo games (Genshin Impact, Honkai, Zenless Zone Zero, etc.)';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600; // 1 hour

    private const GIDS_MAP = [
        '1' => '崩坏三',
        '2' => '原神',
        '3' => '崩坏二',
        '4' => '未定事件簿',
        '6' => '崩坏：星穹铁道',
        '8' => '绝区零',
    ];

    private const TYPE_MAP = [
        '1' => '公告',
        '2' => '活动',
        '3' => '资讯',
    ];

    private const GAME_SHORT_MAP = [
        '1' => 'bh3',
        '2' => 'ys',
        '3' => 'bh2',
        '4' => 'wd',
        '6' => 'sr',
        '8' => 'zzz',
    ];

    private const OFFICIAL_PAGE_MAP = [
        '1' => '6',
        '2' => '28',
        '3' => '31',
        '4' => '33',
        '6' => '53',
        '8' => '58',
    ];

    public const PARAMETERS = [
        '' => [
            'gids' => [
                'name' => 'Game',
                'type' => 'list',
                'values' => [
                    'Honkai Impact 3rd (崩坏三)' => '1',
                    'Genshin Impact (原神)' => '2',
                    'Honkai Gakuen 2 (崩坏二)' => '3',
                    'Tears of Themis (未定事件簿)' => '4',
                    'Honkai: Star Rail (崩坏：星穹铁道)' => '6',
                    'Zenless Zone Zero (绝区零)' => '8',
                ],
                'defaultValue' => '2',
                'title' => 'Select the game for announcements',
            ],
            'type' => [
                'name' => 'Type',
                'type' => 'list',
                'values' => [
                    'Announcements (公告)' => '1',
                    'Events (活动)' => '2',
                    'News (资讯)' => '3',
                ],
                'defaultValue' => '2',
                'title' => 'Type of announcements to fetch',
            ],
            'page_size' => [
                'name' => 'Page size',
                'type' => 'number',
                'required' => false,
                'defaultValue' => 20,
                'exampleValue' => 20,
                'title' => 'Number of announcements to fetch (1-100)',
            ],
            'last_id' => [
                'name' => 'Last ID',
                'type' => 'text',
                'required' => false,
                'exampleValue' => '',
                'title' => 'For pagination: skip announcements before this ID. Leave empty for latest announcements.',
            ],
        ],
    ];

    public function collectData(): void
    {
        $gids = (string)$this->getInput('gids');
        $type = (string)$this->getInput('type');

        $pageSizeRaw = $this->getInput('page_size');
        $pageSize = $pageSizeRaw !== null && $pageSizeRaw !== '' ? (int)$pageSizeRaw : 20;

        $lastId = (string)$this->getInput('last_id');

        // Validate gids and type
        if (array_key_exists($gids, self::GIDS_MAP) === false) {
            throwClientException('Invalid game ID. Please select a valid game from the dropdown.');
        }

        if (array_key_exists($type, self::TYPE_MAP) === false) {
            throwClientException('Invalid announcement type. Please select a valid type from the dropdown.');
        }

        // Enforce reasonable limits
        $pageSize = max(1, min(100, $pageSize));

        // Fetch news list
        $list = $this->getNewsList($gids, $type, $pageSize, $lastId);

        if (count($list) === 0) {
            throwClientException('No announcements found for the selected criteria.');
        }

        $successCount = 0;
        $failCount = 0;

        // Fetch full content for each post
        foreach ($list as $item) {
            if (is_array($item) === false || isset($item['post']) === false) {
                $failCount++;
                continue;
            }

            try {
                $feedItem = $this->getPostContent($item, $gids);
                if ($feedItem !== null) {
                    $this->items[] = $feedItem;
                    $successCount++;
                } else {
                    $failCount++;
                }
            } catch (\Exception $e) {
                $failCount++;
                continue;
            }
        }

        if ($successCount === 0) {
            throwServerException("Failed to fetch any valid announcements. {$failCount} posts failed to load. API may require authentication or have changed its structure.");
        }
    }

    private function getNewsList(string $gids, string $type, int $pageSize, string $lastId): array
    {
        $params = [
            'client_type' => '4',
            'gids' => $gids,
            'type' => $type,
            'page_size' => $pageSize,
        ];

        if ($lastId !== '') {
            $params['last_id'] = $lastId;
        }

        $url = 'https://bbs-api-static.miyoushe.com/painter/wapi/getNewsList?' . http_build_query($params);

        // Add required headers for miHoYo API
        $headers = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Referer: https://www.miyoushe.com/',
            'Accept: application/json',
        ];

        try {
            $response = getContents($url, $headers);
        } catch (\Exception $e) {
            throwServerException('Failed to fetch news list from miyoushe API: ' . $e->getMessage());
        }

        try {
            $data = Json::decode($response);
        } catch (\JsonException $e) {
            throwServerException('Invalid JSON response from miyoushe API: ' . $e->getMessage());
        }

        // Check for API-level errors
        if (isset($data['retcode']) === true && (int)$data['retcode'] !== 0) {
            $message = $data['message'] ?? 'Unknown API error';
            throwServerException('miyoushe API error (code ' . $data['retcode'] . '): ' . $message);
        }

        $list = $data['data']['list'] ?? [];
        if (is_array($list) === false) {
            throwServerException('Unexpected response structure: "data.list" is not an array');
        }

        return $list;
    }

    private function getPostContent(array $row, string $defaultGid): ?array
    {
        $post = $row['post'] ?? [];
        if (is_array($post) === false || isset($post['post_id']) === false) {
            return null;
        }

        $postId = (string)$post['post_id'];
        $url = 'https://bbs-api.miyoushe.com/post/wapi/getPostFull?post_id=' . urlencode($postId);

        // Cache full post content for 24 hours
        $cacheKey = 'miyoushe_post_' . $postId;
        $cached = $this->loadCacheValue($cacheKey);

        if ($cached !== null && is_array($cached) === true) {
            return $cached;
        }

        // Add required headers for miHoYo API
        $headers = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Referer: https://www.miyoushe.com/',
            'Accept: application/json',
        ];

        try {
            $response = getContents($url, $headers);
        } catch (\Exception $e) {
            // Skip this post if fetch fails
            return null;
        }

        try {
            $data = Json::decode($response);
        } catch (\JsonException $e) {
            return null;
        }

        // Check for API-level errors
        if (isset($data['retcode']) === true && (int)$data['retcode'] !== 0) {
            return null;
        }

        $fullRow = $data['data']['post'] ?? null;
        if ($fullRow === null || is_array($fullRow) === false) {
            return null;
        }

        $fullPost = $fullRow['post'] ?? [];
        $user = $fullRow['user'] ?? [];
        $topics = $fullRow['topics'] ?? [];

        if (is_array($fullPost) === false || isset($fullPost['post_id']) === false) {
            return null;
        }

        $gid = (string)($fullPost['game_id'] ?? $defaultGid);
        $author = (string)($user['nickname'] ?? '');
        $content = (string)($fullPost['content'] ?? '');
        $tags = [];

        if (is_array($topics) === true) {
            foreach ($topics as $topic) {
                if (is_array($topic) === true && isset($topic['name']) === true) {
                    $tags[] = (string)$topic['name'];
                }
            }
        }

        // Build description with cover images
        $hasCover = (bool)($row['post']['has_cover'] ?? false);
        $coverList = $row['cover_list'] ?? [];
        if (is_array($coverList) === false) {
            $coverList = [];
        }

        $description = $this->renderOfficialDescription($hasCover, $coverList, $content);

        $gameShort = self::GAME_SHORT_MAP[$gid] ?? 'ys';
        $link = 'https://www.miyoushe.com/' . $gameShort . '/article/' . $postId;

        $createdAt = (int)($fullPost['created_at'] ?? time());
        $title = (string)($fullPost['subject'] ?? '');

        $feedItem = [
            'title' => $title,
            'uri' => $link,
            'content' => $description,
            'timestamp' => $createdAt,
            'author' => $author,
            'uid' => $postId,
            'categories' => $tags,
        ];

        // Cache for 24 hours
        $this->saveCacheValue($cacheKey, $feedItem, 86400);

        return $feedItem;
    }

    private function renderOfficialDescription(bool $hasCover, array $coverList, string $content): string
    {
        $parts = [];

        // Add cover images if present
        if ($hasCover === true && count($coverList) > 0) {
            foreach ($coverList as $cover) {
                if (is_array($cover) === false || isset($cover['url']) === false) {
                    continue;
                }

                $url = (string)$cover['url'];
                if ($url !== '') {
                    $parts[] = '<img src="' . e($url) . '" loading="lazy" />';
                    $parts[] = '<br />';
                }
            }
        }

        // Add main content
        if ($content !== '') {
            $parts[] = $content;
        }

        return implode("\n", $parts);
    }
}
