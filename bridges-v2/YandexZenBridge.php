<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;
use RSSBridge\Utils\Json;

final class YandexZenBridge extends BridgeAbstract
{
    public const NAME = 'Dzen';
    public const URI = 'https://dzen.ru';
    public const DESCRIPTION = 'Latest posts from the specified Dzen channel';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        '' => [
            'channelURL' => [
                'name' => 'Channel URL',
                'type' => 'text',
                'required' => true,
                'title' => 'https://dzen.ru/channelname or https://dzen.ru/id/xxxxxxxxxxxxxxxxxxxxxxxx',
                'exampleValue' => 'https://dzen.ru/dream_faity_diy',
            ],
            'limit' => [
                'name' => 'Limit',
                'type' => 'number',
                'title' => 'Number of posts to display. Max is 20.',
                'defaultValue' => 10,
            ],
        ],
    ];

    private const EXPORT_API = 'https://dzen.ru/api/v3/launcher/export?country_code=ru&clid=1400&lang=ru&referrer_place=more&channel_name=';
    private const CHANNEL_ID_REGEX = '#^https?://dzen\.ru/id/(?<channelID>[a-z0-9]{24})(?:[^a-z0-9]|$)#i';
    private const CHANNEL_NAME_REGEX = '#^https?://dzen\.ru/(?<channelName>[A-Za-z0-9_.]+)#';
    private const CRAWLER_UA = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
    private const MAX_LIMIT = 20;

    private string $channelTitle = '';

    public function collectData(): void
    {
        $channelUrl = $this->getInput('channelURL');
        $channelUrl = is_string($channelUrl) === true ? trim($channelUrl) : '';
        if ($channelUrl === '') {
            throwClientException('Channel URL is required');
        }

        $channelName = $this->resolveChannelName($channelUrl);
        $data = Json::decode((string) getContents(self::EXPORT_API . rawurlencode($channelName), ['Accept: application/json']));

        if (is_array($data) === false) {
            throwServerException('Dzen returned an unexpected API response');
        }

        $this->channelTitle = $this->extractChannelTitle($data);
        if ($this->channelTitle === '') {
            $this->channelTitle = $channelName;
        }

        $items = $data['items'] ?? null;
        if (is_array($items) === false || $items === []) {
            throwServerException('Dzen returned no posts for this channel');
        }

        $limit = $this->getInput('limit');
        $limit = is_numeric($limit) === true ? (int) $limit : self::MAX_LIMIT;
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        foreach (array_slice($items, 0, $limit) as $post) {
            if (is_array($post) === false) {
                continue;
            }

            $uri = self::field($post, 'share_link');
            if ($uri === '') {
                $uri = self::field($post, 'link');
            }
            if ($uri === '') {
                continue;
            }

            $title = self::field($post, 'title');
            $text = self::field($post, 'text');
            $image = self::field($post, 'image');

            $content = $text !== '' ? nl2br(e($text)) : '';
            if ($image !== '') {
                $content .= sprintf('<br /><img src="%s" alt="" loading="lazy" />', e($image));
            }

            $item = [
                'title' => $title !== '' ? $title : '(no title)',
                'uri' => $uri,
                'content' => $content,
            ];

            $timestamp = $post['publication_date'] ?? null;
            if (is_numeric($timestamp) === true && (int) $timestamp > 0) {
                $item['timestamp'] = (int) $timestamp;
            }

            $uid = self::field($post, 'id');
            if ($uid !== '') {
                $item['uid'] = $uri . '#' . $uid;
            }

            $this->items[] = $item;
        }
    }

    public function getName(): string
    {
        return $this->channelTitle !== '' ? $this->channelTitle : self::NAME;
    }

    public function getURI(): string
    {
        $channelUrl = $this->getInput('channelURL');
        return is_string($channelUrl) === true && $channelUrl !== '' ? $channelUrl : self::URI;
    }

    private function resolveChannelName(string $channelUrl): string
    {
        if (preg_match(self::CHANNEL_ID_REGEX, $channelUrl, $m) === 1) {
            return $this->channelNameFromId($m['channelID']);
        }

        if (preg_match(self::CHANNEL_NAME_REGEX, $channelUrl, $m) === 1) {
            return $m['channelName'];
        }

        throwClientException('Invalid channel URL. Use https://dzen.ru/channelname or https://dzen.ru/id/<24-character id>');
    }

    private function channelNameFromId(string $channelId): string
    {
        $cacheKey = 'channel_id_' . $channelId;
        $cached = $this->loadCacheValue($cacheKey);
        if (is_string($cached) === true && $cached !== '') {
            return $cached;
        }

        $html = (string) getContents('https://dzen.ru/id/' . $channelId, ['User-Agent: ' . self::CRAWLER_UA]);
        $canonical = '';
        if ($html !== '') {
            $dom = parseHtmlDocument($html);
            $canonical = $dom->querySelector('link[rel="canonical"]')?->getAttribute('href') ?? '';
            if ($canonical === '') {
                $canonical = $dom->querySelector('meta[property="og:url"]')?->getAttribute('content') ?? '';
            }
        }

        if ($canonical !== '' && preg_match(self::CHANNEL_NAME_REGEX, $canonical, $m) === 1) {
            $this->saveCacheValue($cacheKey, $m['channelName'], 604800);
            return $m['channelName'];
        }

        throwClientException('Could not resolve this channel ID to a channel name. Open the channel in a browser and use its https://dzen.ru/channelname URL instead.');
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function extractChannelTitle(array $data): string
    {
        $channel = $data['channel'] ?? null;
        if (is_array($channel) === false) {
            return '';
        }

        $source = $channel['source'] ?? null;
        if (is_array($source) === false) {
            return '';
        }

        return trim(self::field($source, 'title'));
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function field(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        return is_string($value) === true ? $value : '';
    }
}
