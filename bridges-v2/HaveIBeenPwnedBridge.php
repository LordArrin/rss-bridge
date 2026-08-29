<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class HaveIBeenPwnedBridge extends BridgeAbstract
{
    public const NAME = 'Have I Been Pwned (HIBP)';
    public const URI = 'https://haveibeenpwned.com';
    public const API_URI = 'https://haveibeenpwned.com/api/v3';
    public const DESCRIPTION = 'Returns list of Pwned websites';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        [
            'order' => [
                'name' => 'Order by',
                'type' => 'list',
                'values' => [
                    'Breach date' => 'breachDate',
                    'Date added to HIBP' => 'dateAdded',
                ],
                'defaultValue' => 'dateAdded',
            ],
            'item_limit' => [
                'name' => 'Limit number of returned items',
                'type' => 'number',
                'required' => true,
                'defaultValue' => 20,
            ]
        ]
    ];

    private const BREACH_TYPES = [
        'IsVerified' => [
            false => 'Unverified breach, may be sourced from elsewhere'
        ],
        'IsFabricated' => [
            true => 'Fabricated breach, likely not legitimate'
        ],
        'IsSensitive' => [
            true => 'Sensitive breach, not publicly searchable'
        ],
        'IsRetired' => [
            true => 'Retired breach, removed from system'
        ],
        'IsSpamList' => [
            true => 'Spam list, used for spam marketing'
        ],
        'IsMalware' => [
            true => 'Malware breach'
        ],
    ];

    private array $breaches = [];

    public function collectData(): void
    {
        $json = getContents(self::API_URI . '/breaches');
        $data = json_decode($json, true);

        if (is_array($data) === false) {
            \throwServerException('Invalid API response format');
        }

        foreach ($data as $breach) {
            if (is_array($breach) === false) {
                continue;
            }

            $item = [];

            $pwnCount = number_format((float) ($breach['PwnCount'] ?? 0));
            $title = (string) ($breach['Title'] ?? 'Unknown');

            $item['title'] = $title . ' - ' . $pwnCount . ' breached accounts';

            $addedDateRaw = (string) ($breach['AddedDate'] ?? '');
            $breachDateRaw = (string) ($breach['BreachDate'] ?? '');

            $item['dateAdded'] = $addedDateRaw;
            $item['breachDate'] = $breachDateRaw;
            $item['uri'] = self::URI . '/breach/' . (string) ($breach['Name'] ?? '');

            $description = (string) ($breach['Description'] ?? '');
            $item['content'] = '<p>' . $description . '</p>';
            $item['content'] .= '<p>' . $this->breachType($breach) . '</p>';

            $breachTimestamp = strtotime($breachDateRaw);
            if ($breachTimestamp === false) {
                $breachTimestamp = time();
            }
            $breachDate = date('j F Y', $breachTimestamp);

            $addedTimestamp = strtotime($addedDateRaw);
            if ($addedTimestamp === false) {
                $addedTimestamp = time();
            }
            $addedDate = date('j F Y', $addedTimestamp);

            $dataClasses = $breach['DataClasses'] ?? [];
            if (is_array($dataClasses) === false) {
                $dataClasses = [];
            }
            $compData = implode(', ', $dataClasses);

            $item['content'] .= <<<EOD
<p>
<strong>Breach date:</strong> {$breachDate}<br>
<strong>Date added to HIBP:</strong> {$addedDate}<br>
<strong>Compromised accounts:</strong> {$pwnCount}<br>
<strong>Compromised data:</strong> {$compData}<br>
</p>
EOD;
            $item['uid'] = (string) ($breach['Name'] ?? '');
            $this->breaches[] = $item;
        }

        $this->orderBreaches();
        $this->createItems();
    }

    private function breachType(array $breach): string
    {
        $content = '';

        foreach (self::BREACH_TYPES as $type => $message) {
            $breachValue = $breach[$type] ?? null;
            if (isset($message[$breachValue]) === true) {
                $content .= $message[$breachValue] . '.<br>';
            }
        }

        return $content;
    }

    private function orderBreaches(): void
    {
        $sortBy = (string) ($this->getInput('order') ?? 'dateAdded');
        $sort = [];

        foreach ($this->breaches as $key => $item) {
            $sort[$key] = $item[$sortBy] ?? '';
        }

        array_multisort($sort, SORT_DESC, $this->breaches);
    }

    private function createItems(): void
    {
        $limit = (int) ($this->getInput('item_limit') ?? 20);

        if ($limit < 1) {
            $limit = 20;
        }

        foreach ($this->breaches as $breach) {
            $item = [];

            $item['title'] = (string) ($breach['title'] ?? '');
            $sortBy = (string) ($this->getInput('order') ?? 'dateAdded');
            $item['timestamp'] = (string) ($breach[$sortBy] ?? '');
            $item['uri'] = (string) ($breach['uri'] ?? '');
            $item['content'] = (string) ($breach['content'] ?? '');
            $item['uid'] = (string) ($breach['uid'] ?? '');

            $this->items[] = $item;

            if (count($this->items) >= $limit) {
                break;
            }
        }
    }
}
