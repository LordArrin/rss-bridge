<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\FeedExpander;

final class OglafBridge extends FeedExpander
{
    public const NAME = 'Oglaf';
    public const URI = 'https://www.oglaf.com/';
    public const DESCRIPTION = 'Fetch the entire comic image';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        '' => [
            'limit' => [
                'name' => 'limit (max 20)',
                'type' => 'number',
                'defaultValue' => 10,
                'required' => true,
            ]
        ]
    ];

    public function collectData(): void
    {
        $url = self::URI . 'feeds/rss/';
        $limitInput = $this->getInput('limit');
        $limit = (int) ($limitInput !== null ? $limitInput : 10);
        $limit = min(20, $limit);

        $this->collectExpandableDatas($url, $limit);
    }

    protected function parseItem(array $item): array|false
    {
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

        $comicImage = $dom->querySelector('img#strip');
        if ($comicImage instanceof \Dom\Element === false) {
            return $item;
        }

        $alt = (string) $comicImage->getAttribute('alt');
        $title = (string) $comicImage->getAttribute('title');

        $altEscaped = htmlspecialchars($alt, ENT_QUOTES, 'UTF-8');
        $titleEscaped = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

        $item['content'] = $comicImage->outerHTML . sprintf(
            '<h3>Alt: %s</h3><h3>Title: %s</h3>',
            $altEscaped,
            $titleEscaped
        );

        return $item;
    }
}
