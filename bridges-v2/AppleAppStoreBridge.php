<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class AppleAppStoreBridge extends BridgeAbstract
{
    public const NAME = 'Apple App Store';
    public const URI = 'https://apps.apple.com/';
    public const DESCRIPTION = 'Returns version updates for a specific application';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [[
        'id' => [
            'name' => 'Application ID',
            'required' => true,
            'exampleValue' => '310633997',
        ],
        'country' => [
            'name' => 'Store Country',
            'type' => 'list',
            'values' => [
                // North America & Anglosphere
                'United States' => 'US',
                'Canada' => 'CA',
                'United Kingdom' => 'UK',
                'Australia' => 'AU',
                'New Zealand' => 'NZ',
                'Ireland' => 'IE',

                // Western Europe
                'Germany' => 'DE',
                'France' => 'FR',
                'Italy' => 'IT',
                'Spain' => 'ES',
                'Portugal' => 'PT',
                'Netherlands' => 'NL',
                'Belgium (NL)' => 'BENL',
                'Belgium (FR)' => 'BEFR',
                'Switzerland' => 'CH',
                'Austria' => 'AT',

                // Scandinavia
                'Sweden' => 'SE',
                'Norway' => 'NO',
                'Denmark' => 'DK',
                'Finland' => 'FI',

                // Central & Eastern Europe
                'Poland' => 'PL',
                'Czech Republic' => 'CZ',
                'Hungary' => 'HU',
                'Romania' => 'RO',
                'Greece' => 'GR',

                // Post-Soviet
                'Russia' => 'RU',
                'Ukraine' => 'UA',
                'Belarus' => 'BY',
                'Kazakhstan' => 'KZ',
                'Armenia' => 'AM',
                'Azerbaijan' => 'AZ',
                'Georgia' => 'GE',
                'Moldova' => 'MD',
                'Uzbekistan' => 'UZ',
                'Kyrgyzstan' => 'KG',

                // East & Southeast Asia
                'Japan' => 'JP',
                'China' => 'CN',
                'South Korea' => 'KR',
                'Singapore' => 'SG',
                'Hong Kong' => 'HK',
                'Taiwan' => 'TW',
                'Malaysia' => 'MY',
                'Thailand' => 'TH',
                'Philippines' => 'PH',
                'Vietnam' => 'VN',
                'Indonesia' => 'ID',
                'India' => 'IN',

                // Middle East
                'Saudi Arabia' => 'SA',
                'United Arab Emirates' => 'AE',
                'Israel' => 'IL',
                'Turkey' => 'TR',

                // Latin America
                'Mexico' => 'MX',
                'Brazil' => 'BR',
                'Argentina' => 'AR',
                'Chile' => 'CL',
                'Colombia' => 'CO',
                'Peru' => 'PE',

                // Africa
                'South Africa' => 'ZA',
            ],
            'defaultValue' => 'US',
        ],
    ]];

    private ?string $appName = null;

    private function makeHtmlUrl(): string
    {
        $id = (string)$this->getInput('id');
        $country = strtolower((string)$this->getInput('country'));
        return sprintf('https://apps.apple.com/%s/app/id%s', $country, $id);
    }

    public function getName()
    {
        if ($this->appName !== null) {
            return $this->appName;
        }

        return parent::getName();
    }

    private function getAppData(): array
    {
        $url = $this->makeHtmlUrl();
        $content = getContents($url);

        $startTag = '<script type="application/json" id="serialized-server-data">';
        $startPos = strpos($content, $startTag);
        if ($startPos === false) {
            throw new \Exception('Failed to locate serialized server data in HTML page');
        }

        $jsonStart = $startPos + strlen($startTag);
        $endPos = strpos($content, '</script>', $jsonStart);
        if ($endPos === false) {
            throw new \Exception('Failed to locate end of serialized server data');
        }

        $serializedServerData = html_entity_decode(
            substr($content, $jsonStart, $endPos - $jsonStart),
            ENT_QUOTES | ENT_HTML5
        );

        $json = json_decode($serializedServerData, true);
        if (is_array($json) === false) {
            throw new \Exception('Failed to parse serialized server data');
        }

        if (isset($json['data']) === false || empty($json['data']) === true) {
            throw new \Exception('No app data found in serialized server data');
        }

        return $json['data'][0]['data'] ?? $json['data'][0];
    }

    private function extractAppDetails(array $data): array
    {
        if (isset($data['lockup']) === true) {
            $this->appName = $data['lockup']['title'] ?? null;
            $author = $data['developerAction']['title'] ?? ($data['lockup']['developerTagline'] ?? null);
            return [$this->appName, $author];
        }

        if (isset($data['title']) === true) {
            $this->appName = $data['title'];
            $author = $data['developerAction']['title'] ?? ($data['lockup']['developerTagline'] ?? null);
            return [$this->appName, $author];
        }

        if (isset($data['attributes']) === true) {
            $this->appName = $data['attributes']['name'] ?? null;
            $author = $data['attributes']['artistName'] ?? null;
            return [$this->appName, $author];
        }

        $this->appName = sprintf('App %s', $this->getInput('id'));
        return [$this->appName, 'Unknown Developer'];
    }

    private function getVersionHistory(array $data): array
    {
        $versionHistory = [];

        $pageData = $data['shelfMapping']['mostRecentVersion']['seeAllAction']['pageData'] ?? [];
        $shelves = $pageData['shelves'] ?? [];

        foreach ($shelves as $shelf) {
            if (is_array($shelf) === false) {
                continue;
            }

            foreach (($shelf['items'] ?? []) as $entry) {
                if (is_array($entry) === false) {
                    continue;
                }

                if (($entry['$kind'] ?? null) !== 'TitledParagraph') {
                    continue;
                }

                $versionHistory[] = [
                    'versionDisplay' => $entry['primarySubtitle'] ?? 'Unknown Version',
                    'releaseNotes' => $entry['text'] ?? 'No release notes available',
                    'releaseDate' => $entry['secondarySubtitle'] ?? null,
                ];
            }
        }

        return $versionHistory;
    }

    public function collectData()
    {
        $data = $this->getAppData();

        [$name, $author] = $this->extractAppDetails($data);

        $versionHistory = $this->getVersionHistory($data);

        foreach ($versionHistory as $entry) {
            $version = $entry['versionDisplay'] ?? 'Unknown Version';
            $releaseNotes = $entry['releaseNotes'] ?? 'No release notes available';
            $releaseDate = $entry['releaseDate'] ?? 'Unknown Date';

            $item = [];
            $item['title'] = $version;

            $contentHtml = nl2br(e($releaseNotes));
            if ($contentHtml === '') {
                $contentHtml = 'No release notes available';
            }
            $item['content'] = $contentHtml;

            $parsedTimestamp = strtotime($releaseDate);
            if ($parsedTimestamp !== false) {
                $item['timestamp'] = $parsedTimestamp;
            } else {
                $item['timestamp'] = $releaseDate;
            }

            $item['author'] = $author;
            $item['uri'] = $data['canonicalURL'] ?? $this->makeHtmlUrl();

            $this->items[] = $item;
        }
    }
}
