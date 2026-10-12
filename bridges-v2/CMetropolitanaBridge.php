<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;
use RSSBridge\Http\HttpException;

use function RSSBridge\Exceptions\throwServerException;

final class CMetropolitanaBridge extends BridgeAbstract
{
    public const NAME = 'CMetropolitana';
    public const URI = 'https://carrismetropolitana.pt';
    public const DESCRIPTION = 'CMetropolitana | Alertas';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public function collectData(): void
    {
        $apiUrl = 'https://go.tmlmobilidade.pt/hub/api/v1/alerts';

        try {
            $json = getContents($apiUrl);
        } catch (HttpException $e) {
            throwServerException('Failed to fetch alerts: ' . $e->getMessage());
        }

        $data = json_decode($json, false);

        if (is_object($data) === false) {
            throwServerException('Invalid API response format');
        }

        if (isset($data->data) === false || is_array($data->data) === false) {
            return;
        }

        foreach ($data->data as $entry) {
            if (is_object($entry) === false) {
                continue;
            }

            $id = (string) ($entry->_id ?? '');
            $title = (string) ($entry->title ?? '');
            $description = (string) ($entry->description ?? '');
            $startDate = (int) ($entry->active_period_start_date ?? 0);

            if ($id === '' || $title === '') {
                continue;
            }

            $item = [
                'uri' => self::URI . '/alerts/' . $id,
                'title' => $title,
                'timestamp' => $startDate > 0 ? (int) ($startDate / 1000) : time(),
                'content' => $description,
            ];

            $this->items[] = $item;
        }
    }
}
