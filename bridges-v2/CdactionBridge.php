<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\FeedExpander;

use function urljoin;

final class CdactionBridge extends FeedExpander
{
    public const NAME = 'CD-ACTION.PL';
    public const URI = 'https://cdaction.pl/';
    public const DESCRIPTION = 'Fetches the latest posts from given category.';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        '' => [
            'category' => [
                'name' => 'Kategoria',
                'type' => 'list',
                'defaultValue' => 'newsy',
                'values' => [
                    'Wszystko' => 'all',
                    'Newsy' => 'newsy',
                    'Recenzje' => 'recenzje',
                    'Teksty' => [
                        'Publicystyka' => 'teksty',
                        'Zapowiedzi' => 'zapowiedzi',
                        'Już graliśmy' => 'juz-gralismy',
                        'Retro' => 'retro',
                    ],
                    'Kultura' => 'kultura',
                    'Technologie' => [
                        'Artykuły' => 'artykuly',
                        'Technologie' => 'technologie',
                        'Testy' => 'testy',
                    ],
                    'Na luzie' => [
                        'Nadgodziny' => 'nadgodziny',
                        'Szpile' => 'szpile',
                    ],
                ],
            ],
            'limit' => [
                'name' => 'Limit',
                'type' => 'number',
                'defaultValue' => 10,
                'title' => 'Maximum number of items to return',
            ],
        ],
    ];

    public function collectData(): void
    {
        $category = (string) $this->getInput('category');
        if ($category === '') {
            throwClientException('Invalid category');
        }

        $limit = (int) ($this->getInput('limit') ?? 10);
        $limit = min(50, max(1, $limit));

        $feedUrl = $category === 'all' ? self::URI . 'feed/' : self::URI . $category . '/feed/';

        $this->collectExpandableDatas($feedUrl, $limit);

        if ($this->items === []) {
            throwServerException('No articles found in the feed.');
        }
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    protected function parseItem(array $item): array
    {
        $uri = (string) ($item['uri'] ?? '');
        $content = (string) ($item['content'] ?? '');

        $item['title'] = strip_invisible_unicode(trim((string) ($item['title'] ?? '')));
        if ($uri !== '') {
            $item['uid'] = $uri;
        }

        $bodyImages = [];
        if ($content !== '') {
            $body = parseHtmlDocument($content)->querySelector('body');
            if ($body !== null) {
                foreach ($body->querySelectorAll('script, style, aside') as $junk) {
                    $junk->remove();
                }

                if ($uri !== '') {
                    foreach ($body->querySelectorAll('img[src]') as $img) {
                        $src = $img->getAttribute('src') ?? '';
                        if ($src !== '' && str_starts_with($src, 'http') === false && str_starts_with($src, 'data:') === false) {
                            $img->setAttribute('src', urljoin($uri, $src));
                        }
                    }

                    foreach ($body->querySelectorAll('a[href]') as $anchor) {
                        $href = $anchor->getAttribute('href') ?? '';
                        if ($href !== '' && str_starts_with($href, 'http') === false && str_starts_with($href, '#') === false && str_starts_with($href, 'mailto:') === false) {
                            $anchor->setAttribute('href', urljoin($uri, $href));
                        }
                    }
                }

                foreach ($body->querySelectorAll('img') as $img) {
                    $key = $this->imageKey($img->getAttribute('src') ?? '');
                    if ($key !== '') {
                        $bodyImages[] = $key;
                    }

                    foreach (parseSrcset($img->getAttribute('srcset') ?? '') as $variant) {
                        $key = $this->imageKey($variant);
                        if ($key !== '') {
                            $bodyImages[] = $key;
                        }
                    }
                }

                $content = break_annoying_html_tags($body->innerHTML, true);
            }
        }

        $enclosures = $item['enclosures'] ?? [];
        if (is_array($enclosures) === false) {
            $enclosures = [];
        }

        $item['content'] = $content;
        $item['enclosures'] = $this->filterEnclosures($enclosures, $bodyImages);
        unset($item['enclosure']);

        return $item;
    }

    /**
     * @param array<array-key, mixed> $enclosures
     * @param list<string> $bodyImages
     * @return list<string>
     */
    private function filterEnclosures(array $enclosures, array $bodyImages): array
    {
        $result = [];
        foreach ($enclosures as $enclosure) {
            if (is_string($enclosure) === false || $enclosure === '') {
                continue;
            }

            $key = $this->imageKey($enclosure);
            if ($key !== '' && in_array($key, $bodyImages, true) === true) {
                continue;
            }

            $result[] = $enclosure;
        }

        return $result;
    }

    private function imageKey(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path) === false || $path === '') {
            return '';
        }

        $normalized = preg_replace('/-\d+x\d+(?=\.[A-Za-z0-9]+$)/', '', $path);

        return strtolower(is_string($normalized) === true ? $normalized : $path);
    }
}
