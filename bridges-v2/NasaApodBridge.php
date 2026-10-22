<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\FeedExpander;

final class NasaApodBridge extends FeedExpander
{
    public const NAME = 'NASA Picture of the Day';
    public const URI = 'https://science.nasa.gov/apod/';
    public const DESCRIPTION = 'Returns the latest NASA Picture of the Day with explanations';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 43200;

    public function collectData(): void
    {
        $this->collectExpandableDatas('https://science.nasa.gov/feed/apod-basic/', 3);
    }

    protected function parseItem(array $item): array|false
    {
        if (!isset($item['apod']) || !is_array($item['apod'])) {
            return false;
        }

        $apod = $item['apod'];

        $media = '';
        if (!empty($apod['hdurl'])) {
            $imageUri = htmlspecialchars((string) $apod['hdurl'], ENT_QUOTES, 'UTF-8');
            $imageAlt = htmlspecialchars((string) ($apod['alt'] ?? ''), ENT_QUOTES, 'UTF-8');
            
            $media = sprintf(
                '<a href="%s"><img src="%s" alt="%s" /></a><br />',
                $imageUri,
                $imageUri,
                $imageAlt
            );
        }

        $explanation = (string) ($apod['explanation'] ?? '');
        $cleanExplanation = preg_replace(
            '/\A(<strong>Explanation:\s*<\/strong>\s*)<b>Explanation<\/b>\s*:\s*/iu',
            '$1',
            $explanation
        );
        
        $item['content'] = $media . ($cleanExplanation ?? $explanation);

        return $item;
    }
}