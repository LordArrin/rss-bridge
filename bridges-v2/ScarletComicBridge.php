<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use FeedExpander;

final class ScarletComicBridge extends FeedExpander
{
    public const NAME = 'Scarlet Comics';
    public const URI = 'https://www.sandraandwoo.com';
    public const DESCRIPTION = 'Fetch the entire comic page';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        '' => [
            'limit' => [
                'name' => 'limit (max 5)',
                'type' => 'number',
                'defaultValue' => 5,
                'required' => true,
            ]
        ]
    ];

    public function collectData(): void
    {
        $url = self::URI . '/scarlet/feed';
        $limitInput = $this->getInput('limit');
        $limit = (int) ($limitInput !== null ? $limitInput : 5);
        $limit = min(5, $limit);

        $this->collectExpandableDatas($url, $limit);
    }

    protected function parseItem($item)
    {
        if (is_array($item) === false) {
            return $item;
        }

        $uri = $item['uri'] ?? '';
        if ($uri === '') {
            return $item;
        }

        $html = getContents($uri);
        if ($html === '') {
            return $item;
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $comicImage = $dom->querySelector('div#spliced-comic');

        if ($comicImage instanceof \Dom\Element === false) {
            return $item;
        }

        $item['content'] = $comicImage->outerHTML;

        if (isset($item['enclosures']) === true) {
            $item['enclosures'] = [];
        }

        return $item;
    }
}
