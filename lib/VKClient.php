<?php

declare(strict_types=1);

namespace RSSBridge;

use RSSBridge\Caches\CacheInterface;
use RSSBridge\Logger\Logger;
use RSSBridge\Utils\Json;

/**
 * VK API client with built-in retry, rate limit and error handling.
 *
 * The access token is shared by every VK bridge and is read from the [VK]
 * section of .bridges (access_token), mirroring GithubClient's global token.
 * The token is sent only as an Authorization header and is redacted from all
 * log records and exception messages.
 *
 * Unlike GitHub, VK does not expose rate-limit headers; limits are signalled
 * through error codes 6/29. The client therefore keeps local call counters
 * (per minute / per day) and paces consecutive calls to stay under the
 * documented 3 requests/second cap for user tokens.
 *
 * @see https://dev.vk.com/ru/reference/
 */
final class VKClient
{
    public const API_BASE = 'https://api.vk.ru/method/';
    public const URI = 'https://vk.ru';
    public const API_VER = 5.199;
    public const MAX_COUNT = 100;
    public const MAX_VIDEO_COUNT = 200;

    public const API_ERR_UNKNOWN = 1;
    public const API_ERR_UNKNOWN_METHOD = 3;
    public const API_ERR_AUTH_FAILED = 5;
    public const API_ERR_TOO_MANY_REQUESTS = 6;
    public const API_ERR_FLOOD_CONTROL = 9;
    public const API_ERR_INTERNAL = 10;
    public const API_ERR_CAPTCHA = 14;
    public const API_ERR_ACCESS_DENIED = 15;
    public const API_ERR_VALIDATION = 17;
    public const API_ERR_USER_DELETED = 18;
    public const API_ERR_RATE_LIMIT = 29;

    private const MAX_RETRIES = 3;
    private const RETRY_DELAY_US = 500_000;
    private const MIN_CALL_INTERVAL_US = 350_000;
    private const CAPTCHA_COOLDOWN = 300;
    private const VALIDATION_COOLDOWN = 1800;
    private const OWNER_CACHE_TTL = 86400;
    private const CALL_COUNTER_MIN_TTL = 120;
    private const CALL_COUNTER_DAY_TTL = 172800;

    private const TRANSIENT_ERRORS = [
        self::API_ERR_UNKNOWN,
        self::API_ERR_TOO_MANY_REQUESTS,
        self::API_ERR_FLOOD_CONTROL,
        self::API_ERR_INTERNAL,
    ];

    private const RATE_LIMIT_DELAYS = [
        self::API_ERR_TOO_MANY_REQUESTS => 15,
        self::API_ERR_FLOOD_CONTROL => 60,
        self::API_ERR_RATE_LIMIT => 1800,
        601 => 1800,
    ];

    private const AUTH_ERRORS = [5, 16, 27, 28, 1117];
    private const ACCESS_DENIED_ERRORS = [7, 15, 20, 21, 30, 200, 201, 203, 204, 205, 210, 211, 212, 213, 214, 260];
    private const USER_GONE_ERRORS = [18, 37, 3610];
    private const NOT_FOUND_ERRORS = [39, 40];
    private const METHOD_ERRORS = [3, 23];
    private const CAPTCHA_ERRORS = [14, 3300];
    private const VALIDATION_ERRORS = [17, 24, 3301, 3302, 3303, 3304];

    private const ERR_OWNER_NOT_FOUND = 'owner_not_found';
    private const ERR_INVALID_API_RESPONSE = 'invalid_api_response';
    private const ERR_MISSING_ACCESS_TOKEN = 'missing_access_token';
    private const ERR_INVALID_JSON = 'invalid_json';
    private const ERR_API_ERROR = 'api_error';
    private const ERR_AUTH_FAILED = 'auth_failed';
    private const ERR_ACCESS_DENIED = 'access_denied';
    private const ERR_USER_DELETED = 'user_deleted';
    private const ERR_UNKNOWN_METHOD = 'unknown_method';

    private const ERROR_MESSAGES = [
        self::ERR_OWNER_NOT_FOUND => 'Could not detect owner id. Check the short name.',
        self::ERR_INVALID_API_RESPONSE => 'Invalid API response from',
        self::ERR_MISSING_ACCESS_TOKEN => 'Access token is required. Set access_token in the [VK] section of .bridges.',
        self::ERR_INVALID_JSON => 'Invalid JSON response from VK API.',
        self::ERR_API_ERROR => 'API returned error:',
        self::ERR_AUTH_FAILED => 'VK authorization failed. Check the access_token in the [VK] section of .bridges.',
        self::ERR_ACCESS_DENIED => 'Access denied. The wall or group might be private.',
        self::ERR_USER_DELETED => 'User was deleted or banned.',
        self::ERR_UNKNOWN_METHOD => 'Unknown or disabled API method.',
    ];

    private static ?float $lastCallAt = null;

    private CacheInterface $cache;
    private Logger $logger;
    private ?string $token;
    private string $tokenFingerprint;
    private string $rateLimitCacheKey;

    public function __construct(CacheInterface $cache, Logger $logger)
    {
        $this->cache = $cache;
        $this->logger = $logger;

        $globalToken = Configuration::getConfig('VK', 'access_token');
        $this->token = is_string($globalToken) === true && $globalToken !== '' ? $globalToken : null;
        $this->tokenFingerprint = md5($this->token ?? '');
        $this->rateLimitCacheKey = 'vk_rate_limit_' . $this->tokenFingerprint;
    }

    public function hasToken(): bool
    {
        return $this->token !== null && $this->token !== '';
    }

    /**
     * @return array{ownerId: int, iconUrl: string|null, name: string}
     */
    public function resolveOwner(string $name): array
    {
        if (preg_match('/^(club|public)(\d+)$/', $name, $m) === 1) {
            return ['ownerId' => -(int) $m[2], 'iconUrl' => null, 'name' => ''];
        }

        if (preg_match('/^id(\d+)$/', $name, $m) === 1) {
            return ['ownerId' => (int) $m[1], 'iconUrl' => null, 'name' => ''];
        }

        $cacheKey = 'vk_owner_' . md5(strtolower($name));
        $cached = $this->cache->get($cacheKey);

        if (is_array($cached) === true && isset($cached['ownerId']) === true && is_int($cached['ownerId']) === true) {
            $icon = $cached['iconUrl'] ?? null;
            $cachedName = $cached['name'] ?? '';

            return [
                'ownerId' => $cached['ownerId'],
                'iconUrl' => is_string($icon) === true && $icon !== '' ? $icon : null,
                'name' => is_string($cachedName) === true ? $cachedName : '',
            ];
        }

        $r = $this->call('groups.getById', ['group_ids' => $name, 'fields' => 'photo_200,photo_100,photo_50'], [self::API_ERR_TOO_MANY_REQUESTS]);
        $response = $r['response'] ?? null;
        $group = null;

        if (is_array($response) === true) {
            if (isset($response['groups'][0]) === true && is_array($response['groups'][0]) === true) {
                $group = $response['groups'][0];
            } elseif (isset($response[0]) === true && is_array($response[0]) === true) {
                $group = $response[0];
            }
        }

        if ($group !== null && isset($group['id']) === true && is_int($group['id']) === true) {
            $ownerId = -$group['id'];
            $icon = self::pickPhoto($group, ['photo_200', 'photo_100', 'photo_50']);
            $displayName = is_string($group['name'] ?? null) === true ? $group['name'] : '';
            $this->cache->set($cacheKey, ['ownerId' => $ownerId, 'iconUrl' => $icon, 'name' => $displayName], self::OWNER_CACHE_TTL);

            return ['ownerId' => $ownerId, 'iconUrl' => $icon, 'name' => $displayName];
        }

        $r = $this->call('users.get', ['user_ids' => $name, 'fields' => 'photo_100,photo_50']);
        $response = $r['response'] ?? null;
        $user = is_array($response) === true && isset($response[0]) === true && is_array($response[0]) === true ? $response[0] : null;

        if ($user !== null && isset($user['deactivated']) === true) {
            $this->handleError(self::ERR_USER_DELETED, sprintf('Screen name "%s"', $name));
        }

        if ($user !== null && isset($user['id']) === true && is_int($user['id']) === true) {
            $ownerId = $user['id'];
            $icon = self::pickPhoto($user, ['photo_100', 'photo_50']);
            $displayName = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
            $this->cache->set($cacheKey, ['ownerId' => $ownerId, 'iconUrl' => $icon, 'name' => $displayName], self::OWNER_CACHE_TTL);

            return ['ownerId' => $ownerId, 'iconUrl' => $icon, 'name' => $displayName];
        }

        $this->handleError(self::ERR_OWNER_NOT_FOUND, sprintf('Screen name "%s"', $name));
    }

    /**
     * @return array{items: list<array<string, mixed>>, profiles: list<array<string, mixed>>, groups: list<array<string, mixed>>}
     */
    public function fetchWall(int $ownerId, int $count, int $offset): array
    {
        $r = $this->call('wall.get', [
            'owner_id' => $ownerId,
            'extended' => '1',
            'count' => $count,
            'offset' => $offset,
            'fields' => 'photo_200,photo_100,photo_50',
        ]);

        $response = $r['response'] ?? null;
        if (is_array($response) === false || isset($response['items']) === false || is_array($response['items']) === false) {
            $this->handleError(self::ERR_INVALID_API_RESPONSE, 'wall.get');
        }

        $profiles = $response['profiles'] ?? [];
        $groups = $response['groups'] ?? [];

        return [
            'items' => self::filterObjectList($response['items']),
            'profiles' => is_array($profiles) === true ? self::filterObjectList($profiles) : [],
            'groups' => is_array($groups) === true ? self::filterObjectList($groups) : [],
        ];
    }

    /**
     * @return array{count: int, items: list<array<string, mixed>>}
     */
    public function fetchVideos(int $ownerId, int $albumId = 0, int $count = 100, int $offset = 0): array
    {
        $params = [
            'owner_id' => $ownerId,
            'count' => max(1, min(self::MAX_VIDEO_COUNT, $count)),
            'offset' => max(0, $offset),
            'sort_album' => 0,
        ];
        if ($albumId !== 0) {
            $params['album_id'] = $albumId;
        }

        $r = $this->call('video.get', $params);

        $response = $r['response'] ?? null;
        if (is_array($response) === false || isset($response['items']) === false || is_array($response['items']) === false) {
            $this->handleError(self::ERR_INVALID_API_RESPONSE, 'video.get');
        }

        $total = $response['count'] ?? 0;

        return [
            'count' => is_int($total) === true ? $total : 0,
            'items' => self::filterObjectList($response['items']),
        ];
    }

    /**
     * @param list<string> $photoKeys
     * @return array<string, string>
     */
    public function fetchPhotoTexts(array $photoKeys): array
    {
        if ($photoKeys === []) {
            return [];
        }

        $r = $this->call(
            'photos.getById',
            ['photos' => implode(',', array_unique($photoKeys))],
            [self::API_ERR_TOO_MANY_REQUESTS, self::API_ERR_RATE_LIMIT]
        );

        $response = $r['response'] ?? null;
        if (is_array($response) === false) {
            return [];
        }

        $texts = [];
        foreach ($response as $photo) {
            if (is_array($photo) === false) {
                continue;
            }

            $oid = $photo['owner_id'] ?? null;
            $pid = $photo['id'] ?? null;
            $text = $photo['text'] ?? null;

            if (is_int($oid) === true && is_int($pid) === true && is_string($text) === true) {
                $texts[$oid . '_' . $pid] = $text;
            }
        }

        return $texts;
    }

    /**
     * @param array<string, mixed> $params
     * @param list<int> $expectedErrorCodes
     * @return array<string, mixed>
     */
    public function call(string $method, array $params, array $expectedErrorCodes = []): array
    {
        if ($this->token === null || $this->token === '') {
            $this->handleError(self::ERR_MISSING_ACCESS_TOKEN);
        }

        unset($params['access_token']);

        if ($this->cache->get($this->rateLimitCacheKey) === true) {
            $this->logger->info('vk: Internal rate limit cooldown is active');
            throwRateLimitException('VK API rate limit cooldown is active');
        }

        $this->logger->info(sprintf('vk: vk_client->call(%s)', $method));

        $params['v'] = self::API_VER;
        $url = self::API_BASE . $method . '?' . http_build_query($params);
        $retryDelayUs = self::RETRY_DELAY_US;

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            $this->pace();
            $stats = $this->bumpCallCounters();

            try {
                $response = (string) getContents($url, ['Authorization: Bearer ' . $this->token]);
            } catch (\Exception $e) {
                if ($attempt < self::MAX_RETRIES) {
                    usleep($retryDelayUs);
                    $retryDelayUs *= 2;
                    continue;
                }
                $this->handleError(self::ERR_API_ERROR, 'Network error: ' . $this->redact($e->getMessage()));
            }

            try {
                $r = Json::decode($response);
            } catch (\JsonException $e) {
                $this->handleError(self::ERR_INVALID_JSON, $this->redact($e->getMessage()));
            }

            if (is_array($r) === false) {
                $this->handleError(self::ERR_INVALID_JSON);
            }

            if (isset($r['error']) === false) {
                if (isset($r['response']) === false) {
                    $this->handleError(self::ERR_INVALID_API_RESPONSE, $method);
                }
                $this->logger->info(sprintf(
                    'vk: calls_1m=%d, calls_24h=%d, cooldown=off (%s)',
                    $stats['minute'],
                    $stats['day'],
                    $this->token !== null ? 'authenticated' : 'anonymous'
                ));
                return $r;
            }

            $error = is_array($r['error']) === true ? $r['error'] : [];
            $errorCode = (int) ($error['error_code'] ?? 0);
            $errorMsg = is_string($error['error_msg'] ?? null) === true ? $error['error_msg'] : 'Unknown error';
            $errorMsg = $this->redact($errorMsg);

            $this->logger->warning(sprintf('vk: %s returned error %d: %s', $method, $errorCode, $errorMsg));

            if (in_array($errorCode, self::TRANSIENT_ERRORS, true) === true && $attempt < self::MAX_RETRIES) {
                usleep($retryDelayUs);
                $retryDelayUs *= 2;
                continue;
            }

            if (in_array($errorCode, self::CAPTCHA_ERRORS, true) === true) {
                $this->cache->set($this->rateLimitCacheKey, true, self::CAPTCHA_COOLDOWN);
                throwRateLimitException('VK captcha required: ' . $errorMsg, self::CAPTCHA_COOLDOWN);
            }

            if (in_array($errorCode, self::VALIDATION_ERRORS, true) === true) {
                $this->cache->set($this->rateLimitCacheKey, true, self::VALIDATION_COOLDOWN);
                throwRateLimitException('VK validation required: ' . $errorMsg, self::VALIDATION_COOLDOWN);
            }

            if (in_array($errorCode, self::AUTH_ERRORS, true) === true) {
                $this->handleError(self::ERR_AUTH_FAILED, $errorMsg);
            }

            if (in_array($errorCode, self::ACCESS_DENIED_ERRORS, true) === true) {
                $this->handleError(self::ERR_ACCESS_DENIED, $errorMsg);
            }

            if (in_array($errorCode, self::USER_GONE_ERRORS, true) === true) {
                $this->handleError(self::ERR_USER_DELETED, $errorMsg);
            }

            if (in_array($errorCode, self::NOT_FOUND_ERRORS, true) === true) {
                $this->handleError(self::ERR_OWNER_NOT_FOUND, $errorMsg);
            }

            if (in_array($errorCode, self::METHOD_ERRORS, true) === true) {
                $this->handleError(self::ERR_UNKNOWN_METHOD, $errorMsg);
            }

            if (in_array($errorCode, $expectedErrorCodes, true) === true) {
                return $r;
            }

            if (isset(self::RATE_LIMIT_DELAYS[$errorCode]) === true) {
                $this->cache->set($this->rateLimitCacheKey, true, self::RATE_LIMIT_DELAYS[$errorCode]);
                throwRateLimitException('VK API rate limit: ' . $errorMsg, self::RATE_LIMIT_DELAYS[$errorCode]);
            }

            $this->handleError(self::ERR_API_ERROR, $errorMsg . ' (' . $errorCode . ')');
        }

        throw new \RuntimeException('VK API: max retries exceeded for ' . $method);
    }

    /**
     * @param array<mixed, mixed> $items
     * @return list<array<string, mixed>>
     */
    private static function filterObjectList(array $items): array
    {
        $result = [];

        foreach ($items as $item) {
            if (is_array($item) === false) {
                continue;
            }

            $normalized = [];
            foreach ($item as $key => $value) {
                if (is_string($key) === true) {
                    $normalized[$key] = $value;
                }
            }

            $result[] = $normalized;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $keys
     */
    private static function pickPhoto(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) === true && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function pace(): void
    {
        if (self::$lastCallAt !== null) {
            $elapsedUs = (int) ((microtime(true) - self::$lastCallAt) * 1_000_000);
            if ($elapsedUs < self::MIN_CALL_INTERVAL_US) {
                usleep(self::MIN_CALL_INTERVAL_US - $elapsedUs);
            }
        }
        self::$lastCallAt = microtime(true);
    }

    /**
     * @return array{minute: int, day: int}
     */
    private function bumpCallCounters(): array
    {
        $minute = (int) floor(time() / 60);
        $minKey = 'vk_calls_min_' . $this->tokenFingerprint . '_' . $minute;
        $dayKey = 'vk_calls_day_' . $this->tokenFingerprint . '_' . gmdate('Ymd');

        $minCount = $this->cache->get($minKey);
        $minCount = is_int($minCount) === true ? $minCount + 1 : 1;
        $dayCount = $this->cache->get($dayKey);
        $dayCount = is_int($dayCount) === true ? $dayCount + 1 : 1;

        $this->cache->set($minKey, $minCount, self::CALL_COUNTER_MIN_TTL);
        $this->cache->set($dayKey, $dayCount, self::CALL_COUNTER_DAY_TTL);

        return ['minute' => $minCount, 'day' => $dayCount];
    }

    private function redact(string $text): string
    {
        if ($this->token !== null && $this->token !== '') {
            return str_replace($this->token, '[redacted]', $text);
        }

        return $text;
    }

    private function handleError(string $code, string $details = ''): never
    {
        $message = self::ERROR_MESSAGES[$code] ?? 'Unknown error.';
        if ($details !== '') {
            $message .= ' ' . $details;
        }

        $message = $this->redact($message);
        $this->logger->error('vk: ' . $message);
        throwServerException($message);
    }
}
