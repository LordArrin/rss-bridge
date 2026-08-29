<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class ComicsKingdomBridge extends BridgeAbstract
{
    public const NAME = 'Comics Kingdom Unofficial RSS';
    public const URI = 'https://wp.comicskingdom.com/wp-json/wp/v2/ck_comic';
    public const DESCRIPTION = 'Comics Kingdom Unofficial RSS';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 21600;

    public const PARAMETERS = [
        [
            'comicname' => [
                'name' => 'Name of comic',
                'type' => 'text',
                'exampleValue' => 'mutts',
                'title' => 'The name of the comic in the URL after https://comicskingdom.com/',
                'required' => true
            ],
            'limit' => [
                'name' => 'Limit',
                'type' => 'number',
                'title' => 'The number of recent comics to get',
                'defaultValue' => 10
            ]
        ]
    ];

    protected ?string $comicName = null;

    public function collectData(): void
    {
        $apiUrl = $this->getURI();
        $json = getContents($apiUrl);
        $data = json_decode($json, false);

        if (is_array($data) === false) {
            \throwServerException('Invalid API response format');
        }

        if (isset($data[0]->_embedded->{'wp:term'}[0][0]) === true) {
            $this->comicName = (string) $data[0]->_embedded->{'wp:term'}[0][0]->name;
        }

        foreach ($data as $comicitem) {
            $item = [];

            $item['uid'] = (string) ($comicitem->id ?? '');
            $item['uri'] = (string) ($comicitem->yoast_head_json->og_url ?? $apiUrl);

            $byline = $comicitem->ck_comic_byline ?? '';
            $item['author'] = str_ireplace('By ', '', (string) $byline);

            $item['title'] = (string) ($comicitem->yoast_head_json->title ?? 'Untitled');

            $date = $comicitem->date ?? null;
            if (is_string($date) === true) {
                $item['timestamp'] = strtotime($date);
            } else {
                $item['timestamp'] = time();
            }

            $imageUrl = $comicitem->yoast_head_json->og_image[0]->url ?? null;
            if (is_string($imageUrl) === true) {
                $item['content'] = '<img src="' . htmlspecialchars($imageUrl) . '" />';
            } else {
                $item['content'] = '';
            }

            $this->items[] = $item;
        }
    }

    public function getURI(): string
    {
        $comicName = $this->getInput('comicname');
        if (is_string($comicName) === true && $comicName !== '') {
            $limit = (int) ($this->getInput('limit') ?? 10);
            $params = [
                'ck_feature'        => $comicName,
                'per_page'          => $limit,
                'date_inclusive'    => 'true',
                'order'             => 'desc',
                'page'              => '1',
                '_embed'            => 'true'
            ];

            return self::URI . '?' . http_build_query($params);
        }

        return parent::getURI();
    }

    public function getName(): string
    {
        if (is_string($this->comicName) === true && $this->comicName !== '') {
            return $this->comicName . ' - Comics Kingdom';
        }

        return parent::getName();
    }
}
