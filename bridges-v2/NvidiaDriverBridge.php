<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class NvidiaDriverBridge extends BridgeAbstract
{
    public const NAME = 'NVIDIA Driver Releases';
    public const URI = 'https://www.nvidia.com/Download/processFind.aspx';
    public const DESCRIPTION = 'Fetch the latest NVIDIA driver updates';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 86400;

    public const PARAMETERS = [
        'Windows' => [
            'wwhql' => [
                'name' => 'Driver Type',
                'type' => 'list',
                'values' => [
                    'All' => '',
                    'Certified' => '1',
                    'Studio' => '4',
                ],
                'defaultValue' => '1',
            ],
        ],
        'Linux' => [
            'lwhql' => [
                'name' => 'Driver Type',
                'type' => 'list',
                'values' => [
                    'All' => '',
                    'Beta' => '0',
                    'Branch' => '5',
                    'Certified' => '1',
                ],
                'defaultValue' => '1',
            ],
        ],
        'FreeBSD' => [
            'fwhql' => [
                'name' => 'Driver Type',
                'type' => 'list',
                'values' => [
                    'All' => '',
                    'Beta' => '0',
                    'Branch' => '5',
                    'Certified' => '1',
                ],
                'defaultValue' => '1',
            ],
        ],
    ];

    private const WHQL_LABELS = [
        '' => 'All',
        '0' => 'Beta',
        '1' => 'Certified',
        '4' => 'Studio',
        '5' => 'Branch',
    ];

    private const CSS = [
        'wrapper'   => 'font-family:sans-serif;line-height:1.6;word-wrap:break-word;',
        'ul'        => 'margin:1em 0;padding-left:2em;list-style-type:disc;',
        'li'        => 'margin:0.5em 0;',
        'download'  => 'display:inline-block;margin-top:1em;padding:8px 16px;background:#76b900;color:#fff;text-decoration:none;border-radius:4px;font-weight:bold;',
        'empty'     => 'color:#666;font-style:italic;',
    ];

    private string $operatingSystem = '';

    public function collectData(): void
    {
        $parameters = [
            'lid'  => 1,
            'psid' => 129,
        ];

        switch ($this->queriedContext) {
            case 'Windows':
                $whql = $this->getInput('wwhql');
                $parameters['osid'] = 57;
                $parameters['dtcid'] = 1;
                $parameters['whql'] = $whql;
                $this->operatingSystem = 'Windows';
                break;
            case 'Linux':
                $whql = $this->getInput('lwhql');
                $parameters['osid'] = 12;
                $parameters['whql'] = $whql;
                $this->operatingSystem = 'Linux';
                break;
            case 'FreeBSD':
                $whql = $this->getInput('fwhql');
                $parameters['osid'] = 22;
                $parameters['whql'] = $whql;
                $this->operatingSystem = 'FreeBSD';
                break;
        }

        $url = 'https://www.nvidia.com/Download/processFind.aspx?' . http_build_query($parameters);
        $html = getContents($url);

        if ($html === '' || $html === null) {
            throwServerException('Failed to fetch NVIDIA driver page');
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        foreach ($dom->querySelectorAll('tr#driverList') as $element) {
            $img = $element->querySelector('img');
            if ($img === null) {
                continue;
            }

            $imgId = $img->getAttribute('id');
            if ($imgId === null) {
                continue;
            }

            $id = str_replace('img_', '', $imgId);

            $nameCell = $element->querySelector('td.gridItem.driverName');
            $versionCell = $element->querySelector('td.gridItem:nth-child(3)');
            $dateCell = $element->querySelector('td.gridItem:nth-child(4)');
            $contentSpan = $dom->querySelector('tr#tr_' . $id . ' span');

            if ($nameCell === null || $versionCell === null || $dateCell === null) {
                continue;
            }

            $linkNode = $nameCell->querySelector('a');
            $driverName = trim($nameCell->textContent);
            $version = trim($versionCell->textContent);
            $date = trim($dateCell->textContent);

            $releaseNotes = $this->extractReleaseNotes($contentSpan);

            $content = '<div style="' . self::CSS['wrapper'] . '">' . $releaseNotes . $downloadButton . '</div>';

            $parsedTimestamp = strtotime($date);

            $this->items[] = [
                'timestamp' => $parsedTimestamp !== false ? $parsedTimestamp : null,
                'title'     => sprintf('%s %s', $driverName, $version),
                'content'   => $content,
            ];
        }
    }

    public function getIcon(): string
    {
        return 'https://www.nvidia.com/favicon.ico';
    }

    public function getName(): string
    {
        $key = match ($this->queriedContext) {
            'Windows' => 'wwhql',
            'Linux' => 'lwhql',
            'FreeBSD' => 'fwhql',
            default => null,
        };

        $whqlValue = $key !== null ? (string)$this->getInput($key) : '';
        $driverType = self::WHQL_LABELS[$whqlValue] ?? 'All';

        return sprintf('NVIDIA %s %s Drivers', $this->operatingSystem, $driverType);
    }

    private function extractReleaseNotes(?\Dom\Element $contentSpan): string
    {
        if ($contentSpan === null) {
            return '<p style="' . self::CSS['empty'] . '">No release notes available.</p>';
        }

        $html = $contentSpan->innerHTML;

        $html = preg_replace(
            '/<ul([^>]*)>/i',
            '<ul$1 style="' . self::CSS['ul'] . '">',
            $html
        ) ?? $html;

        $html = preg_replace(
            '/<li([^>]*)>/i',
            '<li$1 style="' . self::CSS['li'] . '">',
            $html
        ) ?? $html;

        return $html;
    }
}
