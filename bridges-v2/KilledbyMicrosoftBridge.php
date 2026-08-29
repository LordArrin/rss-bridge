<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class KilledbyMicrosoftBridge extends BridgeAbstract
{
    public const NAME = 'Killed by Microsoft';
    public const URI = 'https://killedbymicrosoft.info';
    public const DESCRIPTION = 'Lists recently discontinued Microsoft products';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [];

    private const ITEMS_LIMIT = 15;

    public function collectData(): void
    {
        $json = getContents('https://killedbymicrosoft.info/graveyard.json');

        if (is_string($json) === false || $json === '') {
            \throwServerException('Empty response from Killed by Microsoft API');
        }

        $discontinuedServices = \Json::decode($json);

        if (is_array($discontinuedServices) === false) {
            \throwServerException('Invalid JSON response from Killed by Microsoft API');
        }

        usort($discontinuedServices, function (array $a, array $b): int {
            $tsA = strtotime($a['dateClose'] ?? '');
            $tsB = strtotime($b['dateClose'] ?? '');

            if ($tsA === false) {
                $tsA = 0;
            }
            if ($tsB === false) {
                $tsB = 0;
            }

            return $tsB <=> $tsA;
        });

        $discontinuedServices = array_slice($discontinuedServices, 0, self::ITEMS_LIMIT);

        foreach ($discontinuedServices as $service) {
            if (is_array($service) === false) {
                continue;
            }

            $name = (string) ($service['name'] ?? '');
            $dateOpen = (string) ($service['dateOpen'] ?? '');
            $dateClose = (string) ($service['dateClose'] ?? '');
            $description = (string) ($service['description'] ?? '');
            $slug = (string) ($service['slug'] ?? '');
            $link = (string) ($service['link'] ?? '');

            if ($name === '' || $slug === '' || $link === '') {
                continue;
            }

            $title = $this->formatTitle($name, $dateOpen, $dateClose);

            $content = '<p>' . htmlspecialchars($description) . '</p>';
            $content .= '<p>Scheduled closure on ' . htmlspecialchars($dateClose) . '.</p>';

            $timestamp = strtotime($dateClose);

            $item = [
                'title' => $title,
                'uid' => $slug,
                'uri' => $link,
                'content' => $content,
            ];

            if ($timestamp !== false && $timestamp > 0) {
                $item['timestamp'] = $timestamp;
            }

            $this->items[] = $item;
        }
    }

    private function formatTitle(string $name, string $dateOpen, string $dateClose): string
    {
        $yearOpen = '';
        $yearClose = '';

        if ($dateOpen !== '') {
            $ts = strtotime($dateOpen);
            if ($ts !== false) {
                $yearOpen = date('Y', $ts);
            }
        }

        if ($dateClose !== '') {
            $ts = strtotime($dateClose);
            if ($ts !== false) {
                $yearClose = date('Y', $ts);
            }
        }

        if ($yearOpen === '' || $yearClose === '') {
            return htmlspecialchars($name);
        }

        return htmlspecialchars($name) . ' (' . $yearOpen . ' - ' . $yearClose . ')';
    }
}
