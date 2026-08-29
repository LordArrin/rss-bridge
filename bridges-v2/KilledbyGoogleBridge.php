<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class KilledbyGoogleBridge extends BridgeAbstract
{
    public const NAME = 'Killed by Google';
    public const URI = 'https://killedbygoogle.com';
    public const DESCRIPTION = 'Returns list of recently discontinued Google services, products, devices and apps';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [];

    private const CSS = [
        'img' => 'display: block; float: none; clear: both; max-width: 50px; width: auto; height: auto; margin: 16px 0; padding: 0;',
    ];

    private const ITEMS_LIMIT = 15;

    public function collectData(): void
    {
        $json = getContents(self::URI . '/graveyard.json');

        if (is_string($json) === false || $json === '') {
            \throwServerException('Empty response from Killed by Google API');
        }

        $this->handleJson($json);
        $this->orderItems();
    }

    private function handleJson(string $json): void
    {
        $graveyard = \Json::decode($json);

        if (is_array($graveyard) === false) {
            \throwServerException('Invalid JSON response from Killed by Google API');
        }

        $currentDate = new \DateTime();

        foreach ($graveyard as $tombstone) {
            if (is_array($tombstone) === false) {
                continue;
            }

            $dateOpen = (string) ($tombstone['dateOpen'] ?? '');
            $dateClose = (string) ($tombstone['dateClose'] ?? '');

            if ($dateOpen === '' || $dateClose === '') {
                continue;
            }

            try {
                $openDate = new \DateTime($dateOpen);
                $closeDate = new \DateTime($dateClose);
            } catch (\Exception $e) {
                continue;
            }

            if ($closeDate > $currentDate) {
                continue;
            }

            $yearOpened = $openDate->format('Y');
            $yearClosed = $closeDate->format('Y');

            $name = (string) ($tombstone['name'] ?? '');
            $slug = (string) ($tombstone['slug'] ?? '');
            $link = (string) ($tombstone['link'] ?? '');
            $description = (string) ($tombstone['description'] ?? '');

            if ($name === '' || $slug === '' || $link === '') {
                continue;
            }

            $timestamp = strtotime($dateClose);
            if ($timestamp === false) {
                $timestamp = time();
            }

            $imageUrl = 'https://static.killedbygoogle.com/com/tombstone.svg';

            $content = '<img src="' . htmlspecialchars($imageUrl) . '" alt="Tombstone" style="' . self::CSS['img'] . '" />';
            $content .= '<p>' . htmlspecialchars($description) . '</p>';
            $content .= '<p><a href="' . htmlspecialchars($link) . '">' . htmlspecialchars($link) . '</a></p>';

            $item = [
                'title' => $name . ' (' . $yearOpened . ' - ' . $yearClosed . ')',
                'uid' => $slug,
                'uri' => $link,
                'timestamp' => $timestamp,
                'content' => $content,
            ];

            $this->items[] = $item;
        }
    }

    private function orderItems(): void
    {
        $sort = [];

        foreach ($this->items as $key => $item) {
            if (is_array($item) === false) {
                continue;
            }
            $sort[$key] = $item['timestamp'] ?? 0;
        }

        if (count($sort) > 0) {
            array_multisort($sort, SORT_DESC, $this->items);
        }

        $this->items = array_slice($this->items, 0, self::ITEMS_LIMIT);
    }
}
