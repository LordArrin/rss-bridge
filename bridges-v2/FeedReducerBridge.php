<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use FeedExpander;

final class FeedReducerBridge extends FeedExpander
{
    public const NAME = 'Feed Reducer';
    public const URI = 'http://github.com/RSS-Bridge/rss-bridge/';
    public const DESCRIPTION = 'Choose a percentage of a feed you want to see';
    public const MAINTAINER = 'no maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [[
        'url' => [
            'name' => 'Feed URI',
            'exampleValue' => 'https://lorem-rss.herokuapp.com/feed?length=42',
            'required' => true
        ],
        'percentage' => [
            'name' => 'percentage',
            'type' => 'number',
            'exampleValue' => 50,
            'required' => true
        ]
    ]];

    public function collectData(): void
    {
        $url = (string)$this->getInput('url');
        if (preg_match('#^http(s?)://#i', $url) === 1) {
            $this->collectExpandableDatas($url);
        } else {
            throwClientException('URI must begin with http(s)://');
        }
    }

    public function getItems(): array
    {
        $filteredItems = [];
        $percentageInput = $this->getInput('percentage');
        $percentageString = $percentageInput !== null ? preg_replace('/[^0-9]/', '', (string)$percentageInput) : '';
        $intPercentage = (int)$percentageString;

        $feedUrl = (string)$this->getInput('url');

        foreach ($this->items as $item) {
            $data = ($item['uri'] ?? '') . '::' . $feedUrl;
            $hash = hash('sha256', $data, true);
            $unpacked = unpack('S', $hash);
            if ($unpacked === false || isset($unpacked[1]) === false) {
                continue;
            }
            $pseudoRandomInteger = $unpacked[1] % 100;

            if ($pseudoRandomInteger < $intPercentage) {
                $filteredItems[] = $item;
            }
        }

        return $filteredItems;
    }

    public function getName(): string
    {
        $percentageInput = $this->getInput('percentage');
        $trimmedPercentage = $percentageInput !== null ? preg_replace('/[^0-9]/', '', (string)$percentageInput) : '';
        return parent::getName() . ' [' . $trimmedPercentage . '%]';
    }
}
