<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;
use RSSBridge\Utils\Json;

use function urljoin;

final class MangaDexBridge extends BridgeAbstract
{
    public const NAME = 'MangaDex';
    public const URI = 'https://mangadex.org/';
    public const DESCRIPTION = 'Returns MangaDex items using the API';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 1800;

    public const PARAMETERS = [
        'global' => [
            'limit' => [
                'name' => 'Item Limit',
                'type' => 'number',
                'defaultValue' => 10,
                'required' => true
            ],
            'lang' => [
                'name' => 'Chapter Languages (default=all)',
                'title' => 'comma-separated, two-letter language codes (example "en,jp")',
                'exampleValue' => 'en,jp',
                'required' => false
            ],
            'images' => [
                'name' => 'Fetch chapter page images',
                'type' => 'list',
                'title' => 'Places chapter images in feed contents. Entries will consume more bandwidth.',
                'defaultValue' => 'no',
                'values' => [
                    'None' => 'no',
                    'Data Saver' => 'saver',
                    'Full Quality' => 'yes'
                ]
            ]
        ],
        'Title Chapters' => [
            'url' => [
                'name' => 'URL to title page',
                'exampleValue' => 'https://mangadex.org/title/f9c33607-9180-4ba6-b85c-e4b5faee7192/official-test-manga',
                'required' => true
            ],
            'external' => [
                'name' => 'Allow external feed items',
                'type' => 'checkbox',
                'title' => 'Some chapters are inaccessible or only available on an external site. Include these?'
            ]
        ],
        'Search Chapters' => [
            'chapter' => [
                'name' => 'Chapter Number (default=all)',
                'title' => 'The example value finds the newest first chapters',
                'exampleValue' => 1,
                'required' => false
            ],
            'groups' => [
                'name' => 'Group UUID (default=all)',
                'title' => 'This can be found in the MangaDex Group Page URL',
                'exampleValue' => '00e03853-1b96-4f41-9542-c71b8692033b',
                'required' => false,
            ],
            'uploader' => [
                'name' => 'User UUID (default=all)',
                'title' => 'This can be found in the MangaDex User Page URL',
                'exampleValue' => 'd2ae45e0-b5e2-4e7f-a688-17925c2d7d6b',
                'required' => false,
            ],
            'external' => [
                'name' => 'Allow external feed items',
                'type' => 'checkbox',
                'title' => 'Some chapters are inaccessible or only available on an external site. Include these?'
            ]
        ]
    ];

    public const CONFIGURATION = [
        'access_token' => [
            'required' => false,
        ],
    ];

    private const API_ROOT = 'https://api.mangadex.org/';

    private const BASE_HEADERS = [
        'User-Agent: rss-bridge bot (https://github.com/LordArrin/rss-bridge)',
        'Sec-Ch-Ua:',
        'Sec-Ch-Ua-Mobile:',
        'Sec-Ch-Ua-Platform:',
        'Sec-Fetch-Dest:',
        'Sec-Fetch-Mode:',
        'Sec-Fetch-Site:',
        'Sec-Fetch-User:',
        'Upgrade-Insecure-Requests:',
        'Priority:',
    ];

    private const TITLE_REGEX = '#title/(?<uuid>[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})#';

    private string $feedName = '';
    private string $feedURI = '';

    public function getName(): string
    {
        switch ($this->queriedContext) {
            case 'Title Chapters':
                return $this->feedName . ' Chapters';
            case 'Search Chapters':
                return 'MangaDex Chapter Search';
            default:
                return parent::getName();
        }
    }

    public function getURI(): string
    {
        switch ($this->queriedContext) {
            case 'Title Chapters':
                return $this->feedURI;
            default:
                return parent::getURI();
        }
    }

    public function collectData(): void
    {
        $apiUri = $this->getAPI();
        $headers = $this->getHeaders();

        $response = getContents($apiUri, $headers);
        if (is_string($response) === false) {
            throwServerException('Unexpected response type from MangaDex API');
        }

        try {
            $content = Json::decode($response, true);
        } catch (\JsonException $e) {
            throwServerException('Failed to decode MangaDex API response');
        }

        if (is_array($content) === false || isset($content['result']) === false || $content['result'] !== 'ok') {
            throwServerException('Could not retrieve API results');
        }

        $data = $content['data'] ?? [];
        if (is_array($data) === false) {
            throwServerException('Invalid API response structure');
        }

        switch ($this->queriedContext) {
            case 'Title Chapters':
            case 'Search Chapters':
                $this->getChapters($data);
                break;
            default:
                throwServerException('Unimplemented Context (collectData)');
        }
    }

    private function getAPI(): string
    {
        $limit = $this->getInput('limit');
        if ($limit === null || $limit === '') {
            $limit = 10;
        }

        $params = [
            'limit' => (int) $limit,
            'order[readableAt]' => 'desc',
        ];

        $arrayParams = [];
        $langInput = $this->getInput('lang');
        if (is_string($langInput) === true && $langInput !== '') {
            $arrayParams['translatedLanguage[]'] = explode(',', $langInput);
        }

        switch ($this->queriedContext) {
            case 'Title Chapters':
                $urlInput = (string) $this->getInput('url');
                if (preg_match(self::TITLE_REGEX, $urlInput, $matches) !== 1) {
                    throwClientException('Invalid URL Parameter');
                }
                $uuid = $matches['uuid'];
                $this->feedURI = urljoin(self::URI, 'title/' . $uuid);
                $externalInput = $this->getInput('external');
                if ($externalInput === false || $externalInput === null || $externalInput === '') {
                    $params['includeFutureUpdates'] = '0';
                }
                $arrayParams['includes[]'] = ['manga', 'scanlation_group', 'user'];
                $uri = self::API_ROOT . 'manga/' . $uuid . '/feed';
                break;
            case 'Search Chapters':
                $chapterInput = $this->getInput('chapter');
                if (is_string($chapterInput) === true && $chapterInput !== '') {
                    $params['chapter'] = $chapterInput;
                } elseif (is_int($chapterInput) === true) {
                    $params['chapter'] = (string) $chapterInput;
                }
                $groupsInput = $this->getInput('groups');
                if (is_string($groupsInput) === true && $groupsInput !== '') {
                    $params['groups[]'] = $groupsInput;
                }
                $uploaderInput = $this->getInput('uploader');
                if (is_string($uploaderInput) === true && $uploaderInput !== '') {
                    $params['uploader'] = $uploaderInput;
                }
                $externalInput = $this->getInput('external');
                if ($externalInput === false || $externalInput === null || $externalInput === '') {
                    $params['includeFutureUpdates'] = '0';
                }
                $arrayParams['includes[]'] = ['manga', 'scanlation_group', 'user'];
                $uri = self::API_ROOT . 'chapter';
                break;
            default:
                throwServerException('Unimplemented Context (getAPI)');
        }

        $uri .= '?' . http_build_query($params);

        foreach ($arrayParams as $name => $arrayParam) {
            foreach ($arrayParam as $item) {
                $uri .= '&' . $name . '=' . $item;
            }
        }

        return $uri;
    }

    /**
     * @param array<mixed> $content
     */
    private function getChapters(array $content): void
    {
        foreach ($content as $chapter) {
            if (is_array($chapter) === false) {
                continue;
            }

            $chapterId = $chapter['id'] ?? '';
            if (is_string($chapterId) === false || $chapterId === '') {
                continue;
            }

            $attributes = $chapter['attributes'] ?? [];
            if (is_array($attributes) === false) {
                continue;
            }

            $pages = $attributes['pages'] ?? 0;
            $externalInput = $this->getInput('external');
            if (($externalInput === false || $externalInput === null || $externalInput === '') && (int) $pages === 0) {
                continue;
            }

            $item = [
                'uid' => $chapterId,
                'uri' => urljoin(self::URI, 'chapter/' . $chapterId),
            ];

            $title = '';
            if (isset($attributes['volume']) === true && is_string($attributes['volume']) === true && $attributes['volume'] !== '') {
                $title .= 'Volume ' . $attributes['volume'] . ' ';
            }
            if (isset($attributes['chapter']) === true && is_string($attributes['chapter']) === true && $attributes['chapter'] !== '') {
                $title .= 'Chapter ' . $attributes['chapter'];
            }
            if (isset($attributes['title']) === true && is_string($attributes['title']) === true && $attributes['title'] !== '') {
                $title .= ' - ' . $attributes['title'];
            }
            $translatedLanguage = $attributes['translatedLanguage'] ?? 'unknown';
            if (is_string($translatedLanguage) === false) {
                $translatedLanguage = 'unknown';
            }
            $title .= ' [' . $translatedLanguage . ']';

            $item['title'] = $title;

            $readableAt = $attributes['readableAt'] ?? '';
            if (is_string($readableAt) === true && $readableAt !== '') {
                $timestamp = strtotime($readableAt);
                if ($timestamp !== false) {
                    $item['timestamp'] = $timestamp;
                }
            }

            $groups = [];
            $users = [];
            $relationships = $chapter['relationships'] ?? [];
            if (is_array($relationships) === true) {
                foreach ($relationships as $rel) {
                    if (is_array($rel) === false) {
                        continue;
                    }

                    $relType = $rel['type'] ?? '';
                    $relAttributes = $rel['attributes'] ?? [];

                    if (is_string($relType) === false || is_array($relAttributes) === false) {
                        continue;
                    }

                    switch ($relType) {
                        case 'scanlation_group':
                            $groupName = $relAttributes['name'] ?? '';
                            if (is_string($groupName) === true && $groupName !== '') {
                                $groups[] = $groupName;
                            }
                            break;
                        case 'manga':
                            $mangaTitle = $relAttributes['title'] ?? [];
                            if (is_array($mangaTitle) === true && $mangaTitle !== []) {
                                $firstTitle = reset($mangaTitle);
                                if (is_string($firstTitle) === true && $firstTitle !== '') {
                                    if ($this->feedName === '') {
                                        $this->feedName = $firstTitle;
                                    }
                                    if ($this->queriedContext !== 'Title Chapters') {
                                        $item['title'] = $firstTitle . ' ' . $item['title'];
                                    }
                                }
                            }
                            break;
                        case 'user':
                            $username = $relAttributes['username'] ?? '';
                            if (is_string($username) === true && $username !== '') {
                                if (isset($item['author']) === false) {
                                    $item['author'] = $username;
                                } else {
                                    $users[] = $username;
                                }
                            }
                            break;
                    }
                }
            }

            $contentText = 'Groups: ';
            if ($groups === []) {
                $contentText .= 'No Group';
            } else {
                $contentText .= implode(', ', $groups);
            }

            if ($users !== []) {
                $contentText .= '<br>Other Users: ' . implode(', ', $users);
            }

            $imagesInput = $this->getInput('images');
            if ($imagesInput !== 'no') {
                $apiUri = self::API_ROOT . 'at-home/server/' . $chapterId;
                $headers = $this->getHeaders();

                $pagesResponse = getContents($apiUri, $headers);
                if (is_string($pagesResponse) === false) {
                    continue;
                }

                try {
                    $pagesData = Json::decode($pagesResponse, true);
                } catch (\JsonException $e) {
                    continue;
                }

                if (is_array($pagesData) === false || isset($pagesData['result']) === false || $pagesData['result'] !== 'ok') {
                    continue;
                }

                $baseUrl = $pagesData['baseUrl'] ?? '';
                $chapterData = $pagesData['chapter'] ?? [];

                if (is_string($baseUrl) === false || is_array($chapterData) === false) {
                    continue;
                }

                $chapterHash = $chapterData['hash'] ?? '';
                if (is_string($chapterHash) === false) {
                    continue;
                }

                $saverData = $chapterData['dataSaver'] ?? [];
                $fullData = $chapterData['data'] ?? [];

                if ($imagesInput === 'saver') {
                    if (is_array($saverData) === true && is_array($fullData) === true) {
                        $saverBase = $baseUrl . '/data-saver/' . $chapterHash . '/';
                        $fullBase = $baseUrl . '/data/' . $chapterHash . '/';

                        foreach ($saverData as $index => $saverImage) {
                            if (is_string($saverImage) === false) {
                                continue;
                            }

                            $fullImageUrl = '';
                            if (isset($fullData[$index]) === true && is_string($fullData[$index]) === true) {
                                $fullImageUrl = $fullBase . $fullData[$index];
                            }

                            $imageUrl = $this->getImageUrlWithFallback($saverBase . $saverImage, $fullImageUrl);

                            $contentText .= '<br><img src="' . e($imageUrl) . '" style="display: block; max-width: 1200px; width: 100%; height: auto; margin: 1em 0;">';
                        }
                    }
                } else {
                    if (is_array($fullData) === true) {
                        $pageBase = $baseUrl . '/data/' . $chapterHash . '/';
                        foreach ($fullData as $image) {
                            if (is_string($image) === true) {
                                $contentText .= '<br><img src="' . e($pageBase . $image) . '" style="display: block; max-width: 1200px; width: 100%; height: auto; margin: 1em 0;">';
                            }
                        }
                    }
                }
            }

            $item['content'] = $contentText;
            $this->items[] = $item;
        }
    }

    private function getImageUrlWithFallback(string $saverUrl, string $fullUrl): string
    {
        if ($fullUrl === '') {
            return $saverUrl;
        }

        $cacheKey = 'mangadex_image_' . md5($saverUrl);
        $cachedResult = $this->loadCacheValue($cacheKey);

        if (is_string($cachedResult) === true && $cachedResult !== '') {
            return $cachedResult;
        }

        try {
            $headers = $this->getHeaders();
            $response = getContents($saverUrl, $headers);

            if (is_string($response) === true && $response !== '') {
                $this->saveCacheValue($cacheKey, $saverUrl, 86400);
                return $saverUrl;
            }
        } catch (\Exception $e) {
        }

        try {
            $headers = $this->getHeaders();
            $response = getContents($fullUrl, $headers);

            if (is_string($response) === true && $response !== '') {
                $this->saveCacheValue($cacheKey, $fullUrl, 86400);
                return $fullUrl;
            }
        } catch (\Exception $e) {
        }

        return $saverUrl;
    }

    /**
     * @return array<string>
     */
    private function getHeaders(): array
    {
        $headers = array_merge(self::BASE_HEADERS, ['Content-Type: application/json']);

        $accessToken = $this->getOption('access_token');
        if (is_string($accessToken) === true && $accessToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $accessToken;
        }

        return $headers;
    }
}
