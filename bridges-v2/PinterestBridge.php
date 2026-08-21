<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use FeedExpander;

final class PinterestBridge extends FeedExpander
{
    public const NAME = 'Pinterest';
    public const URI = 'https://www.pinterest.com';
    public const DESCRIPTION = 'Returns the newest images on a board';
    public const MAINTAINER = 'no maintainer';

    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        'By username and board' => [
            'u' => [
                'name' => 'username',
                'exampleValue' => 'VIGOIndustries',
                'required' => true
            ],
            'b' => [
                'name' => 'board',
                'exampleValue' => 'bathroom-remodels',
                'required' => true
            ]
        ]
    ];

    public function getIcon(): string
    {
        return 'https://s.pinimg.com/webapp/style/images/favicon-9f8f9adf.png';
    }

    public function collectData(): void
    {
        $this->collectExpandableDatas($this->getURI() . '.rss');
        $this->fixLowRes();
    }

    private function fixLowRes(): void
    {
        $newitems = [];
        $pattern = '/https\:\/\/i\.pinimg\.com\/[a-zA-Z0-9]*x\//';

        foreach ($this->items as $item) {
            $content = $item['content'] ?? '';
            $replaced = preg_replace($pattern, 'https://i.pinimg.com/originals/', $content);
            if ($replaced !== null) {
                $item['content'] = $replaced;
            }

            $item['enclosures'] = [
                $item['uri'] ?? '',
            ];
            $newitems[] = $item;
        }

        $this->items = $newitems;
    }

    public function getURI(): string
    {
        if ($this->queriedContext === 'By username and board') {
            return self::URI . '/' . urlencode((string)$this->getInput('u')) . '/' . urlencode((string)$this->getInput('b'));
        }

        return parent::getURI();
    }

    public function getName(): string
    {
        if ($this->queriedContext === 'By username and board') {
            return $this->getInput('u') . ' - ' . $this->getInput('b') . ' - ' . self::NAME;
        }

        return parent::getName();
    }
}
