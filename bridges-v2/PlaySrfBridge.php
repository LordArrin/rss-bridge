<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class PlaySrfBridge extends BridgeAbstract
{
    public const NAME = 'Play SRF / RTS / RSI / RTR';
    public const URI = 'https://www.srf.ch/play/tv';
    public const DESCRIPTION = 'Feed of any show in the Play SRF / RTS / RSI / RTR portals, specified by its ID';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        [
            'showId' => [
                'name' => 'URL of Show Page',
                'required' => true,
                'title' => 'Insert the URL to the page of a show.',
                'exampleValue' => 'https://www.srf.ch/play/tv/sendung/arena?id=09784065-687b-4b60-bd23-9ed0d2d43cdc'
            ],
            'limit' => self::LIMIT,
        ]
    ];

    private string $title = '';

    public function collectData(): void
    {
        $showIdInput = (string) ($this->getInput('showId') ?? '');

        if (preg_match('/[a-z0-9]{8}\-[a-z0-9]{4}\-[a-z0-9]{4}\-[a-z0-9]{4}\-[a-z0-9]{12}$|[0-9]{5,10}$/s', $showIdInput, $matchShowId) !== 1) {
            \throwClientException('Invalid show URL format');
        }
        if (preg_match('/(?<=https:\/{2}w{3}\.)[first]{3}/', $showIdInput, $matchRegion) !== 1) {
            \throwClientException('Invalid region in show URL');
        }

        $showId = (string) $matchShowId[0];
        $region = (string) $matchRegion[0];

        $limitInput = $this->getInput('limit');
        $limit = is_numeric($limitInput) === true ? (int) $limitInput : null;

        $apiUrl = 'https://www.' . $region . '.ch/play/v3/api/' . $region . '/production/videos-by-show-id?showId=' . $showId;
        $raw = getContents($apiUrl);

        if (is_string($raw) === false || $raw === '') {
            \throwServerException('Empty response from SRF API');
        }

        $jsonShowVideos = json_decode($raw, true);

        if (is_array($jsonShowVideos) === false) {
            \throwServerException('Invalid JSON response from SRF API');
        }

        $data = $jsonShowVideos['data']['data'] ?? null;
        if (is_array($data) === false || count($data) === 0) {
            \throwServerException('No videos found for this show');
        }

        $this->title = (string) ($data[0]['show']['title'] ?? 'Play SRF');
        $episodes = $data;

        if ($limit !== null && $limit > 0) {
            $episodes = array_slice($episodes, 0, $limit);
        }

        foreach ($episodes as $ep) {
            if (is_array($ep) === false) {
                continue;
            }

            $description = (string) ($ep['description'] ?? '');
            $lead = (string) ($ep['lead'] ?? '');

            $content = '';
            if ($description !== '') {
                $content .= '<p>' . nl2br($description, false) . '</p>';
            } else {
                $content .= '<p>' . nl2br($lead, false) . '</p>';
            }

            $item = [];

            $urn = (string) ($ep['urn'] ?? '');
            $item['uri'] = 'https://www.srf.ch/play/tv/-/video/-?urn=' . $urn;
            $item['title'] = (string) ($ep['title'] ?? '');

            $date = (string) ($ep['date'] ?? '');
            if ($date !== '') {
                $timestamp = strtotime($date);
                if ($timestamp !== false) {
                    $item['timestamp'] = $timestamp;
                }
            }

            $item['author'] = (string) ($ep['show']['title'] ?? '');
            $item['content'] = $content;
            $item['uid'] = $urn;

            $this->items[] = $item;
        }
    }

    public function getName(): string
    {
        if ($this->title !== '') {
            return $this->title;
        }
        return parent::getName();
    }
}
