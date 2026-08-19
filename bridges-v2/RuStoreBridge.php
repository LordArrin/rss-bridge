<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class RuStoreBridge extends BridgeAbstract
{
    public const NAME = 'RuStore';
    public const URI = 'https://www.rustore.ru';
    public const DESCRIPTION = 'Returns application updates with its changelog';
    public const MAINTAINER = 'LordArrin';
    public const CACHE_TIMEOUT = 3600;
    public const PARAMETERS = [
        [
            'package' => [
                'name' => 'Package name',
                'type' => 'text',
                'required' => true,
                'exampleValue' => 'com.flyersoft.moonreader',
                'pattern' => '^[a-zA-Z][a-zA-Z0-9_]*(\.[a-zA-Z][a-zA-Z0-9_]*)+$',
            ],
        ],
    ];

    private const BASE_URL = self::URI . '/catalog/app/';
    private const VERSIONS_PATH = '/versions';
    private const PACKAGE_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_]*(\.[a-zA-Z][a-zA-Z0-9_]*)+$/';
    private const HTTP_HEADERS = [
        'Referer: https://www.rustore.ru/',
    ];
    private const CSS_STYLES = [
        'item' => 'background:rgba(0,119,255,0.08);color:inherit;padding:12px;border-left:3px solid #0077ff;margin:0 0 15px 0;font-size:0.95em;line-height:1.5;border-radius:4px',
        'empty' => 'font-style:italic;color:inherit;opacity:0.6;padding:8px 0',
    ];

    private string $package = '';
    private string $appName = '';
    private ?string $appIcon = null;

    public function collectData(): void
    {
        $package = (string) $this->getInput('package');

        if (preg_match(self::PACKAGE_PATTERN, $package) === 0) {
            throw new \InvalidArgumentException('Invalid package name format.');
        }

        $this->package = $package;
        $html = getContents(self::BASE_URL . urlencode($package) . self::VERSIONS_PATH, self::HTTP_HEADERS);

        if ($html === '') {
            throw new \RuntimeException('Failed to load page (empty response).');
        }

        $this->appName = $this->extractAppName($html);
        $this->appIcon = $this->extractOgImage($html);

        $versions = $this->parseJsonLd($html);
        if ($versions === []) {
            $versions = $this->parseNextJsPayload($html);
        }

        if ($versions === []) {
            throw new \RuntimeException('No version data found.');
        }

        $this->items = array_map(
            callback: fn(array $v): array => $this->buildItem($v),
            array: $versions,
        );
    }

    public function getName(): string
    {
        if ($this->appName === '') {
            return parent::getName();
        }

        $clean = preg_replace('/[\x{1F300}-\x{1F9FF}\x{2600}-\x{27BF}\x{FE00}-\x{FE0F}]/u', '', $this->appName);
        $clean = trim((string) $clean);

        if ($clean === '') {
            return parent::getName();
        }

        return $clean;
    }

    public function getURI(): string
    {
        if ($this->package === '') {
            return parent::getURI();
        }
        return self::BASE_URL . urlencode($this->package);
    }

    public function getIcon(): ?string
    {
        if ($this->appIcon === null) {
            return parent::getIcon();
        }
        return $this->appIcon;
    }

    private function extractAppName(string $html): string
    {
        $offset = 0;
        $needle = '<script type="application/ld+json">';

        while (($pos = strpos($html, $needle, $offset)) !== false) {
            $start = $pos + strlen($needle);
            $end = strpos($html, '</script>', $start);
            if ($end === false) {
                break;
            }

            $json = html_entity_decode(substr($html, $start, $end - $start), ENT_QUOTES | ENT_HTML5);
            $offset = $end + 9;

            if (json_validate($json) === false) {
                continue;
            }

            try {
                $data = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (($data['@type'] ?? null) === 'BreadcrumbList' && isset($data['itemListElement']) === true) {
                $last = end($data['itemListElement']);
                if (isset($last['name']) === true) {
                    return (string) $last['name'];
                }
            }
        }
        return '';
    }

    private function extractOgImage(string $html): ?string
    {
        $pos = strpos($html, 'property="og:image"');
        if ($pos === false) {
            $pos = strpos($html, "property='og:image'");
        }
        if ($pos === false) {
            return null;
        }

        $fragment = substr($html, $pos, 500);
        if (preg_match('/content\s*=\s*["\']([^"\']+)["\']/', $fragment, $m) === 1) {
            $url = $m[1];
            if (str_starts_with($url, '//') === true) {
                return 'https:' . $url;
            }
            return $url;
        }
        return null;
    }

    private function parseJsonLd(string $html): array
    {
        $versions = [];
        $offset = 0;
        $needle = '<script type="application/ld+json">';

        while (($pos = strpos($html, $needle, $offset)) !== false) {
            $start = $pos + strlen($needle);
            $end = strpos($html, '</script>', $start);
            if ($end === false) {
                break;
            }

            $json = html_entity_decode(substr($html, $start, $end - $start), ENT_QUOTES | ENT_HTML5);
            $offset = $end + 9;

            if (json_validate($json) === false) {
                continue;
            }

            try {
                $data = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (($data['@type'] ?? null) !== 'ItemList') {
                continue;
            }
            if (isset($data['itemListElement']) === false) {
                continue;
            }

            foreach ($data['itemListElement'] as $element) {
                if (is_array($element) === false) {
                    continue;
                }
                if (($element['@type'] ?? null) !== 'UpdateAction') {
                    continue;
                }
                if (isset($element['name']) === false) {
                    continue;
                }

                $versions[] = [
                    'versionName' => (string) $element['name'],
                    'whatsNew' => (string) ($element['description'] ?? ''),
                    'date' => (string) ($element['startTime'] ?? ''),
                ];
            }

            if ($versions !== []) {
                return $versions;
            }
        }
        return $versions;
    }

    private function parseNextJsPayload(string $html): array
    {
        $marker = '"versions":[';
        $pos = strpos($html, $marker);
        if ($pos === false) {
            $pos = strpos($html, '"versions" : [');
        }
        if ($pos === false) {
            return [];
        }

        $arrayStart = $pos + strlen($marker);
        $arrayEnd = $this->findMatchingBracket($html, $arrayStart - 1, '[', ']');
        if ($arrayEnd === false) {
            return [];
        }

        $json = '[' . substr($html, $arrayStart, $arrayEnd - $arrayStart) . ']';
        if (json_validate($json) === false) {
            return [];
        }

        try {
            $data = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (is_array($data) === false) {
            return [];
        }

        $versions = [];
        foreach ($data as $v) {
            if (is_array($v) === false) {
                continue;
            }
            if (isset($v['versionName']) === false) {
                continue;
            }
            $versions[] = [
                'versionName' => (string) $v['versionName'],
                'whatsNew' => (string) ($v['whatsNew'] ?? ''),
                'date' => (string) ($v['appVerUpdatedAt'] ?? ''),
            ];
        }
        return $versions;
    }

    private function findMatchingBracket(string $str, int $openPos, string $openChar, string $closeChar): int|false
    {
        $depth = 0;
        $inString = false;
        $escape = false;

        for ($i = $openPos, $len = strlen($str); $i < $len; $i++) {
            $c = $str[$i];

            if ($escape === true) {
                $escape = false;
                continue;
            }

            if ($c === '\\') {
                $escape = true;
                continue;
            }

            if ($c === '"') {
                $inString = ($inString === false);
                continue;
            }

            if ($inString === true) {
                continue;
            }

            if ($c === $openChar) {
                $depth++;
            } elseif ($c === $closeChar) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return false;
    }

    private function formatChangelog(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '<p style="' . self::CSS_STYLES['empty'] . '">No changelog available.</p>';
        }

        $splitResult = preg_split('/\r\n|\r|\n/', $text);
        $lines = array_filter(
            array_map(trim(...), $splitResult !== false ? $splitResult : []),
            static fn(string $s): bool => $s !== '',
        );

        if ($lines === []) {
            return '<p style="' . self::CSS_STYLES['empty'] . '">No changelog available.</p>';
        }

        $lines = array_values($lines);
        $isImplicitList = $this->looksLikeImplicitList($lines);
        $bullet = "\u{2022}";
        $formatted = [];

        foreach ($lines as $line) {
            if (preg_match('/^[\x{00AD}\x{FEFF}]?([\x{2460}-\x{2473}])\s*(.*)$/u', $line, $m) === 1) {
                $num = mb_ord($m[1], 'UTF-8') - 0x245F;
                $clean = $this->capitalize(trim($m[2]));
                if ($clean !== '') {
                    $formatted[] = $num . '. ' . htmlspecialchars($clean, ENT_QUOTES | ENT_HTML5);
                }
                continue;
            }

            if (preg_match('/^[\x{00AD}\x{FEFF}]?[\x{25CF}\x{2014}\x{2013}\-\x{2022}*]\s*(.*)$/u', $line, $m) === 1) {
                $clean = $this->capitalize(trim($m[1]));
                if ($clean !== '') {
                    $formatted[] = $bullet . ' ' . htmlspecialchars($clean, ENT_QUOTES | ENT_HTML5);
                }
                continue;
            }

            if ($line !== '') {
                if ($isImplicitList === true) {
                    $clean = $this->capitalize(rtrim($line, '; '));
                    if ($clean !== '') {
                        $formatted[] = $bullet . ' ' . htmlspecialchars($clean, ENT_QUOTES | ENT_HTML5);
                    }
                } else {
                    $formatted[] = htmlspecialchars($line, ENT_QUOTES | ENT_HTML5);
                }
            }
        }

        if ($formatted === []) {
            return '<p style="' . self::CSS_STYLES['empty'] . '">No changelog available.</p>';
        }

        return sprintf(
            '<div style="%s"><p>%s</p></div>',
            self::CSS_STYLES['item'],
            implode('<br>', $formatted),
        );
    }

    private function looksLikeImplicitList(array $lines): bool
    {
        if (count($lines) < 2) {
            return false;
        }

        $semicolonCount = 0;
        $capitalStartCount = 0;
        $hasExplicitMarker = false;

        foreach ($lines as $line) {
            if (preg_match('/;\s*$/u', $line) === 1) {
                $semicolonCount++;
            }
            if (preg_match('/^[\p{Lu}0-9]/u', $line) === 1) {
                $capitalStartCount++;
            }
            if (preg_match('/^[\x{00AD}\x{FEFF}]?[\x{2014}\x{2013}\-\x{2022}\x{25CF}*]/u', $line) === 1) {
                $hasExplicitMarker = true;
            }
        }

        if ($hasExplicitMarker === true) {
            return false;
        }

        if ($semicolonCount >= count($lines) - 1) {
            return true;
        }

        return $capitalStartCount >= count($lines);
    }

    private function capitalize(string $text): string
    {
        if ($text === '') {
            return '';
        }
        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }

    private function buildItem(array $version): array
    {
        try {
            $timestamp = (new \DateTimeImmutable($version['date']))->getTimestamp();
        } catch (\Exception) {
            $ts = strtotime($version['date'] !== '' ? $version['date'] : 'now');
            if ($ts === false) {
                $timestamp = time();
            } else {
                $timestamp = $ts;
            }
        }

        return [
            'uri' => self::BASE_URL . urlencode($this->package) . self::VERSIONS_PATH . '#v' . urlencode($version['versionName']),
            'title' => $version['versionName'],
            'content' => $this->formatChangelog($version['whatsNew']),
            'uid' => sprintf('rustore-%s-%s', $this->package, $version['versionName']),
            'timestamp' => $timestamp,
        ];
    }
}
