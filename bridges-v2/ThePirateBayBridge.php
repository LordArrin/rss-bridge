<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class ThePirateBayBridge extends BridgeAbstract
{
    public const NAME = 'The Pirate Bay';
    public const URI = 'https://thepiratebay.org';
    public const DESCRIPTION = 'Returns results for the keywords, with categories and filtering';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [[
        'q' => [
            'name' => 'keywords/username/category, separated by semicolons',
            'exampleValue' => 'simpsons',
            'required' => true,
        ],
        'crit' => [
            'type' => 'list',
            'name' => 'Search type',
            'values' => [
                'search' => 'search',
                'category' => 'cat',
                'user' => 'usr',
            ],
        ],
        'catCheck' => [
            'type' => 'checkbox',
            'name' => 'Specify category for keyword search?',
        ],
        'cat' => [
            'name' => 'Category number',
            'exampleValue' => '100, 200… See TPB for category number',
        ],
        'trusted' => [
            'type' => 'checkbox',
            'name' => 'Only get results from Trusted or VIP users?',
        ],
    ]];

    public const STATIC_SERVER = 'https://torrindex.net';

    public const CATEGORIES = [
        '1' => 'Audio',
        '2' => 'Video',
        '3' => 'Applications',
        '4' => 'Games',
        '5' => 'Porn',
        '6' => 'Other',
        '100' => 'Audio',
        '200' => 'Video',
        '300' => 'Applications',
        '400' => 'Games',
        '500' => 'Porn',
        '600' => 'Other',
        '101' => 'Music',
        '102' => 'Audio Books',
        '103' => 'Sound clips',
        '104' => 'FLAC',
        '199' => 'Other',
        '201' => 'Movies',
        '202' => 'Movies DVDR',
        '203' => 'Music videos',
        '204' => 'Movie Clips',
        '205' => 'TV-Shows',
        '206' => 'Handheld',
        '207' => 'HD Movies',
        '208' => 'HD TV-Shows',
        '209' => '3D',
        '210' => 'CAM/TS',
        '211' => 'UHD/4k Movies',
        '212' => 'UHD/4k TV-Shows',
        '299' => 'Other',
        '301' => 'Windows',
        '302' => 'Mac/Apple',
        '303' => 'UNIX',
        '304' => 'Handheld',
        '305' => 'IOS(iPad/iPhone)',
        '306' => 'Android',
        '399' => 'Other OS',
        '401' => 'PC',
        '402' => 'Mac/Apple',
        '403' => 'PSx',
        '404' => 'XBOX360',
        '405' => 'Wii',
        '406' => 'Handheld',
        '407' => 'IOS(iPad/iPhone)',
        '408' => 'Android',
        '499' => 'Other OS',
        '501' => 'Movies',
        '502' => 'Movies DVDR',
        '503' => 'Pictures',
        '504' => 'Games',
        '505' => 'HD-Movies',
        '506' => 'Movie Clips',
        '507' => 'UHD/4k Movies',
        '599' => 'Other',
        '601' => 'E-books',
        '602' => 'Comics',
        '603' => 'Pictures',
        '604' => 'Covers',
        '605' => 'Physibles',
        '699' => 'Other',
    ];

    public function collectData()
    {
        $q = $this->getInput('q');
        if (is_string($q) === false) {
            return;
        }

        $keywords = explode(';', $q);

        foreach ($keywords as $keyword) {
            $this->processKeyword($keyword);
        }
    }

    private function processKeyword(string $keyword): void
    {
        $keyword = trim($keyword);
        $crit = $this->getInput('crit');

        if ($crit === 'search') {
            $catCheck = $this->getInput('catCheck');
            if ($catCheck === true) {
                $categories = $this->getInput('cat');
                $query = sprintf(
                    '/q.php?q=%s&cat=%s',
                    rawurlencode($keyword),
                    rawurlencode((string)$categories)
                );
            } else {
                $query = sprintf('/q.php?q=%s', rawurlencode($keyword));
            }
        } elseif ($crit === 'cat') {
            $query = sprintf('/q.php?q=category:%s', rawurlencode($keyword));
        } elseif ($crit === 'usr') {
            $query = sprintf('/q.php?q=user:%s', rawurlencode($keyword));
        } else {
            throwClientException('Impossible');
        }

        $api = 'https://apibay.org';
        $json = getContents($api . $query);
        $result = json_decode($json);

        if (is_array($result) === false || isset($result[0]) === false) {
            return;
        }

        if (isset($result[0]->name) === true && $result[0]->name === 'No results returned') {
            return;
        }

        $trustedOnly = $this->getInput('trusted') === true;

        foreach ($result as $torrent) {
            if (is_object($torrent) === false) {
                continue;
            }

            if ($trustedOnly === true && in_array($torrent->status ?? '', ['vip', 'trusted'], true) === false) {
                continue;
            }

            $this->processTorrent($torrent);
        }
    }

    private function processTorrent(object $torrent): void
    {
        $trackers = [
            'udp://tracker.coppersurfer.tk:6969/announce',
            'udp://tracker.openbittorrent.com:6969/announce',
            'udp://9.rarbg.to:2710/announce',
            'udp://9.rarbg.me:2780/announce',
            'udp://9.rarbg.to:2730/announce',
            'udp://tracker.opentrackr.org:1337',
            'http://p4p.arenabg.com:1337/announce',
            'udp://tracker.torrent.eu.org:451/announce',
            'udp://tracker.tiny-vps.com:6969/announce',
            'udp://open.stealth.si:80/announce',
        ];

        $magnetLink = sprintf(
            'magnet:?xt=urn:btih:%s&dn=%s',
            rawurlencode((string)($torrent->info_hash ?? '')),
            rawurlencode((string)($torrent->name ?? ''))
        );

        foreach ($trackers as $tracker) {
            $magnetLink .= '&tr=' . rawurlencode($tracker);
        }

        $item = [];

        $item['title'] = (string)($torrent->name ?? '');
        $item['uri'] = sprintf('%s/description.php?id=%s', self::URI, rawurlencode((string)($torrent->id ?? '')));

        if (isset($torrent->added) === true) {
            $item['timestamp'] = (int)$torrent->added;
        } else {
            $item['timestamp'] = time();
        }

        if (isset($torrent->username) === true) {
            $item['author'] = (string)$torrent->username;
        } else {
            $item['author'] = null;
        }

        $content = '<b>Type:</b> ' . $this->renderCategory((string)($torrent->category ?? '')) . '<br>';

        $numFiles = (int)($torrent->num_files ?? 0);
        if ($numFiles > 0) {
            $content .= '<b>Files:</b> ' . e((string)$numFiles) . '<br>';
        }

        $content .= '<b>Size:</b> ' . format_bytes((int)($torrent->size ?? 0)) . '<br>';
        $content .= '<b>Seeders:</b> ' . e((string)($torrent->seeders ?? '0')) . '<br>';
        $content .= '<b>Leechers:</b> ' . e((string)($torrent->leechers ?? '0')) . '<br>';
        $content .= '<b>Info hash:</b> ' . e((string)($torrent->info_hash ?? '')) . '<br><br>';

        $imdb = $torrent->imdb ?? null;
        if (is_string($imdb) === true && $imdb !== '') {
            $content .= '<b>Imdb:</b> ' . $this->renderImdbLink($imdb) . '<br><br>';
        }

        $content .= '<a href="' . e($magnetLink) . '">magnet link</a>';

        $item['content'] = $content;

        $this->items[] = $item;
    }

    private function renderCategory(string $category): string
    {
        if (strlen($category) < 1) {
            return '';
        }

        $mainCatNum = $category[0] . '00';
        $mainCatName = self::CATEGORIES[$mainCatNum] ?? 'Unknown';
        $subCatName = self::CATEGORIES[$category] ?? 'Unknown';

        $mainCategory = sprintf(
            '<a href="%s/search.php?q=category:%s">%s</a>',
            self::URI,
            rawurlencode($mainCatNum),
            e($mainCatName)
        );

        $subCategory = sprintf(
            '<a href="%s/search.php?q=category:%s">%s</a>',
            self::URI,
            rawurlencode($category),
            e($subCatName)
        );

        return sprintf('%s &gt; %s', $mainCategory, $subCategory);
    }

    private function renderStatusImage(string $status): string
    {
        return match ($status) {
            'trusted' => sprintf(
                '<img src="%s/images/trusted.png" title="Trusted"/>',
                self::STATIC_SERVER
            ),
            'vip' => sprintf(
                '<img src="%s/images/vip.gif" title="VIP"/>',
                self::STATIC_SERVER
            ),
            'helper' => sprintf(
                '<img src="%s/images/helper.png" title="Helper"/>',
                self::STATIC_SERVER
            ),
            'moderator' => sprintf(
                '<img src="%s/images/moderator.gif" title="Moderator"/>',
                self::STATIC_SERVER
            ),
            'supermod' => sprintf(
                '<img src="%s/images/supermod.png" title="Super Mod"/>',
                self::STATIC_SERVER
            ),
            'admin' => sprintf(
                '<img src="%s/images/admin.gif" title="Admin"/>',
                self::STATIC_SERVER
            ),
            default => '',
        };
    }

    private function renderImdbLink(string $imdb): string
    {
        $url = 'https://www.imdb.com/title/' . $imdb;
        return sprintf(
            '<a href="%s">%s</a>',
            e($url),
            e($url)
        );
    }
}
