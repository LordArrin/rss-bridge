<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class ARDMediathekBridge extends BridgeAbstract
{
    public const NAME = 'ARD-Mediathek';
    public const URI = 'https://www.ardmediathek.de';
    public const DESCRIPTION = 'Feed of any series in the ARD-Mediathek, specified by its path';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        [
            'path' => [
                'name' => 'Show Link or ID',
                'required' => true,
                'title' => 'Link to the show page or just its alphanumeric suffix',
                'exampleValue' => 'https://www.ardmediathek.de/sendung/45-min/Y3JpZDovL25kci5kZS8xMzkx/',
            ],
        ],
    ];

    private const API_DOMAIN = 'https://api.ardmediathek.de';
    private const API_ENDPOINT = '/page-gateway/widgets/ard/asset/';
    private const VIDEO_PATH = '/video/';
    private const PAGESIZE = 29;
    private const MAX_IMAGE_WIDTH = 2000;
    private const IMAGE_WIDTH_PLACEHOLDER = '{width}';
    private const SHOW_ID_PATTERN = '~(Y3JpZDov[^/]+)~';

    private const CSS = [
        'image' => 'display: block; max-width: 500px; height: auto; margin: 10px 0;',
    ];

    private ?string $title = null;

    public function collectData()
    {
        $path = (string)$this->getInput('path');

        if (preg_match(self::SHOW_ID_PATTERN, $path, $matches) !== 1) {
            throwClientException('Could not extract show ID');
        }

        if (isset($matches[1]) === false || $matches[1] === '') {
            throwClientException('Could not extract show ID');
        }

        $showId = $matches[1];
        $url = self::API_DOMAIN . self::API_ENDPOINT . $showId . '?pageSize=' . self::PAGESIZE;

        $rawJson = getContents($url);
        $processedJson = json_decode($rawJson);

        if (is_object($processedJson) === false || isset($processedJson->teasers) === false || is_array($processedJson->teasers) === false) {
            throwServerException('Invalid response from ARD API');
        }

        if (isset($processedJson->title) === true) {
            $this->title = (string)$processedJson->title;
        }

        $timezone = new \DateTimeZone('Europe/Berlin');

        foreach ($processedJson->teasers as $video) {
            if (is_object($video) === false) {
                continue;
            }

            $videoId = (string)($video->id ?? '');
            if ($videoId === '') {
                continue;
            }

            $imageUrl = $this->extractImageUrl($video);

            $item = [];
            $item['uri'] = self::URI . self::VIDEO_PATH . $videoId . '/';
            $item['title'] = (string)($video->longTitle ?? $videoId);
            $item['uid'] = $videoId;
            $item['author'] = isset($video->publicationService->name) ? (string)$video->publicationService->name : null;
            $item['content'] = $this->buildItemContent($imageUrl);

            $broadcastedOn = $video->broadcastedOn ?? null;
            if (is_string($broadcastedOn) === true && $broadcastedOn !== '') {
                try {
                    $date = new \DateTimeImmutable($broadcastedOn, $timezone);
                    $item['timestamp'] = $date->getTimestamp();
                } catch (\Exception $e) {
                }
            }

            $this->items[] = $item;
        }
    }

    public function getName()
    {
        if ($this->title !== null && $this->title !== '') {
            return $this->title;
        }

        return parent::getName();
    }

    private function extractImageUrl(object $video): ?string
    {
        if (isset($video->images->aspect16x9->src) === false) {
            return null;
        }

        $src = (string)$video->images->aspect16x9->src;
        if ($src === '') {
            return null;
        }

        return str_replace(self::IMAGE_WIDTH_PLACEHOLDER, (string)self::MAX_IMAGE_WIDTH, $src);
    }

    private function buildItemContent(?string $imageUrl): string
    {
        if ($imageUrl === null) {
            return '';
        }

        return '<img src="' . e($imageUrl) . '" style="' . self::CSS['image'] . '" />';
    }
}
