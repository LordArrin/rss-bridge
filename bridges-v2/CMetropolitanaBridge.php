<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;
use RSSBridge\Utils\Json;

final class CMetropolitanaBridge extends BridgeAbstract
{
    public const NAME = 'Carris Metropolitana';
    public const URI = 'https://carrismetropolitana.pt/';
    public const DESCRIPTION = 'Alerts from Carris Metropolitana public transport (Lisbon metropolitan area)';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 600;

    private const API_URL = 'https://go.tmlmobilidade.pt/hub/api/v1/LA3SO/alerts';

    public const PARAMETERS = [
        '' => [
            'limit' => [
                'name' => 'Number of alerts',
                'type' => 'number',
                'defaultValue' => 20,
                'title' => 'Maximum number of alerts to return (max 100)',
            ],
        ],
    ];

    public function collectData(): void
    {
        $limit = (int) ($this->getInput('limit') ?? 20);
        $limit = min(100, max(1, $limit));

        $body = (string) getContents(self::API_URL, ['Accept: application/json']);
        $data = Json::decode($body);

        if (is_array($data) === false || isset($data['data']) === false || is_array($data['data']) === false) {
            throwServerException('Unexpected response from Carris Metropolitana API');
        }

        $entries = $data['data'];
        usort($entries, static function (mixed $a, mixed $b): int {
            if (is_array($a) === false || is_array($b) === false) {
                return 0;
            }
            $at = $a['active_period_start_date'] ?? 0;
            $bt = $b['active_period_start_date'] ?? 0;
            if (is_int($at) === false || is_int($bt) === false) {
                return 0;
            }
            return $bt <=> $at;
        });

        foreach (array_slice($entries, 0, $limit) as $entry) {
            if (is_array($entry) === false) {
                continue;
            }

            $id = $this->stringField($entry, '_id');
            $title = $this->stringField($entry, 'title');
            $description = $this->stringField($entry, 'description');
            $cause = $this->stringField($entry, 'cause');
            $effect = $this->stringField($entry, 'effect');
            $imageUrl = $this->stringField($entry, 'image_url');

            if ($id === '' || $title === '') {
                continue;
            }

            $rawStart = $entry['active_period_start_date'] ?? null;
            $rawEnd = $entry['active_period_end_date'] ?? null;
            $startTs = is_int($rawStart) === true ? (int) ($rawStart / 1000) : 0;
            $endTs = is_int($rawEnd) === true ? (int) ($rawEnd / 1000) : 0;

            $content = '<p>' . e($description) . '</p>';
            if ($endTs > 0) {
                $content .= '<p><small>Until: ' . e(date('Y-m-d H:i', $endTs)) . ' UTC</small></p>';
            }
            if ($imageUrl !== '') {
                $content .= '<p><img src="' . e($imageUrl) . '" alt=""></p>';
            }

            $categories = [];
            if ($effect !== '') {
                $categories[] = $effect;
            }
            if ($cause !== '') {
                $categories[] = $cause;
            }

            $enclosures = [];
            if ($imageUrl !== '') {
                $enclosures[] = $imageUrl;
            }

            $item = [
                'uri' => self::URI . 'alerts/' . $id,
                'title' => strip_invisible_unicode($title),
                'content' => $content,
                'uid' => $id,
            ];
            if ($startTs > 0) {
                $item['timestamp'] = $startTs;
            }
            if ($categories !== []) {
                $item['categories'] = $categories;
            }
            if ($enclosures !== []) {
                $item['enclosures'] = $enclosures;
            }

            $this->items[] = $item;
        }

        if ($this->items === []) {
            throwServerException('No valid alerts returned by the Carris Metropolitana API');
        }
    }

    /**
     * @param array<array-key, mixed> $entry
     */
    private function stringField(array $entry, string $key): string
    {
        $value = $entry[$key] ?? null;

        return is_string($value) === true ? trim($value) : '';
    }
}
