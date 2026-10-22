<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\FeedExpander;

final class NasaApodBridge extends FeedExpander
{
    public const NAME = 'NASA Picture of the Day';
    public const URI = 'https://science.nasa.gov/apod/';
    public const DESCRIPTION = 'Returns the 3 latest NASA APOD pictures and explanations via official RSS feed';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 43200;

    public function collectData(): void
    {
        $this->collectExpandableDatas('https://science.nasa.gov/feed/apod-basic/', 3);
    }

    protected function parseItem(array $item): array|false
    {
        $apod = $item['apod'] ?? null;

        if (is_array($apod) === false) {
            return false;
        }

        $media = '';
        $hdurl = (string) ($apod['hdurl'] ?? '');

        if ($hdurl === '') {
        } else {
            $imageUri = htmlspecialchars($hdurl, ENT_QUOTES, 'UTF-8');
            $imageAlt = htmlspecialchars((string) ($apod['alt'] ?? ''), ENT_QUOTES, 'UTF-8');

            $media = sprintf(
                '<a href="%s"><img src="%s" alt="%s" /></a><br />',
                $imageUri,
                $imageUri,
                $imageAlt
            );
        }

        $explanation = (string) ($apod['explanation'] ?? '');
        $cleaned = preg_replace(
            '/\A(<strong>Explanation:\s*<\/strong>\s*)<b>Explanation<\/b>\s*:\s*/iu',
            '$1',
            $explanation
        );

        $item['content'] = $media . ($cleaned ?? $explanation);

        return $item;
    }
}
