<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\FeedExpander;

final class TheDriveBridge extends FeedExpander
{
    public const NAME = 'The Drive';
    public const URI = 'https://www.thedrive.com/';
    public const DESCRIPTION = 'Car news from thedrive.com';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public function collectData(): void
    {
        $this->collectExpandableDatas('https://www.thedrive.com/feed', 20);
    }

    protected function parseItem(array $item): array|false
    {
        $item = parent::parseItem($item);

        if (is_array($item) === false) {
            return false;
        }

        $uri = $item['uri'] ?? '';
        if (str_contains($uri, 'the-war-zone') === true) {
            return false;
        }

        $content = $item['content'] ?? '';
        $enclosures = $item['enclosures'] ?? [];

        foreach ($enclosures as $attachment) {
            if (is_string($attachment) === true && $attachment !== '') {
                $content = '<img src="' . htmlspecialchars($attachment, ENT_QUOTES, 'UTF-8') . '">' . $content;
            }
        }

        if ($content !== '') {
            $content = $this->processContent($content);
        }

        $item['content'] = $content;
        $item['enclosures'] = [];

        return $item;
    }

    private function processContent(string $html): string
    {
        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString(
            '<!DOCTYPE html><html><body>' . $html . '</body></html>'
        );
        libxml_use_internal_errors(false);

        $this->removeInstagramEmbeds($dom);
        $this->replaceYoutubeEmbeds($dom);
        $this->fixGettyImages($dom);

        $body = $dom->querySelector('body');
        if ($body === null) {
            return $html;
        }

        return $body->innerHTML;
    }

    private function removeInstagramEmbeds(\Dom\HTMLDocument $dom): void
    {
        $instagramBlocks = $dom->querySelectorAll('.wp-block-embed-instagram');
        foreach ($instagramBlocks as $block) {
            $block->remove();
        }
    }

    private function replaceYoutubeEmbeds(\Dom\HTMLDocument $dom): void
    {
        $youtubeDivs = $dom->querySelectorAll('div.lazied-youtube-frame');

        foreach ($youtubeDivs as $youtubeDiv) {
            $videoId = $youtubeDiv->getAttribute('data-video-id');

            if ($videoId === null || $videoId === '') {
                continue;
            }

            $iframe = $dom->createElement('iframe');
            $iframe->setAttribute('width', '560');
            $iframe->setAttribute('height', '315');
            $iframe->setAttribute('src', 'https://www.youtube.com/embed/' . $videoId);
            $iframe->setAttribute('frameborder', '0');
            $iframe->setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture');
            $iframe->setAttribute('allowfullscreen', '');

            $youtubeDiv->replaceWith($iframe);
        }
    }

    private function fixGettyImages(\Dom\HTMLDocument $dom): void
    {
        $images = $dom->querySelectorAll('img');

        foreach ($images as $img) {
            $src = $img->getAttribute('src');
            $dataSrc = $img->getAttribute('data-src');
            $dataLazySrc = $img->getAttribute('data-lazy-src');

            if (($src === null || $src === '') && $dataSrc !== null && $dataSrc !== '') {
                $img->setAttribute('src', $dataSrc);
            }

            if (($src === null || $src === '') && $dataLazySrc !== null && $dataLazySrc !== '') {
                $img->setAttribute('src', $dataLazySrc);
            }

            $finalSrc = $img->getAttribute('src');
            if ($finalSrc !== null && $finalSrc !== '') {
                $img->setAttribute('src', $finalSrc);
                $img->removeAttribute('data-src');
                $img->removeAttribute('data-lazy-src');
            }
        }
    }
}
