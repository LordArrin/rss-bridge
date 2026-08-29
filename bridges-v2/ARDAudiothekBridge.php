<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class ARDAudiothekBridge extends BridgeAbstract
{
    public const NAME = 'ARD-Audiothek';
    public const URI = 'https://www.ardaudiothek.de';
    public const DESCRIPTION = 'Feed of any show in the ARD-Audiothek, specified by its path';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    private const API_ENDPOINT = 'https://api.ardaudiothek.de/';
    private const IMAGE_WIDTH = 600;
    private const IMAGE_WIDTH_PLACEHOLDER = '{width}';
    private const IMAGE_EXTENSION = '.jpg';
    private const DEFAULT_LIMIT = 10;

    public const PARAMETERS = [
        [
            'path' => [
                'name' => 'Show Link or ID',
                'required' => true,
                'title' => 'Link to the show page or just its numeric suffix',
                'exampleValue' => 'https://www.ardaudiothek.de/sendung/kalk-welk/10777871/',
            ],
            'limit' => [
                'name' => 'Limit',
                'type' => 'number',
                'required' => false,
                'exampleValue' => 10,
                'defaultValue' => 10,
            ],
        ],
    ];

    private ?string $feedTitle = null;
    private ?string $feedUri = null;
    private ?string $feedIcon = null;

    public function collectData()
    {
        $path = $this->getInput('path');
        $limit = $this->getInput('limit');

        $pathComponents = explode('/', (string)$path);

        if (count($pathComponents) < 2) {
            $showID = $pathComponents[0];
        } else {
            $lastKey = count($pathComponents) - 1;
            $showID = $pathComponents[$lastKey];
            if (strlen($showID) === 0) {
                $showID = $pathComponents[$lastKey - 1];
            }
        }

        $url = self::API_ENDPOINT . 'programsets/' . $showID . '/';
        $json1 = getContents($url);
        $data1 = json_decode($json1, false);

        if (is_object($data1) === false || isset($data1->data->programSet) === false) {
            throw new \Exception('Unable to find show id: ' . $showID);
        }

        $processedJSON = $data1->data->programSet;

        $limitValue = self::DEFAULT_LIMIT;
        if (is_numeric($limit) === true) {
            $limitValue = (int) $limit;
        }

        $answerLength = 1;
        $offset = 0;
        $numberOfElements = 1;

        while ($answerLength !== 0 && $offset < $numberOfElements && $offset < $limitValue) {
            $json2 = getContents($url . '?offset=' . $offset);
            $data2 = json_decode($json2, false);

            if (is_object($data2) === false || isset($data2->data->programSet) === false) {
                break;
            }

            $processedJSON = $data2->data->programSet;

            if (isset($processedJSON->items->nodes) === false || is_array($processedJSON->items->nodes) === false) {
                break;
            }

            $answerLength = count($processedJSON->items->nodes);
            $offset = $offset + $answerLength;
            $numberOfElements = $processedJSON->numberOfElements ?? PHP_INT_MAX;

            foreach ($processedJSON->items->nodes as $audio) {
                if (is_object($audio) === false) {
                    continue;
                }

                $item = [];
                $item['uri'] = (string)($audio->sharingUrl ?? '');
                $item['title'] = (string)($audio->title ?? '');

                $image = '';
                if (isset($audio->image) === true && is_object($audio->image) === true) {
                    if (isset($audio->image->url) === true) {
                        $image = str_replace(
                            self::IMAGE_WIDTH_PLACEHOLDER,
                            (string)self::IMAGE_WIDTH,
                            (string)$audio->image->url
                        );
                    }
                }

                $enclosures = [];
                if (isset($audio->audios[0]->url) === true) {
                    $enclosures[] = (string)$audio->audios[0]->url;
                }
                $item['enclosures'] = $enclosures;

                $synopsis = (string)($audio->synopsis ?? '');
                $item['content'] = '<img src="' . e($image) . '" /><p>' . e($synopsis) . '</p>';

                $item['timestamp'] = $audio->publicationStartDateAndTime ?? null;
                $item['uid'] = (string)($audio->id ?? '');

                if (isset($audio->programSet->publicationService->title) === true) {
                    $item['author'] = (string)$audio->programSet->publicationService->title;
                }

                $category = $audio->programSet->editorialCategories->title ?? null;
                if ($category !== null && $category !== '') {
                    $item['categories'] = [(string)$category];
                }

                if (isset($audio->duration) === true) {
                    $item['itunes'] = [
                        'duration' => (string)$audio->duration,
                    ];
                }

                $this->items[] = $item;
            }
        }

        $this->feedTitle = (string)($processedJSON->title ?? '');
        $this->feedUri = (string)($processedJSON->sharingUrl ?? '');

        if (isset($processedJSON->image->url1X1) === true) {
            $this->feedIcon = str_replace(
                self::IMAGE_WIDTH_PLACEHOLDER,
                (string)self::IMAGE_WIDTH,
                (string)$processedJSON->image->url1X1
            );
            $this->feedIcon = $this->feedIcon . self::IMAGE_EXTENSION;
        }

        $this->items = array_slice($this->items, 0, $limitValue);
    }

    public function getURI()
    {
        if ($this->feedUri !== null && $this->feedUri !== '') {
            return $this->feedUri;
        }
        return parent::getURI();
    }

    public function getName()
    {
        if ($this->feedTitle !== null && $this->feedTitle !== '') {
            return $this->feedTitle;
        }
        return parent::getName();
    }

    public function getIcon()
    {
        if ($this->feedIcon !== null && $this->feedIcon !== '') {
            return $this->feedIcon;
        }
        return parent::getIcon();
    }
}
