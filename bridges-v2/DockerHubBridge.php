<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class DockerHubBridge extends BridgeAbstract
{
    public const NAME = 'Docker Hub';
    public const URI = 'https://hub.docker.com';
    public const DESCRIPTION = 'Returns new images for a container';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        'User Submitted Image' => [
            'user' => [
                'name' => 'User',
                'type' => 'text',
                'required' => true,
                'exampleValue' => 'lordarrin',
            ],
            'repo' => [
                'name' => 'Repository',
                'type' => 'text',
                'required' => true,
                'exampleValue' => 'rss-bridge',
            ],
            'filter' => [
                'name' => 'Filter tag',
                'type' => 'text',
                'required' => false,
                'exampleValue' => 'latest',
            ],
        ],
        'Official Image' => [
            'repo' => [
                'name' => 'Repository',
                'type' => 'text',
                'required' => true,
                'exampleValue' => 'postgres',
            ],
            'filter' => [
                'name' => 'Filter tag',
                'type' => 'text',
                'required' => false,
                'exampleValue' => 'alpine3.17',
            ],
        ],
    ];

    private const API_URL = 'https://hub.docker.com/v2/repositories/';
    private const IMAGE_URL_REGEX = '/hub\.docker\.com\/r\/([\w]+)\/([\w-]+)\/?/';
    private const OFFICIAL_IMAGE_URL_REGEX = '/hub\.docker\.com\/_\/([\w-]+)\/?/';

    private const CSS = [
        'table' => 'width: 400px; border-collapse: collapse; margin: 10px 0;',
        'header' => 'text-align: left; border: 2px solid #ccc; padding: 6px 10px;',
        'cell' => 'border: 1px solid #ccc; padding: 6px 10px;',
    ];

    /**
     * @param string $url
     * @return array|null
     */
    public function detectParameters($url)
    {
        if (is_string($url) === false) {
            return null;
        }

        if (preg_match(self::IMAGE_URL_REGEX, $url, $matches) === 1) {
            return [
                'context' => 'User Submitted Image',
                'user' => $matches[1],
                'repo' => $matches[2],
            ];
        }

        if (preg_match(self::OFFICIAL_IMAGE_URL_REGEX, $url, $matches) === 1) {
            return [
                'context' => 'Official Image',
                'repo' => $matches[1],
            ];
        }

        return null;
    }

    public function collectData()
    {
        $json = getContents($this->getApiUrl());
        $data = json_decode($json, false);

        if (is_object($data) === false || isset($data->results) === false || is_array($data->results) === false) {
            return;
        }

        foreach ($data->results as $result) {
            if (is_object($result) === false) {
                continue;
            }

            $item = [];
            $item['title'] = (string) ($result->name ?? '');
            $item['uid'] = (string) ($result->id ?? '');
            $item['uri'] = $this->getTagUrl((string) ($result->name ?? ''));

            $authorName = null;
            if (isset($result->last_updater_username) === true) {
                $authorName = (string) $result->last_updater_username;
            }
            $item['author'] = $authorName;

            $item['timestamp'] = $result->tag_last_pushed ?? null;

            $lastPushed = '';
            if (isset($result->tag_last_pushed) === true) {
                $parsedTime = strtotime((string)$result->tag_last_pushed);
                if ($parsedTime !== false) {
                    $lastPushed = date('Y-m-d H:i:s', $parsedTime);
                }
            }

            $item['content'] = $this->buildItemContent(
                (string)($result->name ?? ''),
                $lastPushed,
                $result
            );

            $this->items[] = $item;
        }
    }

    public function getURI()
    {
        $uri = parent::getURI();
        $filter = $this->getInput('filter');

        if ($this->queriedContext === 'Official Image') {
            $uri = self::URI . '/_/' . $this->getRepo();
        }

        if ($this->queriedContext === 'User Submitted Image') {
            $uri = self::URI . '/r/' . $this->getRepo();
        }

        if ($filter !== null && $filter !== '') {
            $uri .= '/tags/?&page=1&name=' . rawurlencode((string)$filter);
        }

        return $uri;
    }

    public function getName()
    {
        $repo = $this->getInput('repo');
        if ($repo === null || $repo === '') {
            return parent::getName();
        }

        $name = $this->getRepo();
        $filter = $this->getInput('filter');

        if ($filter !== null && $filter !== '') {
            $name .= ':' . (string)$filter;
        }

        return $name . ' - Docker Hub';
    }

    private function buildItemContent(string $tagName, string $lastPushed, object $result): string
    {
        $content = '<strong>Tag</strong><br>';
        $content .= '<p>' . e($tagName) . '</p>';
        $content .= '<strong>Last pushed</strong><br>';
        $content .= '<p>' . e($lastPushed) . '</p>';
        $content .= '<strong>Images</strong><br>';
        $content .= $this->getImagesTable($result);

        return $content;
    }

    private function getRepo(): string
    {
        if ($this->queriedContext === 'Official Image') {
            return (string)$this->getInput('repo');
        }

        $user = (string)$this->getInput('user');
        $repo = (string)$this->getInput('repo');

        return $user . '/' . $repo;
    }

    private function getApiUrl(): string
    {
        $url = '';

        if ($this->queriedContext === 'Official Image') {
            $url = self::API_URL . 'library/' . $this->getRepo() . '/tags/?page_size=25&page=1';
        }

        if ($this->queriedContext === 'User Submitted Image') {
            $url = self::API_URL . $this->getRepo() . '/tags/?page_size=25&page=1';
        }

        $filter = $this->getInput('filter');
        if ($filter !== null && $filter !== '') {
            $url .= '&name=' . rawurlencode((string)$filter);
        }

        return $url;
    }

    private function getLayerUrl(string $name, string $digest): string
    {
        if ($this->queriedContext === 'Official Image') {
            return self::URI . '/layers/' . $this->getRepo() . '/library/' .
                $this->getRepo() . '/' . rawurlencode($name) . '/images/' . rawurlencode($digest);
        }

        return self::URI . '/layers/' . $this->getRepo() . '/' .
            rawurlencode($name) . '/images/' . rawurlencode($digest);
    }

    private function getTagUrl(string $name): string
    {
        $url = '';

        if ($this->queriedContext === 'Official Image') {
            $url = self::URI . '/_/' . $this->getRepo();
        }

        if ($this->queriedContext === 'User Submitted Image') {
            $url = self::URI . '/r/' . $this->getRepo();
        }

        return $url . '/tags/?&name=' . rawurlencode($name);
    }

    private function getImagesTable(object $result): string
    {
        $rows = '';

        if (isset($result->images) === false || is_array($result->images) === false) {
            return '<p>No images available</p>';
        }

        foreach ($result->images as $image) {
            if (is_object($image) === false) {
                continue;
            }

            $name = (string)($result->name ?? '');
            $digest = (string)($image->digest ?? '');
            $os = (string)($image->os ?? '');
            $architecture = (string)($image->architecture ?? '');
            $size = (int)($image->size ?? 0);

            $layersUrl = $this->getLayerUrl($name, $digest);
            $shortId = $this->getShortDigestId($digest);

            $rows .= '<tr>';
            $rows .= '<td style="' . self::CSS['cell'] . '">';
            $rows .= '<a href="' . e($layersUrl) . '">' . e($shortId) . '</a>';
            $rows .= '</td>';
            $rows .= '<td style="' . self::CSS['cell'] . '">' . e($os) . '/' . e($architecture) . '</td>';
            $rows .= '<td style="' . self::CSS['cell'] . '">' . e(format_bytes($size)) . '</td>';
            $rows .= '</tr>';
        }

        $html = '<table style="' . self::CSS['table'] . '">';
        $html .= '<thead>';
        $html .= '<tr>';
        $html .= '<th style="' . self::CSS['header'] . '">Digest</th>';
        $html .= '<th style="' . self::CSS['header'] . '">OS/architecture</th>';
        $html .= '<th style="' . self::CSS['header'] . '">Compressed Size</th>';
        $html .= '</tr>';
        $html .= '</thead>';
        $html .= '<tbody>';
        $html .= $rows;
        $html .= '</tbody>';
        $html .= '</table>';

        return $html;
    }

    private function getShortDigestId(string $digest): string
    {
        $parts = explode(':', $digest);
        if (count($parts) < 2) {
            return $digest;
        }
        return substr($parts[1], 0, 12);
    }
}
