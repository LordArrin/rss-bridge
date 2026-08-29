<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class SkyArteBridge extends BridgeAbstract
{
    public const NAME = 'Sky Arte | Mostre ed eventi';
    public const URI = 'https://arte.sky.it';
    public const DESCRIPTION = 'News from the website of the Italian television channel Sky Arte, dedicated to culture and art';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 21600;

    public const MAX_ARTICLES = 5;

    private const CSS = [
        'image' => 'max-width: 800px; width: auto; height: auto;',
    ];

    public function collectData(): void
    {
        $urls = get_sitemap('https://arte.sky.it/sitemap-mostre-eventi.xml');

        if (is_array($urls) === false) {
            \throwServerException('Invalid sitemap format');
        }

        $count = 0;
        foreach ($urls as $url) {
            if (is_array($url) === false) {
                continue;
            }

            $loc = (string) ($url['loc'] ?? '');

            if ($loc === '') {
                continue;
            }

            $json = $this->getJson($loc);
            if (is_array($json) === false) {
                continue;
            }

            $event = $this->parseEventData($json);

            $lastmod = (string) ($url['lastmod'] ?? '');
            $timestamp = ($lastmod !== '') ? strtotime($lastmod) : time();
            if ($timestamp === false) {
                $timestamp = time();
            }

            $this->items[] = [
                'title' => $event['title'],
                'uri' => $loc,
                'uid' => $loc,
                'timestamp' => $timestamp,
                'content' => $event['content'],
                'categories' => $event['categories'],
            ];

            if (++$count >= self::MAX_ARTICLES) {
                break;
            }
        }
    }

    private function getJson(string $url): ?array
    {
        $html = getContents($url);
        if (is_string($html) === false || $html === '') {
            return null;
        }

        $dom = \Dom\HTMLDocument::createFromString($html);
        $script = $dom->querySelector('script#__NEXT_DATA__');

        if ($script === null) {
            return null;
        }

        $decoded = json_decode((string) $script->textContent, true);

        return is_array($decoded) === true ? $decoded : null;
    }

    private function parseEventData(array $json): array
    {
        $props = $json['props']['pageProps']['data'] ?? [];
        $card = $props['card'] ?? [];
        $info = $props['info'] ?? [];

        $event = [
            'title' => (string) ($card['title']['typography']['text'] ?? '(untitled)'),
            'content' => '',
            'categories' => [],
            'enclosures' => [],
        ];

        $artist = (string) ($info['artist']['text'] ?? '');

        $curators = [];
        if (isset($info['curators']) === true && is_array($info['curators']) === true) {
            foreach ($info['curators'] as $c) {
                if (is_array($c) === true) {
                    $curators[] = (string) ($c['text'] ?? '');
                }
            }
        }

        $location = '';
        $dates = '';

        if (isset($card['informations']) === true && is_array($card['informations']) === true) {
            foreach ($card['informations'] as $block) {
                if (is_array($block) === false) {
                    continue;
                }

                $icon = (string) ($block['iconRight']['Icon'] ?? '');
                if ($icon === 'SvgLocation') {
                    $location = (string) ($block['textRight']['text'] ?? '');
                }

                if ($icon === 'SvgEventEmpty') {
                    $dates = (string) ($block['textRight']['text'] ?? '');
                }

                if (isset($block['badge']['label']['text']) === true && $block['badge']['label']['text'] !== '') {
                    $event['categories'][] = (string) $block['badge']['label']['text'];
                }
            }
        }

        $imageUrl = '';
        if (isset($card['image']['src']) === true && $card['image']['src'] !== '') {
            $imageUrl = (string) $card['image']['src'];
            $event['enclosures'][] = $imageUrl;
        }

        $content = '';

        if ($imageUrl !== '') {
            $content .= '<p><img src="' . htmlspecialchars($imageUrl) . '" style="' . self::CSS['image'] . '" alt="" /></p>';
        }

        if ($artist !== '') {
            $content .= '<p><strong>Artista:</strong> ' . htmlspecialchars($artist) . '</p>';
        }

        if (count($curators) > 0) {
            $content .= '<p><strong>Curatori:</strong> ' . htmlspecialchars(implode(', ', $curators)) . '</p>';
        }

        if ($location !== '') {
            $content .= '<p><strong>Luogo:</strong> ' . htmlspecialchars($location) . '</p>';
        }

        if ($dates !== '') {
            $content .= '<p><strong>Periodo:</strong> ' . htmlspecialchars($dates) . '</p>';
        }

        $description = (string) ($props['description'] ?? '');
        if ($description !== '') {
            $result = preg_replace('~<h2>(.*?)</h2>~i', '<strong>$1</strong>', $description);
            $description = (is_string($result) === true) ? $result : $description;
            $description = nl2br($description);
            $content .= '<br><hr><br><p>' . $description . '</p>';
        }

        $event['content'] = $content;

        return $event;
    }
}
