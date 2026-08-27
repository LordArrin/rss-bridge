<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class FlickrBridge extends BridgeAbstract
{
    public const NAME = 'Flickr';
    public const URI = 'https://www.flickr.com/';
    public const DESCRIPTION = 'Returns images from Flickr';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 7200;

    public const PARAMETERS = [
        'Explore' => [],
        'By keyword' => [
            'q' => [
                'name' => 'Keyword',
                'type' => 'text',
                'required' => true,
                'title' => 'Insert keyword',
                'exampleValue' => 'bird',
            ],
            'media' => [
                'name' => 'Media',
                'type' => 'list',
                'values' => [
                    'All (Photos & videos)' => 'all',
                    'Photos' => 'photos',
                    'Videos' => 'videos',
                ],
                'defaultValue' => 'all',
            ],
            'sort' => [
                'name' => 'Sort By',
                'type' => 'list',
                'values' => [
                    'Relevance' => 'relevance',
                    'Date uploaded' => 'date-posted-desc',
                    'Date taken' => 'date-taken-desc',
                    'Interesting' => 'interestingness-desc',
                ],
                'defaultValue' => 'relevance',
            ],
        ],
        'By username' => [
            'u' => [
                'name' => 'Username',
                'type' => 'text',
                'required' => true,
                'title' => 'Insert username (as shown in the address bar)',
                'exampleValue' => 'flickr',
            ],
            'content' => [
                'name' => 'Content',
                'type' => 'list',
                'values' => [
                    'Uploads' => 'uploads',
                    'Favorites' => 'faves',
                ],
                'defaultValue' => 'uploads',
            ],
            'media' => [
                'name' => 'Media',
                'type' => 'list',
                'values' => [
                    'All (Photos & videos)' => 'all',
                    'Photos' => 'photos',
                    'Videos' => 'videos',
                ],
                'defaultValue' => 'all',
            ],
            'sort' => [
                'name' => 'Sort By',
                'type' => 'list',
                'values' => [
                    'Relevance' => 'relevance',
                    'Date uploaded' => 'date-posted-desc',
                    'Date taken' => 'date-taken-desc',
                    'Interesting' => 'interestingness-desc',
                ],
                'defaultValue' => 'date-posted-desc',
            ],
        ],
    ];

    private string $username = '';

    public function collectData()
    {
        switch ($this->queriedContext) {
            case 'Explore':
                $filter = 'photo-lite-models';
                $html = getContents($this->getURI());
                break;

            case 'By keyword':
                $filter = 'photo-lite-models';
                $html = getContents($this->getURI());
                break;

            case 'By username':
                $filter = 'photo-lite-models';
                $html = getContents($this->getURI());

                $this->username = (string)$this->getInput('u');

                libxml_use_internal_errors(true);
                $dom = \Dom\HTMLDocument::createFromString($html);
                libxml_use_internal_errors(false);

                $searchPill = $dom->querySelector('span.search-pill-name');
                if ($searchPill !== null) {
                    $this->username = $searchPill->textContent ?? $this->username;
                }
                break;

            default:
                throwClientException('Invalid context: ' . $this->queriedContext);
        }

        $modelJson = $this->extractJsonModel($html);
        $photoModels = $this->getPhotoModels($modelJson, $filter);

        foreach ($photoModels as $model) {
            $item = [];

            if (isset($model['username']) === true) {
                $item['author'] = urldecode((string)$model['username']);
            } else {
                $firstModel = reset($modelJson);
                if (is_array($firstModel) === true && isset($firstModel[0]['owner']['username']) === true) {
                    $item['author'] = urldecode((string)$firstModel[0]['owner']['username']);
                }
            }

            if (isset($model['title']) === true) {
                $title = urldecode((string)$model['title']);
            } else {
                $title = 'Untitled';
            }
            $item['title'] = $title;

            $photoId = (string)($model['id'] ?? '');
            $item['uri'] = self::URI . 'photo.gne?id=' . rawurlencode($photoId);

            if (isset($model['description']) === true) {
                $description = urldecode((string)$model['description']);
            } else {
                $description = '';
            }

            $imageSrc = $this->extractContentImage($model);
            $item['content'] = $this->buildItemContent($item['uri'], $imageSrc, $description);

            $this->items[] = $item;
        }
    }

    private function buildItemContent(string $uri, string $imageSrc, string $description): string
    {
        $html = '<a href="' . e($uri) . '">';
        $html .= '<img src="' . e($imageSrc) . '" style="max-width: 640px; max-height: 480px;"/>';
        $html .= '</a><br><p>';
        $html .= $description; // description уже содержит HTML с ссылками, НЕ экранируем
        $html .= '</p>';
        return $html;
    }

    public function getURI()
    {
        switch ($this->queriedContext) {
            case 'Explore':
                return self::URI . 'explore';
            case 'By keyword':
                $q = rawurlencode((string)$this->getInput('q'));
                $sort = rawurlencode((string)$this->getInput('sort'));
                $media = rawurlencode((string)$this->getInput('media'));
                return self::URI . 'search/?q=' . $q . '&sort=' . $sort . '&media=' . $media;
            case 'By username':
                $u = rawurlencode((string)$this->getInput('u'));
                $media = rawurlencode((string)$this->getInput('media'));
                $uri = self::URI . 'search/?user_id=' . $u . '&sort=date-posted-desc&media=' . $media;

                if ($this->getInput('content') === 'faves') {
                    return $uri . '&faves=1';
                }

                return $uri;
            default:
                return parent::getURI();
        }
    }

    public function getName()
    {
        switch ($this->queriedContext) {
            case 'Explore':
                return 'Explore - ' . self::NAME;
            case 'By keyword':
                return (string)$this->getInput('q') . ' - keyword - ' . self::NAME;
            case 'By username':
                if ($this->getInput('content') === 'faves') {
                    return $this->username . ' - favorites - ' . self::NAME;
                }
                return $this->username . ' - ' . self::NAME;
            default:
                return parent::getName();
        }
    }

    private function extractJsonModel(string $html): array
    {
        $startMarker = 'modelExport:';
        $endMarker = 'auth:';

        $startPos = strpos($html, $startMarker);
        if ($startPos === false) {
            return [];
        }

        $start = $startPos + strlen($startMarker);
        $end = strpos($html, $endMarker, $start);

        if ($end === false) {
            return [];
        }

        $modelText = trim(substr($html, $start, $end - $start));
        if (str_ends_with($modelText, ',') === true) {
            $modelText = substr($modelText, 0, -1);
        }

        $decoded = json_decode($modelText, true);
        if (is_array($decoded) === false) {
            return [];
        }

        return $decoded;
    }

    private function getPhotoModels(array $json, string $filter): array
    {
        $photoModels = [];

        if (isset($json['legend']) === false || isset($json['main']) === false) {
            return $photoModels;
        }

        foreach ($json['legend'] as $legend) {
            if (is_array($legend) === false) {
                continue;
            }

            $photoModel = $json['main'];

            foreach ($legend as $element) {
                if (is_array($photoModel) === false || isset($photoModel[$element]) === false) {
                    continue 2;
                }
                $photoModel = $photoModel[$element];
            }

            if (is_array($photoModel) === true && isset($photoModel['_flickrModelRegistry']) === true) {
                if ($photoModel['_flickrModelRegistry'] === $filter) {
                    $photoModels[] = $photoModel;
                }
            }
        }

        return $photoModels;
    }

    private function extractEnclosures(array $model): array
    {
        $areas = [];

        if (isset($model['sizes']['data']) === false || is_array($model['sizes']['data']) === false) {
            return [];
        }

        foreach ($model['sizes']['data'] as $size) {
            if (is_array($size) === false || isset($size['data']) === false) {
                continue;
            }
            $sizeData = $size['data'];
            if (isset($sizeData['width'], $sizeData['height'], $sizeData['url']) === true) {
                $area = (int)$sizeData['width'] * (int)$sizeData['height'];
                $areas[$area] = (string)$sizeData['url'];
            }
        }

        if (count($areas) === 0) {
            return [];
        }

        $maxKey = max(array_keys($areas));
        return [$this->fixUrl($areas[$maxKey])];
    }

    private function extractContentImage(array $model): string
    {
        $areas = [];
        $limit = 320 * 240;

        if (isset($model['sizes']['data']) === false || is_array($model['sizes']['data']) === false) {
            return '';
        }

        $sizes = $model['sizes']['data'];

        foreach ($sizes as $sizeData) {
            if (is_array($sizeData) === false || isset($sizeData['data']) === false) {
                continue;
            }
            $data = $sizeData['data'];
            if (isset($data['width'], $data['height'], $data['url']) === true) {
                $area = (int)$data['width'] * (int)$data['height'];
                if ($area >= $limit) {
                    $areas[$area] = (string)$data['url'];
                }
            }
        }

        if (count($areas) > 0) {
            $minKey = min(array_keys($areas));
            $url = $areas[$minKey];
        } else {
            $firstKey = array_key_first($sizes);
            if ($firstKey === null || isset($sizes[$firstKey]['data']['url']) === false) {
                return '';
            }
            $url = (string)$sizes[$firstKey]['data']['url'];
        }

        return $this->fixUrl($url);
    }

    private function fixUrl(string $url): string
    {
        if (str_starts_with($url, '//') === true) {
            $url = 'https:' . $url;
        }
        return $url;
    }
}
