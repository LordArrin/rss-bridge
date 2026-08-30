<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class GameBananaBridge extends BridgeAbstract
{
    public const NAME = 'GameBanana';
    public const URI = 'https://gamebanana.com/';
    public const DESCRIPTION = 'Returns mods from GameBanana.';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        'Game' => [
            'gid' => [
                'name' => 'Game ID',
                'required' => true,
                'exampleValue' => '7617',
            ],
            'updates' => [
                'name' => 'Get updates',
                'type' => 'checkbox',
                'required' => false,
                'title' => 'Enable game updates in feed',
            ],
        ],
    ];

    private const API_DOMAIN = 'https://api.gamebanana.com';
    private const API_LIST_PATH = '/Core/List/New';
    private const API_DATA_PATH = '/Core/Item/Data';
    private const FILES_BASE_URL = 'https://files.gamebanana.com/mods/';
    private const IMAGES_BASE_URL = 'https://images.gamebanana.com/img/ss/mods/';

    private const IDX_NAME = 0;
    private const IDX_AUTHOR = 1;
    private const IDX_TEXT = 2;
    private const IDX_SCREENSHOTS = 3;
    private const IDX_FILES = 4;
    private const IDX_DATE = 5;
    private const IDX_URL = 6;
    private const IDX_UDATE = 7;
    private const IDX_UPDATES = 8;
    private const IDX_CATEGORY = 9;
    private const IDX_ROOT_CATEGORY = 10;

    private const FIELDS = 'name,Owner().name,text,screenshots,Files().aFiles(),date,Url().sProfileUrl(),udate,Updates().aLatestUpdates(),Category().name,RootCategory().name';

    private const CSS = [
        'image' => 'display: block; max-width: 500px; height: auto; margin: 10px 0;',
    ];

    private ?string $title = null;

    public function collectData()
    {
        $gid = (string)$this->getInput('gid');
        if ($gid === '') {
            throwClientException('Game ID is required');
        }

        $includeUpdates = $this->getInput('updates') === true;

        $modIds = $this->fetchModIds($gid, $includeUpdates);
        if (count($modIds) === 0) {
            return;
        }

        $dataList = $this->fetchModData($gid, $modIds);
        if (count($dataList) === 0) {
            return;
        }

        $this->title = null;
        if (isset($dataList[0][0]) === true) {
            $this->title = (string) $dataList[0][0];
        }
        array_shift($dataList);

        foreach ($dataList as $element) {
            if (is_array($element) === false) {
                continue;
            }

            if (isset($element[self::IDX_NAME]) === false || $element[self::IDX_NAME] === null) {
                continue;
            }

            $item = $this->buildItem($element, $includeUpdates);
            if ($item !== null) {
                $this->items[] = $item;
            }
        }
    }

    public function getName()
    {
        if ($this->title !== null && $this->title !== '') {
            return parent::getName() . ' - ' . $this->title;
        }

        return parent::getName();
    }

    public function getURI()
    {
        $gid = $this->getInput('gid');
        if ($gid !== null && $gid !== '') {
            return self::URI . 'games/' . rawurlencode((string)$gid);
        }

        return parent::getURI();
    }

    private function fetchModIds(string $gid, bool $includeUpdates): array
    {
        $params = [
            'itemtype' => 'Mod',
            'page' => 1,
            'gameid' => $gid,
        ];

        if ($includeUpdates === true) {
            $params['include_updated'] = 1;
        }

        $url = self::API_DOMAIN . self::API_LIST_PATH . '?' . http_build_query($params);
        $response = getContents($url);
        $list = json_decode($response, true);

        if (is_array($list) === false) {
            return [];
        }

        $modIds = [];
        foreach ($list as $element) {
            if (is_array($element) === false || isset($element[1]) === false) {
                continue;
            }
            $modIds[] = (string)$element[1];
        }

        return $modIds;
    }

    private function fetchModData(string $gid, array $modIds): array
    {
        $parts = [];

        $parts[] = 'itemtype[]=Game';
        $parts[] = 'fields[]=name';
        $parts[] = 'itemid[]=' . rawurlencode($gid);

        foreach ($modIds as $modId) {
            $parts[] = 'itemtype[]=Mod';
            $parts[] = 'fields[]=' . rawurlencode(self::FIELDS);
            $parts[] = 'itemid[]=' . rawurlencode($modId);
        }

        $url = self::API_DOMAIN . self::API_DATA_PATH . '?' . implode('&', $parts);
        $response = getContents($url);
        $data = json_decode($response, true);

        if (is_array($data) === false) {
            return [];
        }

        return $data;
    }

    private function buildItem(array $element, bool $includeUpdates): ?array
    {
        $name = (string)($element[self::IDX_NAME] ?? '');
        $url = (string)($element[self::IDX_URL] ?? '');

        if ($name === '' || $url === '') {
            return null;
        }

        $author = (string)($element[self::IDX_AUTHOR] ?? '');
        $text = (string)($element[self::IDX_TEXT] ?? '');
        $screenshotsJson = (string)($element[self::IDX_SCREENSHOTS] ?? '');
        $files = $element[self::IDX_FILES] ?? [];
        $date = $element[self::IDX_DATE] ?? null;
        $udate = $element[self::IDX_UDATE] ?? null;
        $updates = $element[self::IDX_UPDATES] ?? [];
        $category = (string)($element[self::IDX_CATEGORY] ?? '');
        $rootCategory = (string)($element[self::IDX_ROOT_CATEGORY] ?? '');

        $item = [];
        $item['uri'] = $url;
        $item['comments'] = $url . '#PostsListModule';
        $item['title'] = $name;
        $item['author'] = $author !== '' ? $author : null;

        $categories = [];
        if ($category !== '') {
            $categories[] = $category;
        }
        if ($rootCategory !== '' && $rootCategory !== $category) {
            $categories[] = $rootCategory;
        }
        $item['categories'] = $categories;

        $timestamp = $date;
        if ($includeUpdates === true) {
            $timestamp = $udate;
        }

        if ($timestamp !== null) {
            if (is_numeric($timestamp) === true) {
                $item['timestamp'] = (int) $timestamp;
            } elseif (is_string($timestamp) === true && $timestamp !== '') {
                $item['timestamp'] = $timestamp;
            }
        }

        $item['enclosures'] = [];
        if (is_array($files) === true) {
            foreach ($files as $file) {
                if (is_array($file) === false || isset($file['_sFile']) === false) {
                    continue;
                }
                $item['enclosures'][] = self::FILES_BASE_URL . rawurlencode((string)$file['_sFile']);
            }
        }

        $content = $this->buildScreenshots($screenshotsJson);
        $content .= $this->buildUpdates($updates, $includeUpdates);

        if ($text !== '') {
            $content .= '<br>' . nl2br($text);
        }

        $item['content'] = $content;
        $item['uid'] = $url . $name . (string)($timestamp ?? '');

        return $item;
    }

    private function buildScreenshots(string $screenshotsJson): string
    {
        if ($screenshotsJson === '') {
            return '';
        }

        $imgList = json_decode($screenshotsJson, true);
        if (is_array($imgList) === false) {
            return '';
        }

        $content = '';
        foreach ($imgList as $imgElement) {
            if (is_array($imgElement) === false || isset($imgElement['_sFile']) === false) {
                continue;
            }
            $imgUrl = self::IMAGES_BASE_URL . rawurlencode((string)$imgElement['_sFile']);
            $content .= '<img src="' . e($imgUrl) . '" style="' . self::CSS['image'] . '" />';
        }

        return $content;
    }

    private function buildUpdates(array $updates, bool $includeUpdates): string
    {
        if ($includeUpdates === false || count($updates) === 0) {
            return '';
        }

        $update = $updates[0];
        if (is_array($update) === false) {
            return '';
        }

        $updateTitle = (string)($update['_sTitle'] ?? '');
        $updateText = (string)($update['_sText'] ?? '');
        $changeLog = $update['_aChangeLog'] ?? [];

        $content = '<br><strong>Update:</strong> ' . e($updateTitle);

        if ($updateText !== '') {
            $content .= '<br>' . nl2br($updateText);
        }

        if (is_array($changeLog) === true) {
            foreach ($changeLog as $change) {
                if (is_array($change) === false) {
                    continue;
                }
                $cat = (string)($change['cat'] ?? '');
                $changeText = (string)($change['text'] ?? '');

                if ($cat === '') {
                    $cat = 'Change';
                }

                $content .= '<br><em>' . e($cat) . '</em>: ' . e($changeText);
            }
        }

        $content .= '<br><hr>';

        return $content;
    }
}
