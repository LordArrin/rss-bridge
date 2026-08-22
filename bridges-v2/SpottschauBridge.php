<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

use function urljoin;

final class SpottschauBridge extends BridgeAbstract
{
    public const NAME = 'Harringers Spottschau';
    public const URI = 'https://spottschau.com/';
    public const DESCRIPTION = 'Returns the latest strip from the "Harringers Spottschau" comic';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 86400;

    public function collectData(): void
    {
        $htmlString = getContents(self::URI);

        if ($htmlString === '') {
            throwServerException('Failed to fetch content from spottschau.com');
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($htmlString);
        libxml_use_internal_errors(false);

        $src = $dom->querySelector('div.strip > a')?->querySelector('img')?->getAttribute('src');

        if ($src === null || $src === '') {
            throwServerException('Could not find strip image on the page.');
        }

        $absoluteSrc = urljoin(self::URI, $src);
        $safeSrc = htmlspecialchars($absoluteSrc, ENT_QUOTES, 'UTF-8');

        $this->items[] = [
            'uri' => self::URI,
            'title' => 'Strip der Woche',
            'content' => '<img src="' . $safeSrc . '">',
            'author' => 'Christoph Harringer',
            'uid' => md5($absoluteSrc),
        ];
    }
}
