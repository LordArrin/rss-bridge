<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class Vk2Bridge extends BridgeAbstract
{
    public const NAME = 'VK';
    public const URI = 'https://vk.ru';
    public const DESCRIPTION = 'Returns posts from the public feed. Needs personal API key';
    public const MAINTAINER = 'LordArrin';
    public const CACHE_TIMEOUT = 900;

    private const VK_API_VER = 5.199;
    private const VK_API_MAX_COUNT = 100;
    private const DEFAULT_POST_LIMIT = 10;
    private const MAX_TITLE_LENGTH = 60;
    private const MAX_PREVIEW_LENGTH = 200;
    private const MIN_TITLE_SPACE_POS = 30;
    private const MAX_PAGES = 5;
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY_US = 500_000;

    private const URL_REGEX = '/^https?:\/\/(?:www\.|m\.)?vk\.ru\/([a-zA-Z0-9_.]+)\/?$/i';

    private const SYSTEM_PAGES = [
        'video', 'audio', 'photos', 'messages', 'feed', 'friends', 'groups', 'settings',
        'login', 'reg', 'restore', 'im', 'mail', 'news', 'search', 'apps', 'games',
        'gifts', 'support', 'write', 'wall', 'board', 'albums', 'docs', 'topics',
        'public', 'event', 'market', 'contacts', 'about', 'reviews', 'edit',
    ];

    private const RATE_LIMIT_DELAYS = [
        self::API_ERR_RATE_LIMIT => 15,
        self::API_ERR_RATE_LIMIT_EXTENDED => 1800,
    ];

    private const API_ERR_UNKNOWN_METHOD = 3;
    private const API_ERR_RATE_LIMIT = 6;
    private const API_ERR_CAPTCHA_NEEDED = 14;
    private const API_ERR_ACCESS_DENIED = 15;
    private const API_ERR_USER_DELETED = 18;
    private const API_ERR_RATE_LIMIT_EXTENDED = 29;

    private const ERR_OWNER_NOT_FOUND = 'owner_not_found';
    private const ERR_INVALID_API_RESPONSE = 'invalid_api_response';
    private const ERR_MISSING_ACCESS_TOKEN = 'missing_access_token';
    private const ERR_INVALID_JSON = 'invalid_json';
    private const ERR_API_ERROR = 'api_error';
    private const ERR_NO_POSTS_FOUND = 'no_posts_found';
    private const ERR_CAPTCHA_NEEDED = 'captcha_needed';
    private const ERR_ACCESS_DENIED = 'access_denied';
    private const ERR_USER_DELETED = 'user_deleted';
    private const ERR_UNKNOWN_METHOD = 'unknown_method';
    private const ERR_RATE_LIMIT = 'rate_limit';
    private const ERR_RATE_LIMIT_EXTENDED = 'rate_limit_extended';

    private const ERROR_MESSAGES = [
        self::ERR_OWNER_NOT_FOUND => 'Could not detect owner id. Check the short name.',
        self::ERR_INVALID_API_RESPONSE => 'Invalid API response from',
        self::ERR_MISSING_ACCESS_TOKEN => 'Access token is required.',
        self::ERR_INVALID_JSON => 'Invalid JSON response from VK API.',
        self::ERR_API_ERROR => 'API returned error:',
        self::ERR_NO_POSTS_FOUND => 'Feed is empty:',
        self::ERR_CAPTCHA_NEEDED => 'Captcha required:',
        self::ERR_ACCESS_DENIED => 'Access denied. The wall or group might be private.',
        self::ERR_USER_DELETED => 'User was deleted or banned.',
        self::ERR_UNKNOWN_METHOD => 'Unknown API method.',
    ];

    public const PARAMETERS = [
        [
            'u' => [
                'name' => 'Name of group or profile',
                'type' => 'text',
                'required' => true,
                'exampleValue' => 'rebel_jack',
                'title' => 'Name from URL. Example: rebel_jack from https://vk.ru/rebel_jack',
            ],
            'hide_reposts' => [
                'name' => 'Hide reposts',
                'type' => 'checkbox',
                'title' => 'Check this box to hide reposts from feed items',
            ],
            'limit' => [
                'name' => 'Number of posts',
                'type' => 'number',
                'defaultValue' => self::DEFAULT_POST_LIMIT,
            ],
        ],
    ];

    public const CONFIGURATION = [
        'access_token' => [
            'required' => true,
        ],
    ];

    public const TEST_DETECT_PARAMETERS = [
        'https://vk.ru/rebel_jack' => ['u' => 'rebel_jack'],
    ];

    /** @var array<int, string> */
    private array $ownerNames = [];
    private ?string $pageName = null;
    private ?string $iconUrl = null;

    /** @var array<string, string> */
    private array $photoDescriptions = [];

    public function getURI(): string
    {
        $u = $this->getInput('u');
        return $u !== null && $u !== '' ? static::URI . '/' . $u : parent::getURI();
    }

    public function getName(): string
    {
        return $this->pageName ?? parent::getName();
    }

    public function getIcon(): string
    {
        return $this->iconUrl !== null ? $this->proxyImage($this->iconUrl) : parent::getIcon();
    }

    public function detectParameters($url): ?array
    {
        if (preg_match(self::URL_REGEX, $url, $m) === 0) {
            return null;
        }
        $name = strtolower($m[1]);
        if (in_array($name, self::SYSTEM_PAGES, true) === true) {
            return null;
        }
        return ['u' => $m[1]];
    }

    public function collectData(): void
    {
        if ($this->cache->get($this->getRateLimitCacheKey()) === true) {
            throwRateLimitException();
        }

        $ownerId = $this->detectOwnerId($this->getInput('u'));
        $limitInput = $this->getInput('limit');
        $targetCount = max(1, min(self::VK_API_MAX_COUNT, (int) ($limitInput ?? self::DEFAULT_POST_LIMIT)));
        $hideReposts = (bool) $this->getInput('hide_reposts');
        $filteredPosts = [];
        $offset = 0;

        for (
            $page = 0;
            ($page < self::MAX_PAGES) === true && (count($filteredPosts) < $targetCount) === true;
            $page++
        ) {
            $batchSize = min(self::VK_API_MAX_COUNT, max($targetCount - count($filteredPosts) + 10, 10));

            $r = $this->api('wall.get', [
                'owner_id' => $ownerId,
                'extended' => '1',
                'count' => $batchSize,
                'offset' => $offset,
                'fields' => 'photo_200,photo_100,photo_50',
            ]);

            if (isset($r['response']['items']) === false) {
                $this->handleError(self::ERR_INVALID_API_RESPONSE, 'wall.get');
            }

            $this->cacheOwnerData($r['response'], $ownerId);
            $items = $r['response']['items'];

            if ($items === []) {
                break;
            }

            foreach ($items as $post) {
                if (($post['marked_as_ads'] ?? 0) === 1 || ($post['is_pinned'] ?? 0) === 1) {
                    continue;
                }
                if ($hideReposts === true && $this->isRepost($post) === true) {
                    continue;
                }
                if (
                    ($post['is_deleted'] ?? false) === true
                    && trim($post['text'] ?? '') === ''
                    && ($post['attachments'] ?? []) === []
                ) {
                    continue;
                }
                $filteredPosts[] = $post;
            }

            $offset += count($items);

            if ((count($items) < $batchSize) === true) {
                break;
            }
        }

        if ($filteredPosts === []) {
            $reason = $hideReposts === true ? 'No original posts found after filtering reposts.' : 'No posts found in the feed.';
            $this->handleError(self::ERR_NO_POSTS_FOUND, $reason);
        }

        $postsForFeed = array_slice($filteredPosts, 0, $targetCount);
        $this->generateFeed($postsForFeed, $ownerId);

        $groupName = $this->getInput('u');
        if ($groupName !== null && $groupName !== '') {
            $this->processArticlesFromPosts($postsForFeed, $groupName);
        }
    }

    protected function getPostURI(array $post): string
    {
        $uri = sprintf(
            'https://vk.ru/wall%d_%d',
            $post['owner_id'] ?? 0,
            $post['id'] ?? 0
        );
        if (isset($post['reply_post_id']) === false) {
            return $uri;
        }
        $threadId = $post['parents_stack'][0] ?? $post['reply_post_id'];
        return sprintf('%s?reply=%d&thread=%d', $uri, $post['id'] ?? 0, $threadId);
    }

    protected function generateContentFromPost(array $post, bool $extractTitle = true): string
    {
        $text = $post['text'] ?? '';
        if ($extractTitle === true) {
            [, $text] = $this->splitFirstLine($text);
        }

        $text = trim($text ?? '');
        $ret = '';

        if ($text !== '') {
            $placeholders = [];
            $counter = 0;

            $text = $this->applyPlaceholders(
                $text,
                $placeholders,
                $counter,
                '/\[([^\]|]+)\|([^\]]+)\]/u',
                fn(array $m): string => $this->resolveVkLink(trim($m[1]), $m[2])
            );

            $text = $this->applyPlaceholders(
                $text,
                $placeholders,
                $counter,
                '/#([\p{L}0-9_]+(?:@[\p{L}0-9_.]+)?)/u',
                fn(array $m): string => $this->safeLink('https://vk.ru/feed?q=%23' . urlencode($m[1]), '#' . $m[1])
            );

            $text = $this->applyPlaceholders(
                $text,
                $placeholders,
                $counter,
                '~(https?://[^\s<|]+)|((?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}(?:/[^\s<|]*)?)~i',
                function (array $m): ?string {
                    if (($m[1] ?? '') !== '') {
                        return $this->safeLink($m[1], $m[1]);
                    }
                    if (($m[2] ?? '') !== '') {
                        return $this->safeLink('https://' . $m[2], $m[2]);
                    }
                    return null;
                }
            );

            $ret = $this->e($text);
            if ($placeholders !== []) {
                $ret = str_replace(array_keys($placeholders), array_values($placeholders), $ret);
            }
            $ret = '<p>' . nl2br($ret) . '</p>';
        }

        foreach ($post['attachments'] ?? [] as $attachment) {
            $ret .= $this->renderAttachment($attachment);
        }

        return $ret;
    }

    protected function renderAttachment(array $attachment): string
    {
        $type = $attachment['type'] ?? '';
        $d = $attachment[$type] ?? [];

        return match ($type) {
            'photo' => (function () use ($d): string {
                $key = ($d['owner_id'] ?? 0) . '_' . ($d['id'] ?? 0);
                $alt = $d['text'] ?? ($this->photoDescriptions[$key] ?? '');
                return "<p>{$this->image($this->getLargestImageUrl($d['sizes'] ?? []), $alt)}</p>";
            })(),
            'video' => $this->renderVideo($d),
            'clip' => $this->renderClip($d),
            'audio' => $this->renderAudio($d),
            'doc' => $this->renderDocAttachment($d),
            'link' => (function () use ($d): string {
                $url = str_replace('https://m.vk.ru', 'https://vk.ru', $d['url'] ?? '#');
                $normalized = $this->normalizePlaylistUrl($url);
                $isPlaylist = $normalized !== $url;
                $url = $normalized;
                $img = ($isPlaylist === false && isset($d['photo']['sizes']) === true) ? $this->getLargestImageUrl($d['photo']['sizes']) : '';
                $title = $isPlaylist === true ? 'Playlist: ' . ($d['title'] ?? $url) : ($d['title'] ?? $url);
                return $this->renderLinkCard($url, $title, $img);
            })(),
            'note' => $this->renderLinkCard($d['view_url'] ?? '#', $d['title'] ?? 'Note'),
            'poll' => $this->renderPoll($d),
            'album' => $this->renderLinkCard(
                'https://vk.ru/album' . ($d['owner_id'] ?? 0) . '_' . ($d['id'] ?? 0),
                'Album: ' . ($d['title'] ?? ''),
                $this->getLargestImageUrl($d['thumb']['sizes'] ?? [])
            ),
            'article' => $this->renderLinkCard(
                $d['view_url'] ?? '#',
                $d['title'] ?? 'Article',
                $this->getLargestImageUrl($d['photo']['sizes'] ?? [])
            ),
            'wall' => $this->renderWall($d),
            'market' => (function () use ($d): string {
                $price = $d['price']['text'] ?? '';
                $display = $price !== '' ? ($d['title'] ?? 'Product') . ' - ' . $price : ($d['title'] ?? 'Product');
                $img = ($d['thumb_photo'] ?? '') !== '' ? $this->proxyImage($d['thumb_photo']) : '';
                return $this->renderLinkCard($d['url'] ?? '#', $display, $img);
            })(),
            'audio_playlist' => $this->renderLinkCard(
                'https://vk.ru/music/playlist/' . ($d['owner_id'] ?? 0) . '_' . ($d['id'] ?? 0),
                'Playlist: ' . ($d['title'] ?? '') . ' (' . ($d['count'] ?? 0) . ' tracks)'
            ),
            'video_playlist' => $this->renderLinkCard(
                'https://vk.ru/video/playlist/' . ($d['owner_id'] ?? 0) . '_' . ($d['id'] ?? 0),
                'Video playlist: ' . ($d['title'] ?? '') . ' (' . ($d['count'] ?? 0) . ')',
                $this->getLargestImageUrl($d['photo'] ?? [])
            ),
            'podcast' => $this->renderPodcast($d),
            'event' => $this->renderEvent($d),
            'graffiti' => (($url = $d['photo_586'] ?? $d['photo_200'] ?? '') !== '') ? "<p>{$this->image($this->proxyImage($url), 'Graffiti')}</p>" : '',
            'group' => $this->renderLinkCard(
                ($d['screen_name'] ?? '') !== '' ? 'https://vk.ru/' . $d['screen_name'] : '#',
                $d['name'] ?? 'Group',
                ($img = $d['photo_200'] ?? $d['photo_100'] ?? $d['photo_50'] ?? '') !== '' ? $this->proxyImage($img) : ''
            ),
            'donut_link' => $this->renderLinkCard($d['url'] ?? '#', $d['text'] ?? 'VK Donut'),
            'textlive', 'textpost', 'textpost_publish' => (function () use ($d): string {
                $preview = mb_substr($d['text'] ?? '', 0, self::MAX_PREVIEW_LENGTH);
                $extra = $preview !== '' ? "<p><small>{$this->e($preview)}</small></p>" : '';
                return $this->renderLinkCard($d['url'] ?? '#', $d['title'] ?? 'Text broadcast', '', $extra);
            })(),
            'situational_theme' => $this->renderLinkCard($d['url'] ?? '#', $d['title'] ?? ''),
            'sticker' => $this->renderSticker($d),
            default => "<p>Unknown attachment type: {$this->e($type)}</p>",
        };
    }

    protected function getTitle(array $post): string
    {
        [$title] = $this->splitFirstLine($post['text'] ?? '');
        if ($title !== '') {
            return $title;
        }
        foreach ($post['attachments'] ?? [] as $attachment) {
            $t = $this->getAttachmentTitle($attachment);
            if ($t !== '') {
                return $t;
            }
        }
        return 'untitled';
    }

    protected function api(string $method, array $params, array $expectedErrorCodes = []): array
    {
        $accessToken = $this->getOption('access_token');
        if ($accessToken === null || $accessToken === '') {
            $this->handleError(self::ERR_MISSING_ACCESS_TOKEN);
        }

        $params['v'] = self::VK_API_VER;
        $url = 'https://api.vk.ru/method/' . $method . '?' . http_build_query($params);
        $retryDelayUs = self::RETRY_DELAY_US;

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $response = getContents($url, ['Authorization: Bearer ' . $accessToken]);
            } catch (\Exception $e) {
                if ($attempt < self::MAX_RETRIES) {
                    usleep($retryDelayUs);
                    $retryDelayUs *= 2;
                    continue;
                }
                $this->handleError(self::ERR_API_ERROR, 'Network error: ' . $e->getMessage());
            }

            if (json_validate($response) === false) {
                $this->handleError(self::ERR_INVALID_JSON);
            }

            $r = json_decode($response, true);

            if (is_array($r) === false) {
                $this->handleError(self::ERR_INVALID_JSON);
            }

            if (isset($r['error']) === false) {
                return $r;
            }

            $errorCode = $r['error']['error_code'] ?? 0;
            $apiError = $this->mapApiErrorCode($errorCode);

            if (
                $apiError === self::ERR_RATE_LIMIT
                && ($attempt < self::MAX_RETRIES) === true
            ) {
                usleep($retryDelayUs);
                $retryDelayUs *= 2;
                continue;
            }

            if ($apiError === self::ERR_CAPTCHA_NEEDED) {
                $this->cache->set($this->getRateLimitCacheKey(), true, 300);
                $this->handleError(self::ERR_CAPTCHA_NEEDED, $r['error']['error_msg'] ?? 'Captcha needed');
            }

            if ($apiError === self::ERR_ACCESS_DENIED) {
                $this->handleError(self::ERR_ACCESS_DENIED, $r['error']['error_msg'] ?? 'Access denied');
            }

            if ($apiError === self::ERR_USER_DELETED) {
                $this->handleError(self::ERR_USER_DELETED, $r['error']['error_msg'] ?? 'User deleted or banned');
            }

            if ($apiError === self::ERR_UNKNOWN_METHOD) {
                $this->handleError(self::ERR_UNKNOWN_METHOD, $r['error']['error_msg'] ?? 'Unknown method');
            }

            if (in_array($errorCode, $expectedErrorCodes, true) === true) {
                return $r;
            }

            if (isset(self::RATE_LIMIT_DELAYS[$errorCode]) === true) {
                $this->cache->set($this->getRateLimitCacheKey(), true, self::RATE_LIMIT_DELAYS[$errorCode]);
            }

            $this->handleError(
                self::ERR_API_ERROR,
                ($r['error']['error_msg'] ?? 'Unknown error') . " ({$errorCode})"
            );
        }

        throw new \RuntimeException('VK API: max retries exceeded for ' . $method);
    }

    private function mapApiErrorCode(int $code): ?string
    {
        return match ($code) {
            self::API_ERR_UNKNOWN_METHOD => self::ERR_UNKNOWN_METHOD,
            self::API_ERR_RATE_LIMIT => self::ERR_RATE_LIMIT,
            self::API_ERR_CAPTCHA_NEEDED => self::ERR_CAPTCHA_NEEDED,
            self::API_ERR_ACCESS_DENIED => self::ERR_ACCESS_DENIED,
            self::API_ERR_USER_DELETED => self::ERR_USER_DELETED,
            self::API_ERR_RATE_LIMIT_EXTENDED => self::ERR_RATE_LIMIT_EXTENDED,
            default => null,
        };
    }

    private function generateFeed(array $posts, int $ownerId): void
    {
        $this->fetchPhotoDescriptions($posts);

        foreach ($posts as $post) {
            $displayPost = $this->isRepost($post) === true ? $post['copy_history'][0] : $post;
            $uri = $this->getPostURI($displayPost);

            $cacheKey = 'vk2_post_' . md5(
                $uri . '|' . ($displayPost['date'] ?? '') . '|' . ($displayPost['edited'] ?? '')
            );
            $cachedItem = $this->cache->get($cacheKey);

            if ($cachedItem !== null) {
                $this->items[] = $cachedItem;
                continue;
            }

            $fromId = $displayPost['from_id'] ?? $displayPost['owner_id'] ?? 0;
            $author = $fromId !== $ownerId ? ($this->ownerNames[$fromId] ?? 'Unknown') : '';
            $isDeleted = ($displayPost['is_deleted'] ?? false) === true;

            $content = $this->generateContentFromPost($displayPost, true);
            if ($isDeleted === true) {
                $content = '<p><em>[Deleted]</em></p>' . $content;
            }

            $item = [
                'content' => $content,
                'timestamp' => $displayPost['date'] ?? time(),
                'author' => $author,
                'title' => ($isDeleted === true ? '[Deleted] ' : '') . $this->getTitle($displayPost),
                'uri' => $uri,
                'uid' => sprintf('vk:wall%d_%d', $displayPost['owner_id'] ?? 0, $displayPost['id'] ?? 0),
            ];

            $this->cache->set($cacheKey, $item, self::CACHE_TIMEOUT);
            $this->items[] = $item;
        }

        if (isset($this->ownerNames[$ownerId]) === true) {
            $this->pageName = $this->ownerNames[$ownerId];
        }
    }

    private function fetchPhotoDescriptions(array $posts): void
    {
        $photoKeys = [];

        foreach ($posts as $post) {
            foreach ($post['attachments'] ?? [] as $attachment) {
                if (($attachment['type'] ?? '') !== 'photo') {
                    continue;
                }
                $p = $attachment['photo'] ?? [];
                $oid = $p['owner_id'] ?? 0;
                $pid = $p['id'] ?? 0;
                if ($oid !== 0 && $pid !== 0) {
                    $photoKeys[] = $oid . '_' . $pid;
                }
            }
        }

        if ($photoKeys === []) {
            return;
        }

        $r = $this->api(
            'photos.getById',
            ['photos' => implode(',', array_unique($photoKeys))],
            [self::API_ERR_RATE_LIMIT, self::API_ERR_RATE_LIMIT_EXTENDED]
        );

        foreach ($r['response'] ?? [] as $photo) {
            $key = ($photo['owner_id'] ?? 0) . '_' . ($photo['id'] ?? 0);
            $this->photoDescriptions[$key] = $photo['text'] ?? '';
        }
    }

    private function cacheOwnerData(array $response, int $ownerId): void
    {
        foreach ($response['profiles'] ?? [] as $profile) {
            $this->ownerNames[$profile['id']] = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''));
            if ($profile['id'] === $ownerId) {
                $this->iconUrl = $profile['photo_100'] ?? $profile['photo_50'] ?? $this->iconUrl;
            }
        }

        foreach ($response['groups'] ?? [] as $group) {
            $id = -(int) $group['id'];
            $this->ownerNames[$id] = $group['name'] ?? 'Unknown';
            if ($id === $ownerId) {
                $this->iconUrl = $group['photo_200'] ?? $group['photo_100'] ?? $group['photo_50'] ?? $this->iconUrl;
            }
        }
    }

    private function isRepost(array $post): bool
    {
        return isset($post['copy_history'][0]) === true;
    }

    private function detectOwnerId(string $u): int
    {
        if (preg_match('/^(club|public)(\d+)$/', $u, $m) === 1) {
            return -intval($m[2]);
        }
        if (preg_match('/^id(\d+)$/', $u, $m) === 1) {
            return intval($m[1]);
        }

        $cacheKey = 'vk2_owner_' . md5(strtolower($u));
        $cached = $this->cache->get($cacheKey);

        if (is_array($cached) === true && isset($cached['ownerId']) === true) {
            $this->iconUrl = $cached['iconUrl'] ?? null;
            return (int) $cached['ownerId'];
        }

        $r = $this->api('groups.getById', ['group_ids' => $u, 'fields' => 'photo_200,photo_100,photo_50'], [self::API_ERR_RATE_LIMIT]);
        $group = $r['response']['groups'][0] ?? $r['response'][0] ?? null;

        if ($group !== null && isset($group['id']) === true) {
            $ownerId = -(int) $group['id'];
            $this->iconUrl = $group['photo_200'] ?? $group['photo_100'] ?? $group['photo_50'] ?? null;
            $this->cache->set($cacheKey, ['ownerId' => $ownerId, 'iconUrl' => $this->iconUrl], 86400);
            return $ownerId;
        }

        $r = $this->api('users.get', ['user_ids' => $u, 'fields' => 'photo_100,photo_50']);

        if (isset($r['response'][0]['id']) === true) {
            $user = $r['response'][0];
            $this->iconUrl = $user['photo_100'] ?? $user['photo_50'] ?? null;
            $this->cache->set($cacheKey, ['ownerId' => $user['id'], 'iconUrl' => $this->iconUrl], 86400);
            return $user['id'];
        }

        $this->handleError(self::ERR_OWNER_NOT_FOUND, "Short name '{$u}'");
    }

    private function processArticlesFromPosts(array $posts, string $groupName): void
    {
        /** @var array<string, array{url: string, timestamp: int, author: string}> $articles */
        $articles = [];
        $groupLower = strtolower($groupName);

        foreach ($posts as $post) {
            $displayPost = $this->isRepost($post) === true ? $post['copy_history'][0] : $post;
            $postTimestamp = (int) ($displayPost['date'] ?? time());
            $fromId = (int) ($displayPost['from_id'] ?? $displayPost['owner_id'] ?? 0);
            $postAuthor = $fromId !== 0 ? ($this->ownerNames[$fromId] ?? 'Unknown') : '';

            $text = $post['text'] ?? '';
            foreach ($this->findArticleLinks($text, $groupLower) as $url) {
                $norm = $this->normalizeArticleUrl($url);
                if (isset($articles[$norm]) === false) {
                    $articles[$norm] = [
                        'url' => $url,
                        'timestamp' => $postTimestamp,
                        'author' => $postAuthor,
                    ];
                }
            }

            foreach ($post['attachments'] ?? [] as $attachment) {
                $type = $attachment['type'] ?? '';

                if ($type === 'article') {
                    $viewUrl = $attachment['article']['view_url'] ?? '';
                    if ($viewUrl !== '' && $this->isArticleUrl($viewUrl, $groupLower) === true) {
                        $norm = $this->normalizeArticleUrl($viewUrl);
                        if (isset($articles[$norm]) === false) {
                            $articles[$norm] = [
                                'url' => $viewUrl,
                                'timestamp' => $postTimestamp,
                                'author' => $postAuthor,
                            ];
                        }
                    }
                }

                if ($type === 'link') {
                    $linkUrl = $attachment['link']['url'] ?? '';
                    if ($this->isArticleUrl($linkUrl, $groupLower) === true) {
                        $norm = $this->normalizeArticleUrl($linkUrl);
                        if (isset($articles[$norm]) === false) {
                            $articles[$norm] = [
                                'url' => $linkUrl,
                                'timestamp' => $postTimestamp,
                                'author' => $postAuthor,
                            ];
                        }
                    }
                }
            }

            $copyHistory = $post['copy_history'] ?? [];
            foreach ($copyHistory as $repost) {
                $repostTimestamp = (int) ($repost['date'] ?? $postTimestamp);
                $repostFromId = (int) ($repost['from_id'] ?? $repost['owner_id'] ?? 0);
                $repostAuthor = $repostFromId !== 0 ? ($this->ownerNames[$repostFromId] ?? $postAuthor) : $postAuthor;

                $repostText = $repost['text'] ?? '';
                foreach ($this->findArticleLinks($repostText, $groupLower) as $url) {
                    $norm = $this->normalizeArticleUrl($url);
                    if (isset($articles[$norm]) === false) {
                        $articles[$norm] = [
                            'url' => $url,
                            'timestamp' => $repostTimestamp,
                            'author' => $repostAuthor,
                        ];
                    }
                }

                foreach ($repost['attachments'] ?? [] as $attachment) {
                    $type = $attachment['type'] ?? '';

                    if ($type === 'article') {
                        $viewUrl = $attachment['article']['view_url'] ?? '';
                        if ($viewUrl !== '' && $this->isArticleUrl($viewUrl, $groupLower) === true) {
                            $norm = $this->normalizeArticleUrl($viewUrl);
                            if (isset($articles[$norm]) === false) {
                                $articles[$norm] = [
                                    'url' => $viewUrl,
                                    'timestamp' => $repostTimestamp,
                                    'author' => $repostAuthor,
                                ];
                            }
                        }
                    }

                    if ($type === 'link') {
                        $linkUrl = $attachment['link']['url'] ?? '';
                        if ($this->isArticleUrl($linkUrl, $groupLower) === true) {
                            $norm = $this->normalizeArticleUrl($linkUrl);
                            if (isset($articles[$norm]) === false) {
                                $articles[$norm] = [
                                    'url' => $linkUrl,
                                    'timestamp' => $repostTimestamp,
                                    'author' => $repostAuthor,
                                ];
                            }
                        }
                    }
                }
            }
        }

        foreach ($articles as $a) {
            $article = $this->parseArticle($a['url'], $a['timestamp'], $a['author']);
            if ($article !== null) {
                $this->items[] = $article;
            }
        }
    }

    private function findArticleLinks(string $text, string $groupLower): array
    {
        $urls = [];
        $groupPattern = preg_quote($groupLower, '~');

        $patterns = [
            '~https?://(?:www\.|m\.)?vk\.ru/@' . $groupPattern . '-[\p{L}0-9_-]+~iu',
            '~\[(https?://(?:www\.|m\.)?vk\.ru/@' . $groupPattern . '-[\p{L}0-9_-]+)\|[^\]]+\]~iu',
            '~https?://(?:www\.|m\.)?vk\.ru/@' . $groupPattern . '[^\s<\[\]]*~iu',
            '~(?:www\.|m\.)?vk\.ru/@' . $groupPattern . '-[\p{L}0-9_-]+~iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches) === 0) {
                continue;
            }

            foreach ($matches as $matchGroup) {
                if (is_array($matchGroup) === false) {
                    continue;
                }
                foreach ($matchGroup as $match) {
                    if (is_string($match) === false) {
                        continue;
                    }
                    $cleanUrl = $this->normalizeArticleUrl($match);
                    if ($cleanUrl !== '' && $this->isArticleUrl($cleanUrl, $groupLower) === true) {
                        $urls[] = $cleanUrl;
                    }
                }
            }
        }

        return array_unique($urls);
    }

    private function isArticleUrl(string $url, string $groupLower): bool
    {
        if ($url === '') {
            return false;
        }

        $pattern = '~https?://(?:www\.|m\.)?vk\.ru/@' . preg_quote($groupLower, '~') . '~i';
        return preg_match($pattern, $url) === 1;
    }

    private function parseArticle(string $url, int $parentTimestamp, string $parentAuthor): ?array
    {
        $normalized = $this->normalizeArticleUrl($url);
        $cacheKey = 'vk2_article_' . md5($normalized);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $cached['timestamp'] = $parentTimestamp;
            $cached['author'] = $parentAuthor;
            return $cached;
        }

        try {
            $html = getContents($url);
        } catch (\Exception) {
            return null;
        }

        if ($html === '') {
            return null;
        }

        $tidy = new \tidy();
        $tidy->parseString($html, [
            'clean' => true,
            'output-xhtml' => true,
            'wrap' => 0,
            'show-warnings' => false,
            'quiet' => true,
        ], 'utf8');
        $tidy->cleanRepair();

        $dom = \Dom\HTMLDocument::createFromString((string) $tidy);

        $articleBlock = $dom->querySelector('[id^="article_view_"]');
        if ($articleBlock === null) {
            $articleBlock = $dom->querySelector('.article_content');
        }
        if ($articleBlock === null) {
            $articleBlock = $dom->querySelector('.article__content');
        }
        if ($articleBlock === null) {
            $articleBlock = $dom->querySelector('[class*="article"]');
        }
        if ($articleBlock === null) {
            $articleBlock = $dom->querySelector('article');
        }
        if ($articleBlock === null) {
            return null;
        }

        $title = $this->extractArticleTitle($dom);
        $content = $this->cleanArticleContent($articleBlock);

        if (trim(strip_tags($content)) === '') {
            return null;
        }

        $article = [
            'title' => $title,
            'uri' => $normalized,
            'content' => $content,
            'timestamp' => $parentTimestamp,
            'author' => $parentAuthor,
            'uid' => 'vk:article:' . md5($normalized),
            'categories' => ['article'],
        ];

        $this->cache->set($cacheKey, $article, self::CACHE_TIMEOUT);

        return $article;
    }

    private function extractArticleTitle(\Dom\HTMLDocument $dom): string
    {
        $selectors = [
            '.article_title',
            'h1.article__title',
            '.article__title',
        ];

        foreach ($selectors as $selector) {
            $element = $dom->querySelector($selector);
            if ($element === null) {
                continue;
            }
            $title = trim($element->textContent);
            if ($title !== '') {
                return $title;
            }
        }

        $ogTitle = $dom->querySelector('meta[property="og:title"]');
        if ($ogTitle !== null) {
            $content = $ogTitle->getAttribute('content');
            if ($content !== null && trim($content) !== '') {
                return trim($content);
            }
        }

        $titleTag = $dom->querySelector('title');
        if ($titleTag !== null) {
            $text = trim($titleTag->textContent);
            if ($text !== '') {
                return $text;
            }
        }

        return 'Article';
    }

    private function cleanArticleContent(\Dom\Element $articleBlock): string
    {
        foreach ($articleBlock->querySelectorAll('.article__info_line') as $el) {
            $el->remove();
        }
        foreach ($articleBlock->querySelectorAll('h1') as $el) {
            $el->remove();
        }
        foreach ($articleBlock->querySelectorAll('script, style, noscript, iframe') as $el) {
            $el->remove();
        }

        foreach ($articleBlock->querySelectorAll('figure') as $figure) {
            $parent = $figure->parentNode;
            if ($parent === null) {
                continue;
            }

            $img = $figure->querySelector('img');
            if ($img !== null) {
                $sizesJson = null;
                $sizerWrap = $figure->querySelector('.article_object_sizer_wrap');
                if ($sizerWrap !== null) {
                    $sizesAttr = $sizerWrap->getAttribute('data-sizes');
                    if ($sizesAttr !== null && $sizesAttr !== '') {
                        $decoded = html_entity_decode($sizesAttr, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        $parsed = json_decode($decoded, true);
                        if (is_array($parsed) === true) {
                            $sizesJson = $parsed;
                        }
                    }
                }

                $bestUrl = null;
                if (is_array($sizesJson) === true) {
                    $maxWidth = 0;
                    foreach ($sizesJson as $sizeData) {
                        if (is_array($sizeData) === true && isset($sizeData[0], $sizeData[1]) === true) {
                            $url = $sizeData[0];
                            $width = (int) $sizeData[1];
                            if ($width > $maxWidth) {
                                $maxWidth = $width;
                                $bestUrl = $url;
                            }
                        }
                    }
                }

                if ($bestUrl === null) {
                    $src = $img->getAttribute('src');
                    $dataSrc = $img->getAttribute('data-src');
                    $dataFull = $img->getAttribute('data-full');
                    $dataOriginal = $img->getAttribute('data-original');

                    foreach ([$dataFull, $dataOriginal, $dataSrc, $src] as $candidate) {
                        if ($candidate !== null && $candidate !== '' && preg_match('/^(?:data:|javascript:)/i', $candidate) === 0) {
                            $bestUrl = $candidate;
                            break;
                        }
                    }
                }

                if ($bestUrl !== null) {
                    $newImg = $articleBlock->ownerDocument->createElement('img');
                    $newImg->setAttribute('src', $this->fixVkArticleImageUrl($bestUrl));
                    $alt = $img->getAttribute('alt');
                    if ($alt !== null) {
                        $newImg->setAttribute('alt', $alt);
                    }
                    $newImg->setAttribute('style', 'display: block; max-width: 1400px; width: auto; height: auto;');
                    $parent->insertBefore($newImg, $figure);
                }
            }

            $caption = $figure->querySelector('figcaption');
            if ($caption !== null) {
                $captionsJson = $caption->getAttribute('data-captions');
                $captionText = '';
                if ($captionsJson !== null && $captionsJson !== '') {
                    $decoded = html_entity_decode($captionsJson, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $parsed = json_decode($decoded, true);
                    if (is_array($parsed) === true && isset($parsed[0]) === true && $parsed[0] !== '') {
                        $captionText = $parsed[0];
                    }
                }
                if ($captionText === '') {
                    $captionText = trim($caption->textContent);
                }
                if ($captionText !== '') {
                    $wrapper = $articleBlock->ownerDocument->createElement('p');
                    $wrapper->textContent = $captionText;
                    $wrapper->setAttribute('style', 'font-style: italic; font-size: 0.9em; color: #666; text-align: center;');
                    $parent->insertBefore($wrapper, $figure);
                }
            }

            $figure->remove();
        }

        foreach ($articleBlock->querySelectorAll('img') as $img) {
            $src = $img->getAttribute('src');
            $dataSrc = $img->getAttribute('data-src');
            $dataFull = $img->getAttribute('data-full');
            $dataOriginal = $img->getAttribute('data-original');

            $bestSrc = null;
            foreach ([$dataFull, $dataOriginal, $dataSrc, $src] as $candidate) {
                if ($candidate !== null && $candidate !== '' && preg_match('/^(?:data:|javascript:)/i', $candidate) === 0) {
                    $bestSrc = $candidate;
                    break;
                }
            }

            if ($bestSrc !== null) {
                $img->setAttribute('src', $this->fixVkArticleImageUrl($bestSrc));
            }

            $srcset = $img->getAttribute('srcset');
            if ($srcset !== null && $srcset !== '') {
                $img->setAttribute('srcset', $this->fixVkSrcset($srcset));
            }

            $img->removeAttribute('onerror');
            $img->removeAttribute('onload');
            $img->removeAttribute('loading');
            $img->removeAttribute('width');
            $img->removeAttribute('height');
            $img->setAttribute('style', 'display: block; max-width: 1400px; width: auto; height: auto;');
        }

        foreach ($articleBlock->querySelectorAll('ol, ul') as $list) {
            $list->removeAttribute('class');

            $isOrdered = $list->tagName === 'ol';
            $startAttr = $list->getAttribute('start');

            $style = 'display: block !important; margin: 1em 0 !important; padding-left: 2em !important; ';
            if ($isOrdered === true) {
                $style .= 'list-style: decimal outside !important; ';
            } else {
                $style .= 'list-style: disc outside !important; ';
            }

            if ($isOrdered === true && $startAttr !== null && $startAttr !== '') {
                $start = (int) $startAttr;
                if ($start > 1) {
                    $style .= 'counter-reset: item ' . ($start - 1) . ' !important; ';
                }
            }

            $list->setAttribute('style', $style);
        }

        foreach ($articleBlock->querySelectorAll('ol > li, ul > li') as $li) {
            $li->removeAttribute('class');

            $parentList = $li->parentNode;
            $isOrdered = $parentList !== null && $parentList->tagName === 'ol';

            $style = 'display: list-item !important; margin: 0.5em 0 !important; ';
            if ($isOrdered === true) {
                $style .= 'list-style-type: decimal !important; ';
            } else {
                $style .= 'list-style-type: disc !important; ';
            }

            $li->setAttribute('style', $style);
        }

        $content = $articleBlock->innerHTML ?? '';

        if (function_exists('break_annoying_html_tags') === true) {
            $content = break_annoying_html_tags($content);
        }

        return trim($content);
    }

    private function fixVkArticleImageUrl(string $url): string
    {
        $url = html_entity_decode($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        if (preg_match('/^(javascript|data|vbscript):/i', $url) === 1) {
            return '';
        }

        if (preg_match('~^https?://~i', $url) === 0) {
            if (str_starts_with($url, '//') === true) {
                $url = 'https:' . $url;
            } else {
                return '';
            }
        }

        $parsed = parse_url($url);
        if ($parsed === false || isset($parsed['query']) === false) {
            return $this->proxyImage($url);
        }

        parse_str($parsed['query'], $queryParams);
        $as = $queryParams['as'] ?? '';

        if ($as !== '') {
            $sizes = explode(',', $as);
            $maxWidth = 0;
            foreach ($sizes as $size) {
                $parts = explode('x', $size);
                if (isset($parts[0]) === true) {
                    $w = (int) $parts[0];
                    if ($w > $maxWidth) {
                        $maxWidth = $w;
                    }
                }
            }

            if ($maxWidth > 0) {
                $replaced = preg_replace(
                    '/([?&])cs=[^&]+/',
                    '$1cs=' . $maxWidth . 'x0',
                    $url
                );
                if ($replaced !== null) {
                    $url = $replaced;
                }
            }
        }

        return $this->proxyImage($url);
    }

    private function fixVkSrcset(string $srcset): string
    {
        $parts = explode(',', $srcset);
        $result = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $spacePos = strpos($part, ' ');
            if ($spacePos === false) {
                $result[] = $part;
                continue;
            }

            $url = substr($part, 0, $spacePos);
            $descriptor = substr($part, $spacePos);
            $result[] = $this->fixVkArticleImageUrl($url) . $descriptor;
        }

        return implode(', ', $result);
    }

    private function normalizeArticleUrl(string $url): string
    {
        $url = html_entity_decode($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $parsed = parse_url($url);
        if ($parsed === false || isset($parsed['scheme'], $parsed['host'], $parsed['path']) === false) {
            return $url;
        }

        $host = str_replace('m.vk.ru', 'vk.ru', $parsed['host']);

        return $parsed['scheme'] . '://' . $host . $parsed['path'];
    }

    private function renderDocAttachment(array $d): string
    {
        if (($d['ext'] ?? '') === 'gif') {
            $imgUrl = $this->proxyImage($d['url'] ?? '');
            $alt = $d['title'] ?? 'Document';
            return "<p>{$this->image($imgUrl, $alt)}</p>";
        }

        $url = $d['url'] ?? '#';
        $title = 'Document: ' . ($d['title'] ?? 'Document');
        return "<p>{$this->link($url, $title)}</p>";
    }

    private function renderVideo(array $d): string
    {
        $title = $d['title'] ?? 'Video';
        if (mb_stripos($title, 'Êëèï ') === 0) {
            return $this->renderClip($d);
        }

        $isLive = ($d['live'] ?? 0) === 1;
        $duration = $d['duration'] ?? 0;
        $dur = $duration > 0 ? ' (' . gmdate('i:s', $duration) . ')' : '';
        $w = $d['width'] ?? 0;
        $h = $d['height'] ?? 0;
        $res = ($w > 0 && $h > 0) === true ? " ({$w}x{$h})" : '';

        if ($isLive === true) {
            $labels = ['waiting' => 'Scheduled', 'started' => 'LIVE', 'finished' => 'Ended', 'failed' => 'Failed', 'upcoming' => 'Upcoming'];
            $status = $labels[$d['live_status'] ?? ''] ?? ($d['live_status'] ?? 'unknown');
            $title = "[{$status}] {$title}";
            if (($d['spectators'] ?? 0) > 0) {
                $title .= " ({$d['spectators']} viewers)";
            }
        } else {
            $title = "Video: {$title}{$dur}{$res}";
        }

        $url = 'https://vk.ru/video' . ($d['owner_id'] ?? 0) . '_' . ($d['id'] ?? 0);
        return $this->renderLinkCard($url, $title, $this->getLargestImageUrl($d['image'] ?? []));
    }

    private function renderClip(array $d): string
    {
        $title = $this->cleanClipTitle($d['title'] ?? 'Clip');
        $duration = $d['duration'] ?? 0;
        $dur = $duration > 0 ? ' (' . gmdate('i:s', $duration) . ')' : '';
        $w = $d['width'] ?? 0;
        $h = $d['height'] ?? 0;
        $res = ($w > 0 && $h > 0) === true ? " ({$w}x{$h})" : '';
        $title = "Clip: {$title}{$dur}{$res}";
        $url = 'https://vk.ru/clip' . ($d['owner_id'] ?? 0) . '_' . ($d['id'] ?? 0);
        return $this->renderLinkCard($url, $title, $this->getLargestImageUrl($d['image'] ?? []));
    }

    private function renderAudio(array $d): string
    {
        $url = 'https://vk.ru/audio' . ($d['owner_id'] ?? 0) . '_' . ($d['id'] ?? 0);
        $dur = ($d['duration'] ?? 0) > 0 ? ' (' . gmdate('i:s', $d['duration']) . ')' : '';
        return $this->renderLinkCard($url, 'Music: ' . ($d['artist'] ?? '') . ' - ' . ($d['title'] ?? '') . $dur);
    }

    private function renderLinkCard(string $url, string $title, string $img = '', string $extra = ''): string
    {
        $eTitle = $this->e($title);
        $inner = $img !== '' ? $this->image($img, $title) . "<br>{$eTitle}" : $eTitle;
        $html = "<p>{$this->linkHtml($url, $inner)}</p>";
        return $extra !== '' ? $html . $extra : $html;
    }

    private function renderPoll(array $d): string
    {
        $question = $this->e($d['question'] ?? 'Poll');
        $answers = $d['answers'] ?? [];
        $totalVotes = (int) ($d['votes'] ?? 0);

        $lines = [];
        $lines[] = "Poll: {$question}";
        $lines[] = '';

        foreach ($answers as $answer) {
            $text = $this->e($answer['text'] ?? '');
            $rate = (float) ($answer['rate'] ?? 0);
            $votes = (int) ($answer['votes'] ?? 0);

            $filled = (int) round($rate / 5);
            $bar = str_repeat('#', $filled) . str_repeat('.', 20 - $filled);

            $rateFormatted = rtrim(rtrim(number_format($rate, 1, '.', ''), '0'), '.');

            $lines[] = "{$rateFormatted}% {$text}";
            $lines[] = "[{$bar}] {$votes} votes";
            $lines[] = '';
        }

        $footer = "Total votes: {$totalVotes}";

        if (($d['anonymous'] ?? false) !== false) {
            $footer .= ' · Anonymous';
        }
        if (($d['multiple'] ?? false) !== false) {
            $footer .= ' · Multiple choice';
        }
        if (($d['closed'] ?? false) !== false) {
            $footer .= ' · Closed';
        } elseif (($d['end_date'] ?? 0) > 0) {
            $footer .= ' · Ends ' . date('Y-m-d', $d['end_date']);
        }

        $lines[] = $footer;

        return '<pre>' . implode("\n", $lines) . '</pre>';
    }

    private function renderWall(array $d): string
    {
        $text = $d['text'] ?? '';
        $preview = mb_substr($text, 0, self::MAX_PREVIEW_LENGTH);
        if (mb_strlen($text) > self::MAX_PREVIEW_LENGTH) {
            $preview .= '...';
        }
        return '<p><strong>Attached post:</strong><br>' . $this->linkHtml($this->getPostURI($d), $this->e($preview)) . '</p>';
    }

    private function renderSticker(array $d): string
    {
        $images = $d['images'] ?? [];
        if ($images === []) {
            return '';
        }
        usort($images, fn($a, $b) => ($b['width'] ?? 0) <=> ($a['width'] ?? 0));
        $url = $images[0]['url'] ?? '';
        return $url !== '' ? "<p>{$this->image($this->proxyImage($url), 'Sticker')}</p>" : '';
    }

    private function renderEvent(array $d): string
    {
        $time = $d['time'] ?? 0;
        $date = $d['date'] ?? 0;
        $timeStr = $time > 0 ? date('Y-m-d H:i', $time) : ($date > 0 ? date('Y-m-d', $date) : '');
        $html = '<p>Event: ' . $this->e($d['text'] ?? 'Event');
        if ($timeStr !== '') {
            $html .= "<br><small>{$this->e($timeStr)}</small>";
        }
        if (($d['address'] ?? '') !== '') {
            $html .= '<br><small>Location: ' . $this->e($d['address']) . '</small>';
        }
        return $html . '</p>';
    }

    private function renderPodcast(array $d): string
    {
        $title = $d['podcast_title'] ?? $d['title'] ?? 'Podcast';
        $artist = $d['artist'] ?? '';
        $url = $d['url'] ?? '#';
        $display = $artist !== '' ? "Podcast: {$title} - {$artist}" : "Podcast: {$title}";
        $cover = '';

        foreach ($d['podcast_cover'] ?? [] as $img) {
            if (isset($img['url']) === true) {
                $cover = $img['url'];
                break;
            }
        }

        $html = '';
        if ($cover !== '') {
            $html .= "<p>{$this->linkHtml($url, $this->image($this->proxyImage($cover), $title))}</p>";
        }
        $html .= "<p>{$this->link($url, $display)}</p>";

        if (($d['podcast_description'] ?? '') !== '') {
            $html .= '<p><small>' . $this->e(mb_substr($d['podcast_description'], 0, self::MAX_PREVIEW_LENGTH)) . '</small></p>';
        }

        return $html;
    }

    private function getAttachmentTitle(array $attachment): string
    {
        $type = $attachment['type'] ?? '';
        $d = $attachment[$type] ?? [];

        return match ($type) {
            'video' => (mb_stripos($d['title'] ?? '', 'Êëèï ') === 0) ? 'Clip: ' . $this->cleanClipTitle($d['title']) : 'Video: ' . ($d['title'] ?? ''),
            'clip' => 'Clip: ' . $this->cleanClipTitle($d['title'] ?? ''),
            'audio' => 'Music: ' . ($d['artist'] ?? '') . ' - ' . ($d['title'] ?? ''),
            'link' => (strpos($d['url'] ?? '', 'audio_playlist') !== false) ? 'Playlist: ' . ($d['title'] ?? '') : 'Link: ' . ($d['title'] ?? ''),
            'doc' => 'Document: ' . ($d['title'] ?? ''),
            'album' => 'Album: ' . ($d['title'] ?? ''),
            'poll' => 'Poll: ' . ($d['question'] ?? ''),
            'photo' => 'Photo',
            'article' => 'Article: ' . ($d['title'] ?? ''),
            'wall' => 'Attached post',
            'market' => 'Product: ' . ($d['title'] ?? ''),
            'audio_playlist' => 'Playlist: ' . ($d['title'] ?? ''),
            'video_playlist' => 'Video playlist: ' . ($d['title'] ?? ''),
            'podcast' => 'Podcast: ' . ($d['podcast_title'] ?? $d['title'] ?? ''),
            'event' => 'Event: ' . ($d['text'] ?? ''),
            'graffiti' => 'Graffiti',
            'group' => 'Group: ' . ($d['name'] ?? ''),
            'donut_link' => 'VK Donut',
            'textlive', 'textpost', 'textpost_publish' => 'Text: ' . ($d['title'] ?? ''),
            'situational_theme' => $d['title'] ?? '',
            'sticker' => 'Sticker',
            default => '',
        };
    }

    private function splitFirstLine(string $text): array
    {
        $lines = explode("\n", $text);
        $meaningfulIndex = null;

        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            if ($trimmed !== '' && $this->isLinkOnly($trimmed) === false) {
                $meaningfulIndex = $i;
                break;
            }
        }

        if ($meaningfulIndex === null) {
            return ['', $text];
        }

        $cleanLine = preg_replace('/\[([^\]|]+)\|([^\]]+)\]/u', '$2', trim($lines[$meaningfulIndex]));

        if (trim($cleanLine) === '') {
            return ['', $text];
        }

        [$titlePart, $urlRemainder] = $this->cutAtFirstUrl($cleanLine);

        if ($titlePart === '') {
            return ['', $text];
        }

        if (mb_strlen($titlePart) <= self::MAX_TITLE_LENGTH) {
            if ($urlRemainder !== '') {
                $lines[$meaningfulIndex] = $urlRemainder;
            } else {
                unset($lines[$meaningfulIndex]);
                $lines = array_values($lines);
            }
            return [$titlePart, implode("\n", $lines)];
        }

        $cut = mb_substr($titlePart, 0, self::MAX_TITLE_LENGTH);
        $lastSpace = mb_strrpos($cut, ' ');
        $cutPos = ($lastSpace !== false && $lastSpace > self::MIN_TITLE_SPACE_POS) ? $lastSpace : self::MAX_TITLE_LENGTH;
        $title = mb_substr($titlePart, 0, $cutPos) . '...';
        $remainder = '...' . trim(mb_substr($titlePart, $cutPos));

        if ($urlRemainder !== '') {
            $remainder .= ' ' . $urlRemainder;
        }

        $lines[$meaningfulIndex] = $remainder;
        return [$title, implode("\n", $lines)];
    }

    private function cutAtFirstUrl(string $text): array
    {
        if (preg_match('~\s+https?://~iu', $text, $m, PREG_OFFSET_CAPTURE) === 1) {
            $pos = $m[0][1];
            return [rtrim(trim(substr($text, 0, $pos)), ':;,'), trim(substr($text, $pos))];
        }

        if (preg_match('~\s+(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}/~iu', $text, $m, PREG_OFFSET_CAPTURE) === 1) {
            $pos = $m[0][1];
            return [rtrim(trim(substr($text, 0, $pos)), ':;,'), trim(substr($text, $pos))];
        }

        return [trim($text), ''];
    }

    private function normalizePlaylistUrl(string $url): string
    {
        if (preg_match('/act=audio_playlist(-?\d+)_(\d+)/', $url, $m) === 1) {
            return "https://vk.ru/music/playlist/{$m[1]}_{$m[2]}";
        }
        return $url;
    }

    private function cleanClipTitle(string $title): string
    {
        if (mb_stripos($title, 'Êëèï ') === 0) {
            return trim(mb_substr($title, mb_strlen('Êëèï ')));
        }
        return $title;
    }

    private function isLinkOnly(string $line): bool
    {
        $line = trim($line);
        if ($line === '') {
            return false;
        }
        return preg_match('~^(https?://[^\s]+|[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}(?:/[^\s]*)?)$~i', $line) === 1;
    }

    private function applyPlaceholders(string $text, array &$placeholders, int &$counter, string $pattern, callable $resolver): string
    {
        $result = preg_replace_callback($pattern, function (array $m) use (&$placeholders, &$counter, $resolver): string {
            $html = $resolver($m);
            if ($html === null) {
                return $m[0];
            }
            $marker = "___VK_PH_{$counter}___";
            $placeholders[$marker] = $html;
            $counter++;
            return $marker;
        }, $text);

        return $result ?? $text;
    }

    private function resolveVkLink(string $target, string $linkText): string
    {
        if (preg_match('/^https?:\/\//i', $target) === 1) {
            return $this->safeLink($target, $linkText);
        }
        if (preg_match('/^(id|club|public|wall|post|event|market)([\-0-9_]+)$/i', $target, $tm) === 1) {
            return $this->safeLink('https://vk.ru/' . strtolower($tm[1]) . $tm[2], $linkText);
        }
        return $this->safeLink('https://vk.ru/' . $target, $linkText);
    }

    private function getLargestImageUrl(array $sizes): string
    {
        if ($sizes === []) {
            return '';
        }
        $largest = array_reduce(
            $sizes,
            static fn(array $carry, array $item): array => ((($item['width'] ?? 0) > ($carry['width'] ?? 0)) === true) ? $item : $carry,
            []
        );
        return $this->proxyImage($largest['url'] ?? '');
    }

    private function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function safeLink(string $url, string $text = ''): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('/^(javascript|data|vbscript):/i', $url) === 1) {
            return $this->e($text !== '' ? $text : $url);
        }
        return "<a href='{$this->e($url)}' target='_blank' rel='noopener noreferrer'>{$this->e($text !== '' ? $text : $url)}</a>";
    }

    private function link(string $url, string $text): string
    {
        return $this->linkHtml($url, $this->e($text));
    }

    private function linkHtml(string $url, string $html): string
    {
        return "<a href='{$this->e($url)}' target='_blank' rel='noopener noreferrer'>{$html}</a>";
    }

    private function image(string $url, string $alt): string
    {
        return "<img src='{$this->e($url)}' alt='{$this->e($alt)}' style='display: block; max-width: 1400px; width: auto; height: auto;'>";
    }

    private function proxyImage(string $url): string
    {
        if ($url === '') {
            return '';
        }
        if (function_exists('getProxiedUri') === true) {
            return getProxiedUri($url);
        }
        return $url;
    }

    private function getRateLimitCacheKey(): string
    {
        return 'vk2_rate_limit_' . md5($this->getOption('access_token') ?? '');
    }

    private function handleError(string $code, string $details = ''): never
    {
        $message = self::ERROR_MESSAGES[$code] ?? 'Unknown error.';
        if ($details !== '') {
            $message .= ' ' . $details;
        }
        throwServerException($message);
    }
}
