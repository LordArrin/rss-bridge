<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class AlpineReleasesBridge extends BridgeAbstract
{
    public const NAME = 'Alpine Releases';
    public const URI = 'https://alpinelinux.org/';
    public const DESCRIPTION = 'Alpine Linux releases with branch info';
    public const MAINTAINER = 'LordArrin';
    public const CACHE_TIMEOUT = 3600;

    public function collectData(): void
    {
        $branches = $this->getBranchesInfo();

        $feedXml = getContents(self::URI . 'atom.xml');
        if ($feedXml === '') {
            throwServerException('Failed to fetch Atom feed');
        }

        libxml_use_internal_errors(true);
        $xml = \Dom\XMLDocument::createFromString($feedXml);
        libxml_use_internal_errors(false);

        $entries = $xml->getElementsByTagName('entry');

        foreach ($entries as $entry) {
            $titleEl = $entry->getElementsByTagName('title')->item(0);
            $title = $titleEl !== null ? trim($titleEl->textContent ?? '') : '';
            if ($title === '') {
                continue;
            }

            if (preg_match('/alpine.*\d+\.\d+(\.\d+)?.*(released|releases)/i', $title) !== 1) {
                continue;
            }

            preg_match_all('/(\d+\.\d+(?:\.\d+)?)/', $title, $matches);
            $versions = $matches[1] ?? [];

            $updatedEl = $entry->getElementsByTagName('updated')->item(0);
            $timestamp = 0;
            if ($updatedEl !== null) {
                $dateText = trim($updatedEl->textContent ?? '');
                if ($dateText !== '') {
                    $parsed = strtotime($dateText);
                    if ($parsed !== false) {
                        $timestamp = $parsed;
                    }
                }
            }

            $contentEl = $entry->getElementsByTagName('content')->item(0);
            $summaryEl = $entry->getElementsByTagName('summary')->item(0);
            $rawContent = $contentEl !== null ? ($contentEl->textContent ?? '') : ($summaryEl !== null ? $summaryEl->textContent : '');
            $content = trim((string)$rawContent);

            $plainContent = trim(strip_tags($content));
            if ($plainContent === $title || $plainContent === '') {
                $content = '';
            }

            $branchesMeta = $this->buildBranchesMeta($versions, $branches);
            if ($branchesMeta !== '') {
                $content = $branchesMeta . ($content !== '' ? '<br>' . $content : '');
            }

            $linkEl = $entry->getElementsByTagName('link')->item(0);
            $uri = $linkEl !== null ? ($linkEl->getAttribute('href') ?? '') : '';

            $authorEl = $entry->getElementsByTagName('author')->item(0);
            $author = '';
            if ($authorEl !== null) {
                $authorNameEl = $authorEl->getElementsByTagName('name')->item(0);
                if ($authorNameEl !== null) {
                    $author = trim($authorNameEl->textContent ?? '');
                }
            }

            $idEl = $entry->getElementsByTagName('id')->item(0);
            $uid = $idEl !== null ? ($idEl->textContent ?? '') : uniqid('alpine-', true);

            $this->items[] = [
                'title'     => $title,
                'uri'       => $uri,
                'author'    => $author,
                'timestamp' => $timestamp,
                'uid'       => $uid,
                'content'   => $content !== '' ? $content : $title,
            ];
        }

        usort($this->items, function ($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });
    }

    private function getBranchesInfo(): array
    {
        $url = self::URI . 'releases/';
        $html = getContents($url);
        if ($html === '') {
            return [];
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $branches = [];

        $rows = $dom->querySelectorAll('table tr');
        foreach ($rows as $row) {
            $cells = $row->querySelectorAll('td');
            $cellsArray = iterator_to_array($cells);

            if (count($cellsArray) < 5) {
                continue;
            }

            $branchName = $this->cleanText(trim($cellsArray[0]->textContent ?? ''));
            if (str_starts_with($branchName, 'v') === true) {
                $branchName = substr($branchName, 1);
            }

            $branchDate = $this->cleanText(trim($cellsArray[1]->textContent ?? ''));
            $endOfSupport = $this->cleanText(trim($cellsArray[4]->textContent ?? ''));

            preg_match_all('/(\d+\.\d+(?:\.\d+)?)/', trim($cellsArray[3]->textContent ?? ''), $matches);

            foreach ($matches[1] ?? [] as $version) {
                $branches[$version] = [
                    'branchName' => $branchName,
                    'branchDate' => $branchDate,
                    'endOfSupport' => $endOfSupport,
                ];
            }
        }

        return $branches;
    }

    private function cleanText(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $bullets = ["\xE2\x80\xA2", "\xC2\xB7", "\xE2\x97\x8F", "\xE2\x97\x8B", "\xE2\x97\xA6", "\xE2\xA6\xBF"];
        $text = str_replace($bullets, ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return is_string($text) === true ? trim($text) : '';
    }

    private function buildBranchesMeta(array $versions, array $branches): string
    {
        if ($versions === []) {
            return '';
        }

        usort($versions, function ($a, $b) {
            return version_compare($b, $a);
        });

        $rows = '';
        foreach ($versions as $version) {
            if (isset($branches[$version]) === false) {
                continue;
            }

            $info = $branches[$version];
            $esc = function ($s) {
                return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
            };

            $rows .= '<tr>';
            $rows .= '<td><b>' . $esc($version) . '</b></td>';
            $rows .= '<td>' . $esc($info['branchName']) . '</td>';
            $rows .= '<td>' . $esc($info['branchDate']) . '</td>';
            $rows .= '<td>' . $esc($info['endOfSupport']) . '</td>';
            $rows .= '</tr>';
        }

        if ($rows === '') {
            return '';
        }

        return <<<HTML
    <table border="1" cellpadding="4" cellspacing="0">
    <thead><tr>
    <th>Version</th><th>Branch</th><th>Branch date</th><th>End of support</th>
    </tr></thead>
    <tbody>{$rows}</tbody>
    </table>
    HTML;
    }
}
