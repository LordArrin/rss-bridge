<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class ModrinthBridge extends BridgeAbstract
{
    public const NAME = 'Modrinth';
    public const URI = 'https://modrinth.com/';
    public const DESCRIPTION = 'Returns new versions of mods, resource packs, etc.';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    private const CSS = [
    'block_wrap' => 'margin: 16px 0; padding: 12px 16px; font-family: Arial, sans-serif; font-size: 13px;',
    'compat_border' => 'border-left: 4px solid #d4a574;',
    'changes_border' => 'border-left: 4px solid #c9b96e;',
    'block_title' => 'margin: 0 0 10px 0; font-size: 15px; font-weight: bold;',
    'compat_item' => 'margin: 4px 0;',
    'compat_label' => 'font-weight: bold;',
    'compat_link' => 'color: #c9956b; text-decoration: none;',
    'buttons_wrap' => 'margin: 16px 0;',
    'button' => 'display:inline-block;margin-right:8px;padding:8px 18px;border-radius:4px;color:#fff;text-decoration:none;font-size:13px;font-weight:700;background-color:#d4a574;',
    ];

    public const PARAMETERS = [
        [
            'name' => [
                'name' => 'Name',
                'required' => true,
                'title' => 'The project name as seen in the URL bar',
                'exampleValue' => 'sodium'
            ],
            'category' => [
                'name' => 'Category',
                'type' => 'list',
                'values' => [
                    'Mod' => 'mod',
                    'Resource Pack' => 'resourcepack',
                    'Data Pack' => 'datapack',
                    'Shader' => 'shader',
                    'Modpack' => 'modpack',
                    'Plugin' => 'plugin'
                ],
                'defaultValue' => 'mod'
            ],
            'loaders' => [
                'name' => 'Loaders',
                'title' => 'List of mod loaders, separated by commas',
                'exampleValue' => 'neoforge, fabric'
            ],
            'game_versions' => [
                'name' => 'Game versions',
                'title' => 'List of game versions, separated by commas',
                'exampleValue' => '1.19.1, 1.19.2'
            ],
            'featured' => [
                'name' => 'Featured',
                'type' => 'list',
                'values' => [
                    'Unset' => '',
                    'True' => 'true',
                    'False' => 'false'
                ],
                'title' => "Whether to filter for featured or non-featured\nUnset means no filter",
                'defaultValue' => ''
            ]
        ]
    ];

    public function getURI(): string
    {
        $name = (string) ($this->getInput('name') ?? '');
        $category = (string) ($this->getInput('category') ?? '');

        if ($name === '') {
            return parent::getURI();
        }

        return self::URI . $category . '/' . $name . '/versions';
    }

    public function getName(): string
    {
        $name = (string) ($this->getInput('name') ?? '');

        if ($name === '') {
            return parent::getName();
        }

        return $name;
    }

    public function collectData(): void
    {
        $apiUrl = 'https://api.modrinth.com/v2/project';
        $projectName = (string) ($this->getInput('name') ?? '');

        if ($projectName === '') {
            \throwClientException('Project name is required');
        }

        $url = sprintf('%s/%s/version', $apiUrl, $projectName);

        $featuredInput = $this->getInput('featured');
        $featured = (is_string($featuredInput) === true && $featuredInput !== '') ? $featuredInput : null;

        $queryTable = [
            'loaders' => $this->parseInputList((string) ($this->getInput('loaders') ?? '')),
            'game_versions' => $this->parseInputList((string) ($this->getInput('game_versions') ?? '')),
            'featured' => $featured
        ];

        $query = http_build_query($queryTable);
        if (is_string($query) === true && $query !== '') {
            $url .= '?' . $query;
        }

        $header = ['User-Agent: rss-bridge plugin https://github.com/RSS-Bridge/rss-bridge'];
        $json = getContents($url, $header);
        $data = json_decode($json, false);

        if (is_array($data) === false) {
            \throwServerException('Invalid API response format');
        }

        foreach ($data as $entry) {
            if (is_object($entry) === false) {
                continue;
            }

            $item = [];

            $versionNumber = (string) ($entry->version_number ?? '');
            $category = (string) ($this->getInput('category') ?? '');
            $item['uri'] = self::URI . $category . '/' . $projectName . '/version/' . $versionNumber;

            $item['title'] = (string) ($entry->name ?? '');

            $datePublished = (string) ($entry->date_published ?? '');
            $timestamp = ($datePublished !== '') ? strtotime($datePublished) : time();
            if ($timestamp === false) {
                $timestamp = time();
            }
            $item['timestamp'] = $timestamp;

            $item['author'] = 'Modrinth';

            $loaders = $entry->loaders ?? [];
            $gameVersions = $entry->game_versions ?? [];
            $files = $entry->files ?? [];
            $dependencies = $entry->dependencies ?? [];
            $changelog = (string) ($entry->changelog ?? '');

            if (is_array($loaders) === false) {
                $loaders = [];
            }
            if (is_array($gameVersions) === false) {
                $gameVersions = [];
            }
            if (is_array($files) === false) {
                $files = [];
            }
            if (is_array($dependencies) === false) {
                $dependencies = [];
            }

            $content = $this->buildCompatibilityBlock($loaders, $gameVersions, $dependencies);
            $content .= $this->buildChangesBlock($changelog);
            $content .= $this->buildDownloadButton($files);

            $item['content'] = $content;
            $item['categories'] = array_merge($loaders, $gameVersions);
            $item['uid'] = (string) ($entry->id ?? '');

            $this->items[] = $item;
        }
    }

    private function buildCompatibilityBlock(array $loaders, array $gameVersions, array $dependencies): string
    {
        $hasData = (count($loaders) > 0) || (count($gameVersions) > 0) || (count($dependencies) > 0);
        if ($hasData === false) {
            return '';
        }

        $html = '<div style="' . self::CSS['block_wrap'] . self::CSS['compat_border'] . '">';
        $html .= '<div style="' . self::CSS['block_title'] . '">Compatibility</div>';

        if (count($loaders) > 0) {
            $loadersList = array_map(function ($loader) {
                return is_string($loader) === true ? htmlspecialchars($loader) : '';
            }, $loaders);
            $html .= '<div style="' . self::CSS['compat_item'] . '">';
            $html .= '<span style="' . self::CSS['compat_label'] . '">Loaders:</span> ';
            $html .= implode(', ', $loadersList);
            $html .= '</div>';
        }

        if (count($gameVersions) > 0) {
            $versionsList = array_map(function ($version) {
                return is_string($version) === true ? htmlspecialchars($version) : '';
            }, $gameVersions);
            $html .= '<div style="' . self::CSS['compat_item'] . '">';
            $html .= '<span style="' . self::CSS['compat_label'] . '">Game versions:</span> ';
            $html .= implode(', ', $versionsList);
            $html .= '</div>';
        }

        if (count($dependencies) > 0) {
            $depsHtml = [];
            foreach ($dependencies as $dep) {
                if (is_object($dep) === false) {
                    continue;
                }
                $projectId = (string) ($dep->project_id ?? '');
                $depType = (string) ($dep->dependency_type ?? 'required');
                $fileName = (string) ($dep->file_name ?? '');

                $label = ($projectId !== '') ? $projectId : $fileName;
                if ($label === '') {
                    continue;
                }

                $depUrl = ($projectId !== '') ? (self::URI . 'mod/' . $projectId) : '#';
                $escapedLabel = htmlspecialchars($label);
                $escapedType = htmlspecialchars($depType);
                $escapedUrl = htmlspecialchars($depUrl);

                $linkHtml = '<a href="' . $escapedUrl . '" style="' . self::CSS['compat_link'] . '">';
                $linkHtml .= $escapedLabel . '</a> <small>(' . $escapedType . ')</small>';
                $depsHtml[] = $linkHtml;
            }

            if (count($depsHtml) > 0) {
                $html .= '<div style="' . self::CSS['compat_item'] . '">';
                $html .= '<span style="' . self::CSS['compat_label'] . '">Dependencies:</span> ';
                $html .= implode(', ', $depsHtml);
                $html .= '</div>';
            }
        }

        $html .= '</div>';

        return $html;
    }

    private function buildChangesBlock(string $changelog): string
    {
        if ($changelog === '') {
            return '';
        }

        $parsedown = new \Parsedown();
        $changelogHtml = $parsedown->text($changelog);

        $html = '<div style="' . self::CSS['block_wrap'] . self::CSS['changes_border'] . '">';
        $html .= '<div style="' . self::CSS['block_title'] . '">Changes</div>';
        $html .= $changelogHtml;
        $html .= '</div>';

        return $html;
    }

    private function buildDownloadButton(array $files): string
    {
        $downloadUrl = '';
        foreach ($files as $file) {
            if (is_object($file) === false) {
                continue;
            }
            $isPrimary = $file->primary ?? false;
            $fileUrl = (string) ($file->url ?? '');

            if ($isPrimary === true && $fileUrl !== '') {
                $downloadUrl = $fileUrl;
                break;
            }

            if ($downloadUrl === '' && $fileUrl !== '') {
                $downloadUrl = $fileUrl;
            }
        }

        if ($downloadUrl === '') {
            return '';
        }

        $html = '<div style="' . self::CSS['buttons_wrap'] . '">';
        $html .= '<a href="' . htmlspecialchars($downloadUrl) . '" style="' . self::CSS['button'] . '">Download</a>';
        $html .= '</div>';

        return $html;
    }

    protected function parseInputList(string $input): ?string
    {
        if ($input === '') {
            return null;
        }

        $items = array_filter(array_map('trim', explode(',', $input)));

        if (count($items) > 0) {
            $encoded = json_encode($items);
            return is_string($encoded) === true ? $encoded : null;
        }

        return null;
    }
}
