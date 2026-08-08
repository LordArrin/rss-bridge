<?php

declare(strict_types=1);

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

    private const BASE_URL = 'https://www.rustore.ru/catalog/app/';
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

    #[\Override]
    public function collectData(): void
    {
        $package = (string) $this->getInput('package');
        
        if (!preg_match(self::PACKAGE_PATTERN, $package)) {
            throw new \InvalidArgumentException('Invalid package name format.');
        }

        $this->package = $package;
        $html = getContents(self::BASE_URL . urlencode($package) . self::VERSIONS_PATH, self::HTTP_HEADERS);

        if ($html === '') {
            throw new \RuntimeException('Failed to load page (empty response).');
        }

        $this->appName = $this->extractAppName($html);
        $this->appIcon = $this->extractOgImage($html);

        $versions = $this->parseJsonLd($html) ?: $this->parseNextJsPayload($html);

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
        return trim($clean) ?: parent::getName();
    }

    public function getURI(): string
    {
        return $this->package !== '' ? self::BASE_URL . urlencode($this->package) : parent::getURI();
    }

    public function getIcon(): ?string
    {
        return $this->appIcon ?? parent::getIcon();
    }

    private function extractAppName(string $html): string
    {
        $offset = 0;
        $needle = '<script type="application/ld+json">';

        while (($pos = strpos($html, $needle, $offset)) !== false) {
            $start = $pos + strlen($needle);
            $end = strpos($html, '</script>', $start);
            if ($end === false) break;

            $json = html_entity_decode(substr($html, $start, $end - $start), ENT_QUOTES | ENT_HTML5);
            $offset = $end + 9;

            if (!json_validate($json)) continue;

            try {
                $data = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (($data['@type'] ?? null) === 'BreadcrumbList' 
                && !empty($data['itemListElement'])) {
                $last = end($data['itemListElement']);
                return $last['name'] ?? '';
            }
        }
        return '';
    }

    private function extractOgImage(string $html): ?string
    {
        $pos = strpos($html, 'property="og:image"') ?: strpos($html, "property='og:image'");
        if ($pos === false) return null;

        $fragment = substr($html, $pos, 500);
        if (preg_match('/content\s*=\s*["\']([^"\']+)["\']/', $fragment, $m)) {
            return str_starts_with($m[1], '//') ? 'https:' . $m[1] : $m[1];
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
            if ($end === false) break;

            $json = html_entity_decode(substr($html, $start, $end - $start), ENT_QUOTES | ENT_HTML5);
            $offset = $end + 9;

            if (!json_validate($json)) continue;

            try {
                $data = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (($data['@type'] ?? null) !== 'ItemList' || empty($data['itemListElement'])) continue;

            foreach ($data['itemListElement'] as $element) {
                if (!is_array($element)) continue;
                if (($element['@type'] ?? null) !== 'UpdateAction' || empty($element['name'])) continue;

                $versions[] = [
                    'versionName' => (string) $element['name'],
                    'whatsNew' => (string) ($element['description'] ?? ''),
                    'date' => (string) ($element['startTime'] ?? ''),
                ];
            }

            if ($versions !== []) return $versions;
        }
        return $versions;
    }

    private function parseNextJsPayload(string $html): array
    {
        $marker = '"versions":[';
        $pos = strpos($html, $marker) ?: strpos($html, '"versions" : [');
        if ($pos === false) return [];

        $arrayStart = $pos + strlen($marker);
        $arrayEnd = $this->findMatchingBracket($html, $arrayStart - 1, '[', ']');
        if ($arrayEnd === false) return [];

        $json = '[' . substr($html, $arrayStart, $arrayEnd - $arrayStart) . ']';
        if (!json_validate($json)) return [];

        try {
            $data = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($data)) return [];

        $versions = [];
        foreach ($data as $v) {
            if (!is_array($v) || empty($v['versionName'])) continue;
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

            if ($escape) {
                $escape = false;
                continue;
            }

            if ($c === '\\') {
                $escape = true;
                continue;
            }

            if ($c === '"') {
                $inString = !$inString;
                continue;
            }

            if ($inString) continue;

            if ($c === $openChar) {
                $depth++;
            } elseif ($c === $closeChar) {
                if (--$depth === 0) return $i;
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

        $lines = array_filter(
            array_map(trim(...), preg_split('/\r\n|\r|\n/', $text) ?: []),
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
            if (preg_match('/^[\x{00AD}\x{FEFF}]?([\x{2460}-\x{2473}])\s*(.*)$/u', $line, $m)) {
                $num = mb_ord($m[1], 'UTF-8') - 0x245F;
                $clean = $this->capitalize(trim($m[2]));
                if ($clean !== '') {
                    $formatted[] = $num . '. ' . htmlspecialchars($clean, ENT_QUOTES | ENT_HTML5);
                }
                continue;
            }

            if (preg_match('/^[\x{00AD}\x{FEFF}]?[\x{25CF}\x{2014}\x{2013}\-\x{2022}*]\s*(.*)$/u', $line, $m)) {
                $clean = $this->capitalize(trim($m[1]));
                if ($clean !== '') {
                    $formatted[] = $bullet . ' ' . htmlspecialchars($clean, ENT_QUOTES | ENT_HTML5);
                }
                continue;
            }

            if ($line !== '') {
                if ($isImplicitList) {
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
        if (count($lines) < 2) return false;

        $semicolonCount = $capitalStartCount = $hasExplicitMarker = 0;

        foreach ($lines as $line) {
            if (preg_match('/;\s*$/u', $line)) $semicolonCount++;
            if (preg_match('/^[\p{Lu}0-9]/u', $line)) $capitalStartCount++;
            if (preg_match('/^[\x{00AD}\x{FEFF}]?[\x{2014}\x{2013}\-\x{2022}\x{25CF}*]/u', $line)) {
                $hasExplicitMarker = true;
            }
        }

        if ($hasExplicitMarker) return false;
        if ($semicolonCount >= count($lines) - 1) return true;
        return $capitalStartCount >= count($lines);
    }

    private function capitalize(string $text): string
    {
        return $text === '' ? '' : mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }

    private function buildItem(array $version): array
    {
        try {
            $timestamp = (new \DateTimeImmutable($version['date']))->getTimestamp();
        } catch (\Exception) {
            $ts = strtotime($version['date'] ?: 'now');
            $timestamp = $ts !== false ? $ts : time();
        }

        return [
            'uri' => self::BASE_URL . urlencode($this->package) . self::VERSIONS_PATH . '#v' . urlencode($version['versionName']),
            'title' => $this->appName !== '' 
                ? sprintf('%s - %s', $this->appName, $version['versionName'])
                : $version['versionName'],
            'content' => $this->formatChangelog($version['whatsNew']),
            'uid' => sprintf('rustore-%s-%s', $this->package, $version['versionName']),
            'timestamp' => $timestamp,
        ];
    }
}