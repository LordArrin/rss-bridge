<?php

declare(strict_types=1);

final class CodebergReleasesBridge extends BridgeAbstract
{
    public const NAME = 'Codeberg Releases';
    public const URI = 'https://codeberg.org/';
    public const DESCRIPTION = 'Returns releases for a Codeberg repository with changelogs and downloads';
    public const MAINTAINER = 'LordArrin';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        [
            'owner' => [
                'name' => 'Owner',
                'title' => 'User or organization',
                'exampleValue' => 'librewolf',
                'required' => true,
                'pattern' => '[A-Za-z0-9_.-]+',
            ],
            'repo' => [
                'name' => 'Repository',
                'exampleValue' => 'bsys6',
                'required' => true,
                'pattern' => '[A-Za-z0-9_.-]+',
            ],
            'limit' => [
                'name' => 'Maximum releases',
                'type' => 'number',
                'defaultValue' => 5,
                'required' => true,
            ],
            'include_prereleases' => [
                'name' => 'Pre-releases',
                'type' => 'list',
                'values' => ['Include' => '1', 'Exclude' => '0'],
                'defaultValue' => '1',
            ],
            'downloads_mode' => [
                'name' => 'Download links',
                'type' => 'list',
                'values' => ['Hide' => 'hide', 'Inline' => 'inline', 'Enclosures' => 'enclosures'],
                'defaultValue' => 'inline',
            ],
        ],
    ];

    private const int API_LIMIT = 50;
    private const int MAX_PAGES = 5;

    public function collectData(): void
    {
        [$owner, $repo] = $this->repository();
        $this->validateSlug($owner);
        $this->validateSlug($repo);

        $limit = max(1, min(50, (int) ($this->getInput('limit') ?: 10)));

        $prereleasesInput = strtolower(trim((string) $this->getInput('include_prereleases')));
        $includePrereleases = !in_array($prereleasesInput, ['0', 'false', 'off', 'no', 'exclude', 'disabled'], true);

        $downloadsInput = strtolower(trim((string) $this->getInput('downloads_mode')));
        $downloadsMode = match ($downloadsInput) {
            'hide', 'none', '0', 'off', 'false' => 'hide',
            'enclosures', 'enclosure' => 'enclosures',
            default => 'inline',
        };

        for ($page = 1; $page <= self::MAX_PAGES && count($this->items) < $limit; ++$page) {
            $url = sprintf(
                '%s/api/v1/repos/%s/%s/releases?limit=%d&page=%d',
                rtrim(self::URI, '/'),
                rawurlencode($owner),
                rawurlencode($repo),
                self::API_LIMIT,
                $page
            );

            $releases = $this->fetchJson($url);

            if ($releases === []) {
                break;
            }

            foreach ($releases as $release) {
                if (!is_array($release) || ($release['draft'] ?? false) === true) {
                    continue;
                }

                if (!$includePrereleases && ($release['prerelease'] ?? false) === true) {
                    continue;
                }

                $this->items[] = $this->buildItem($release, $owner, $repo, $downloadsMode);

                if (count($this->items) >= $limit) {
                    break;
                }
            }

            if (count($releases) < self::API_LIMIT) {
                break;
            }
        }
    }

    public function getURI(): string
    {
        [$owner, $repo] = $this->repository();

        return $owner !== '' && $repo !== ''
            ? sprintf('%s%s/%s/releases', self::URI, rawurlencode($owner), rawurlencode($repo))
            : self::URI;
    }

    public function getName(): string
    {
        [$owner, $repo] = $this->repository();

        return $owner !== '' && $repo !== ''
            ? sprintf('%s/%s releases', $owner, $repo)
            : self::NAME;
    }

    private function repository(): array
    {
        return [
            trim((string) $this->getInput('owner')),
            trim((string) $this->getInput('repo')),
        ];
    }

    private function validateSlug(string $value): void
    {
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $value)) {
            throw new InvalidArgumentException(
                'Owner and repository may contain letters, digits, dot, dash and underscore only.'
            );
        }
    }

    private function fetchJson(string $url): array
    {
        $headers = ['Accept: application/json', 'User-Agent: RSS-Bridge CodebergReleasesBridge'];
        $data = json_decode((string) getContents($url, $headers), true, 512, JSON_THROW_ON_ERROR);

        return is_array($data) && array_is_list($data)
            ? $data
            : throw new RuntimeException('Codeberg API returned an unexpected response.');
    }

    private function buildItem(array $release, string $owner, string $repo, string $downloadsMode): array
    {
        $tag = (string) ($release['tag_name'] ?? '');
        $name = trim((string) ($release['name'] ?? ''));

        $title = $name === '' || $name === $tag
            ? ($tag !== '' ? $tag : 'Release')
            : ($tag !== '' ? sprintf('%s (%s)', $name, $tag) : $name);

        $uri = $this->absoluteUrl((string) ($release['html_url'] ?? ''));

        if ($uri === '') {
            $uri = sprintf(
                '%s%s/%s/releases/tag/%s',
                self::URI,
                rawurlencode($owner),
                rawurlencode($repo),
                rawurlencode($tag)
            );
        }

        return [
            'uri' => $uri,
            'title' => $title,
            'timestamp' => strtotime((string) ($release['published_at'] ?? $release['created_at'] ?? '')) ?: time(),
            'author' => (string) ($release['author']['login'] ?? $owner),
            'content' => $this->buildContent($release, $downloadsMode) ?: $this->escape($title),
            'enclosures' => $downloadsMode === 'enclosures' ? $this->extractEnclosures($release) : [],
            'categories' => array_values(array_filter([
                $tag !== '' ? $tag : null,
                ($release['prerelease'] ?? false) === true ? 'pre-release' : null,
            ])),
            'uid' => (string) ($release['id'] ?? $uri),
        ];
    }

    private function absoluteUrl(string $url): string
    {
        $url = trim($url);

        return match (true) {
            $url === '' => '',
            str_starts_with($url, 'http://') || str_starts_with($url, 'https://') => $url,
            str_starts_with($url, '//') => 'https:' . $url,
            str_starts_with($url, '/') => rtrim(self::URI, '/') . $url,
            default => self::URI . $url,
        };
    }

    private function extractEnclosures(array $release): array
    {
        $assets = array_filter((array) ($release['assets'] ?? []), is_array(...));
        $urls = array_map(
            fn(array $asset): string => $this->absoluteUrl((string) ($asset['browser_download_url'] ?? '')),
            $assets
        );

        return array_values(array_unique(array_filter($urls)));
    }

    private function buildContent(array $release, string $downloadsMode): string
    {
        $content = '';

        if (($release['prerelease'] ?? false) === true) {
            $content .= '<p><em>This is a pre-release.</em></p>';
        }

        $body = (string) ($release['body'] ?? '');

        if ($body !== '') {
            $content .= sprintf(
                '<h4>Changelog</h4><div>%s</div>',
                nl2br($this->escape($body), false)
            );
        }

        if ($downloadsMode === 'inline') {
            $content .= $this->buildDownloadsHtml($release);
        }

        return $content;
    }

    private function buildDownloadsHtml(array $release): string
    {
        $items = [];

        foreach ((array) ($release['assets'] ?? []) as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            $url = $this->absoluteUrl((string) ($asset['browser_download_url'] ?? ''));

            if ($url === '') {
                continue;
            }

            $name = trim((string) ($asset['name'] ?? ''));

            if ($name === '') {
                $path = parse_url($url, PHP_URL_PATH);
                $name = is_string($path) ? basename($path) : '';
            }

            $meta = [];

            if (isset($asset['size'])) {
                $meta[] = $this->formatBytes((float) $asset['size']);
            }

            if (isset($asset['download_count'])) {
                $meta[] = sprintf('%s downloads', number_format((int) $asset['download_count']));
            }

            $items[] = sprintf(
                '<li><a href="%s">%s</a>%s</li>',
                $this->escape($url),
                $this->escape($name !== '' ? $name : 'download'),
                $meta !== [] ? ' <small>(' . $this->escape(implode(', ', $meta)) . ')</small>' : ''
            );
        }

        return $items === [] ? '' : '<h4>Downloads</h4><ul>' . implode('', $items) . '</ul>';
    }

    private function formatBytes(float $bytes): string
    {
        if ($bytes <= 0.0) {
            return '0 B';
        }

        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $index = max(0, min((int) floor(log($bytes, 1024)), count($units) - 1));
        $value = $bytes / (1024 ** $index);

        return sprintf(
            '%s %s',
            number_format($value, $index === 0 || $value >= 100.0 ? 0 : 1, '.', ''),
            $units[$index]
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}