<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class WorldbankBridge extends BridgeAbstract
{
    public const NAME = 'World Bank Group';
    public const URI = 'https://www.worldbank.org/en/news/all';
    public const DESCRIPTION = 'Return articles from The World Bank Group All News';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    private const API_BASE_URL = 'https://search.worldbank.org/api/v2/news?format=json';
    private const MAX_LIMIT = 40;

    public const PARAMETERS = [
        [
            'lang' => [
                'name' => 'Language',
                'type' => 'list',
                'defaultValue' => 'English',
                'values' => [
                    'English' => 'English',
                    'French' => 'French',
                ],
            ],
            'limit' => [
                'name' => 'limit (max 40)',
                'type' => 'number',
                'defaultValue' => 5,
                'required' => true,
            ],
        ],
    ];

    public function collectData(): void
    {
        $langInput = $this->getInput('lang');
        $limitInput = $this->getInput('limit');

        $lang = 'English';
        if (is_string($langInput) === true && $langInput !== '') {
            $lang = $langInput;
        }

        $limit = 5;
        if ($limitInput !== null && $limitInput !== '') {
            $limit = (int) $limitInput;
        }

        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > self::MAX_LIMIT) {
            $limit = self::MAX_LIMIT;
        }

        $apiUrl = self::API_BASE_URL
            . '&rows=' . $limit
            . '&lang_exact=' . urlencode($lang);

        $response = getContents($apiUrl);

        if ($response === '') {
            throwServerException('Failed to fetch data from World Bank API');
        }

        $jsonData = json_decode($response, false);

        if (is_object($jsonData) === false) {
            throwServerException('Failed to parse JSON response');
        }

        if (isset($jsonData->documents) === false || is_object($jsonData->documents) === false) {
            throwServerException('No documents found in response');
        }

        if (isset($jsonData->documents->facets) === true) {
            unset($jsonData->documents->facets);
        }

        $documents = get_object_vars($jsonData->documents);
        $currentTime = time();

        foreach ($documents as $key => $element) {
            if ($key === 'facets') {
                continue;
            }

            if (is_object($element) === false) {
                continue;
            }

            $uid = $this->extractProperty($element, 'id');
            $timestampRaw = $this->extractProperty($element, 'lnchdt');
            $titleRaw = $this->extractCdataProperty($element, 'title');
            $uri = $this->extractProperty($element, 'url');
            $descriptionRaw = $this->extractCdataProperty($element, 'descr');

            if ($uid === '' || $uri === '') {
                continue;
            }

            $timestamp = time();
            if ($timestampRaw !== '') {
                $parsed = strtotime($timestampRaw);
                if ($parsed !== false) {
                    $timestamp = $parsed;
                }
            }

            // Skip posts with future dates (API sometimes returns incorrect future dates)
            if ($timestamp > $currentTime) {
                continue;
            }

            $this->items[] = [
                'uid' => $uid,
                'timestamp' => $timestamp,
                'title' => $titleRaw,
                'uri' => $uri,
                'content' => $descriptionRaw,
            ];
        }

        if ($this->items === []) {
            throwServerException('No items could be extracted from the API response');
        }
    }

    private function extractProperty(object $element, string $property): string
    {
        if (isset($element->{$property}) === false) {
            return '';
        }

        $value = $element->{$property};

        if (is_string($value) === true) {
            return $value;
        }

        if (is_int($value) === true || is_float($value) === true) {
            return (string) $value;
        }

        return '';
    }

    private function extractCdataProperty(object $element, string $property): string
    {
        if (isset($element->{$property}) === false) {
            return '';
        }

        $value = $element->{$property};

        if (is_string($value) === true) {
            return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }

        if (is_object($value) === true && isset($value->{'cdata!'}) === true) {
            $cdata = $value->{'cdata!'};
            if (is_string($cdata) === true) {
                return htmlspecialchars($cdata, ENT_QUOTES, 'UTF-8');
            }
        }

        return '';
    }
}
