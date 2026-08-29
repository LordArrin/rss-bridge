<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;
use RSSBridge\FeedItem;

final class GitHubReleaseBridge extends BridgeAbstract
{
    public const NAME = 'GitHub Releases';
    public const URI = 'https://github.com';
    public const DESCRIPTION = 'Returns releases for a GitHub repository (excluding tag-only entries)';
    public const MAINTAINER = 'LordArrin';
    public const CACHE_TIMEOUT = 3600;

    public const CONFIGURATION = ['token' => ['required' => false]];

    public const PARAMETERS = [[
        'owner' => [
            'name' => 'Owner',
            'type' => 'text',
            'required' => true,
            'exampleValue' => 'immich-app',
            'title' => 'The name of the repo owner (e.g. immich-app from https://github.com/immich-app/immich)'
        ],
        'repo' => [
            'name' => 'Repository',
            'type' => 'text',
            'required' => true,
            'exampleValue' => 'immich',
            'title' => 'Repo name (e.g. immich from https://github.com/immich-app/immich)'
        ],
        'pre_release' => [
            'name' => 'Include pre-releases',
            'type' => 'checkbox',
            'title' => 'Check this box to include pre-releases in the feed'
        ],
        'hide_assets' => [
            'name' => 'Hide attachments',
            'type' => 'checkbox',
            'title' => 'Check this box to hide attachments from feed items.'
        ],
        'limit' => [
            'name' => 'Posts limit',
            'type' => 'number',
            'defaultValue' => 10
        ],
    ]];

    private const ALLOWED_TAGS = [
        'div', 'a', 'p', 'ul', 'ol', 'li', 'strong', 'em', 'code', 'pre', 'blockquote', 'span',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'br', 'hr', 'img', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'picture', 'source', 'figure', 'figcaption', 'del', 'details', 'summary'
    ];

    private const ERROR_MESSAGES = [
        401 => 'Auth failed',
        403 => 'Rate limit exceeded',
        404 => 'Repo not found',
    ];

    private const CSS = [
        'wrapper' => 'font-size:14px; line-height:1.6; word-wrap:break-word;',
        'alert_base' => 'padding-left:12px; margin:8px 0;',
        'ul' => 'list-style-type:disc; padding-left:24px;',
        'ol' => 'list-style-type:decimal; padding-left:24px;',
        'alerts' => [
            'NOTE' => '#0969da',
            'TIP' => '#1a7f37',
            'IMPORTANT' => '#8250df',
            'WARNING' => '#9a6700',
            'CAUTION' => '#cf222e',
        ],
    ];

    private const FILE_UNITS = ['B', 'KB', 'MB', 'GB', 'TB'];
    private const MAX_LIMIT = 100;
    private const DEFAULT_LIMIT = 10;

    public function collectData(): void
    {
        $owner = (string)$this->getInput('owner');
        $repo = (string)$this->getInput('repo');
        $includePrereleases = (bool)$this->getInput('pre_release');
        $hideAssets = (bool)$this->getInput('hide_assets');
        $limitInput = $this->getInput('limit');
        $limit = max(1, min(self::MAX_LIMIT, (int)($limitInput !== null ? $limitInput : self::DEFAULT_LIMIT)));

        $releases = $this->fetchReleases($owner, $repo);

        foreach ($releases as $release) {
            if (count($this->items) >= $limit) {
                break;
            }

            if ($this->shouldSkipRelease($release, $includePrereleases) === true) {
                continue;
            }

            $this->items[] = $this->buildFeedItem($release, $owner, $repo, $hideAssets);
        }
    }

    public function getName(): string
    {
        $owner = $this->getInput('owner');
        $repo = $this->getInput('repo');

        if (is_string($owner) === true && $owner !== '' && is_string($repo) === true && $repo !== '') {
            return sprintf('%s/%s - Releases', $owner, $repo);
        }

        return parent::getName();
    }

    public function getURI(): string
    {
        $owner = $this->getInput('owner');
        $repo = $this->getInput('repo');

        if (is_string($owner) === true && $owner !== '' && is_string($repo) === true && $repo !== '') {
            return sprintf('%s/%s/%s/releases', self::URI, $owner, $repo);
        }

        return parent::getURI();
    }

    public function detectParameters($url): ?array
    {
        if (is_string($url) === false) {
            return null;
        }

        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';
        $path = $parsed['path'] ?? '';

        $uriHost = parse_url(self::URI, PHP_URL_HOST);
        if ($host !== $uriHost && $host !== 'www.' . $uriHost) {
            return null;
        }

        if (preg_match('#^/([^/]+)/([^/]+?)(?:/(?:releases|tags))?/?$#', $path, $matches) === 1) {
            return ['owner' => $matches[1], 'repo' => $matches[2]];
        }

        return null;
    }

    private function fetchReleases(string $owner, string $repo): array
    {
        $url = sprintf('https://api.%s/repos/%s/%s/releases?per_page=100', parse_url(self::URI, PHP_URL_HOST), $owner, $repo);
        $headers = ['Accept: application/vnd.github+json'];

        $token = $this->getOption('token');
        if ($token !== null && $token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        try {
            $response = json_decode(getContents($url, $headers), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Exception $e) {
            $code = (int)$e->getCode();
            $message = self::ERROR_MESSAGES[$code] ?? 'GitHub API error: ' . $e->getMessage();
            throwServerException($message);
        }

        if (is_array($response) === false) {
            throwServerException('Invalid response from GitHub API');
        }

        if ($response !== [] && isset($response[0]) === false) {
            $errorMsg = $response['message'] ?? 'Unknown API error';
            throwServerException('GitHub API error: ' . $errorMsg);
        }

        return $response;
    }

    private function shouldSkipRelease(array $release, bool $includePrereleases): bool
    {
        if (($release['draft'] ?? false) === true) {
            return true;
        }

        if (($release['prerelease'] ?? false) === true && $includePrereleases === false) {
            return true;
        }

        return false;
    }

    private function buildFeedItem(array $release, string $owner, string $repo, bool $hideAssets): array
    {
        $name = $release['name'] ?? '';
        $tagName = $release['tag_name'] ?? '';
        $title = $name !== '' ? $name : ($tagName !== '' ? $tagName : 'Untitled');

        $body = $release['body'] ?? '';
        $content = $body !== '' ? $this->processMarkdown((string)$body, $owner, $repo) : '';

        if ($hideAssets === false && isset($release['assets']) === true && is_array($release['assets']) === true) {
            $assetsHtml = $this->buildAssetsBlock($release['assets']);
            if ($assetsHtml !== '') {
                $content .= $assetsHtml;
            }
        }

        $dateStr = $release['published_at'] ?? $release['created_at'] ?? '';
        $timestamp = $dateStr !== '' ? strtotime($dateStr) : false;
        if ($timestamp === false) {
            $timestamp = time();
        }

        return [
            'title' => $title,
            'uri' => $release['html_url'] ?? '',
            'content' => $content,
            'timestamp' => $timestamp,
            'author' => $release['author']['login'] ?? '',
            'uid' => $tagName !== '' ? $tagName : (string)($release['id'] ?? uniqid()),
            'enclosures' => [],
            'categories' => [$tagName !== '' ? $tagName : ''],
        ];
    }

    private function buildAssetsBlock(array $assets): string
    {
        $links = [];

        foreach ($assets as $asset) {
            $url = $asset['browser_download_url'] ?? '';
            $name = $asset['name'] ?? '';

            if ($url === '' || $name === '') {
                continue;
            }

            $url = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            $name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
            $size = $this->formatFileSize((int)($asset['size'] ?? 0));
            $label = $size !== '' ? "{$name} ({$size})" : $name;

            $links[] = "<li><a href=\"{$url}\">{$label}</a></li>";
        }

        if ($links === []) {
            return '';
        }

        return '<h3>Downloads</h3><ul style="' . self::CSS['ul'] . '">' . implode('', $links) . '</ul>';
    }

    private function formatFileSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '';
        }

        $index = 0;
        $size = (float)$bytes;

        while ($size >= 1024 && $index < count(self::FILE_UNITS) - 1) {
            $size /= 1024;
            $index++;
        }

        return round($size, 2) . ' ' . self::FILE_UNITS[$index];
    }

    private function processMarkdown(string $markdown, string $owner, string $repo): string
    {
        $markdown = $this->enrichMarkdownMentions($markdown, $owner, $repo);

        $parsedown = new \Parsedown();
        $parsedown->setSafeMode(false);
        $parsedown->setMarkupEscaped(false);

        $html = $parsedown->text($markdown);

        return $this->processHtml($html, $owner, $repo);
    }

    private function enrichMarkdownMentions(string $markdown, string $owner, string $repo): string
    {
        $repoUrl = sprintf('%s/%s/%s/issues', self::URI, rawurlencode($owner), rawurlencode($repo));

        $markdown = preg_replace(
            '/(?<!\w)@([a-zA-Z0-9](?:[a-zA-Z0-9]|-(?=[a-zA-Z0-9])){0,38})(?!\w)/',
            '[@$1](' . self::URI . '/$1)',
            $markdown
        );

        $markdown = preg_replace(
            '/(?<!\w)#(\d+)(?!\w)/',
            '[#$1](' . $repoUrl . '/$1)',
            $markdown
        );

        $result = preg_replace('/:[a-zA-Z0-9_+\-]+:/', '', $markdown);
        return $result !== null ? $result : $markdown;
    }

    private function processHtml(string $html, string $owner, string $repo): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="w">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        $this->transformAlerts($xpath);
        $this->shortenAutoLinks($xpath, $owner, $repo);
        $this->applyListStyles($xpath);
        $this->sanitizeHtml($xpath);

        $wrapper = $dom->getElementById('w');
        if ($wrapper === null) {
            return '';
        }

        $content = $dom->saveHTML($wrapper);
        $content = preg_replace('#^\s*<div[^>]*>#', '', $content);
        $content = preg_replace('#</div>\s*$#', '', $content);

        $allowedTags = '<' . implode('><', self::ALLOWED_TAGS) . '>';
        $content = strip_tags(trim($content), $allowedTags);

        return sprintf('<div style="%s">%s</div>', self::CSS['wrapper'], $content);
    }

    private function sanitizeHtml(\DOMXPath $xpath): void
    {
        $nodes = $xpath->query('//*');
        if ($nodes === false) {
            return;
        }

        foreach ($nodes as $node) {
            $attrsToRemove = [];

            foreach ($node->attributes as $attr) {
                $name = strtolower($attr->nodeName);
                $value = strtolower(trim($attr->nodeValue));

                if (str_starts_with($name, 'on') === true) {
                    $attrsToRemove[] = $attr->nodeName;
                    continue;
                }

                if (in_array($name, ['href', 'src', 'action', 'formaction', 'xlink:href'], true) === true) {
                    if (preg_match('/^\s*(javascript|vbscript|data(?!:image\/))/i', $value) === 1) {
                        $attrsToRemove[] = $attr->nodeName;
                    }
                }
            }

            foreach ($attrsToRemove as $attrName) {
                $node->removeAttribute($attrName);
            }

            foreach (['src', 'href'] as $attr) {
                if ($node->hasAttribute($attr) === true) {
                    $value = $node->getAttribute($attr);
                    if (str_starts_with($value, '/') === true) {
                        $node->setAttribute($attr, self::URI . $value);
                    }
                }
            }

            if ($node->hasAttribute('srcset') === true) {
                $srcset = $node->getAttribute('srcset');
                $result = preg_replace('#(^|[\s,])(/[^,\s]+)#', '$1' . self::URI . '$2', $srcset);
                if ($result !== null) {
                    $node->setAttribute('srcset', $result);
                }
            }
        }
    }

    private function transformAlerts(\DOMXPath $xpath): void
    {
        $types = array_keys(self::CSS['alerts']);
        $pattern = '/\[!(' . implode('|', $types) . ')\]\s*/i';

        $blockquotes = $xpath->query('//blockquote');
        if ($blockquotes === false) {
            return;
        }

        foreach ($blockquotes as $bq) {
            $found = $this->detectAlertType($xpath, $bq, $pattern);

            if ($found === null) {
                continue;
            }

            $this->removeAlertMarkers($xpath, $bq, $pattern);

            $color = self::CSS['alerts'][strtoupper($found)];

            $existing = $bq->getAttribute('style');
            $borderStyle = sprintf('border-left:4px solid %s;', $color);
            $style = trim(($existing !== '' ? $existing . ' ' : '') . $borderStyle . ' ' . self::CSS['alert_base']);
            $bq->setAttribute('style', $style);
        }
    }

    private function detectAlertType(\DOMXPath $xpath, \DOMElement $bq, string $pattern): ?string
    {
        $textNodes = $xpath->query('.//text()', $bq);
        if ($textNodes !== false) {
            foreach ($textNodes as $node) {
                if (preg_match($pattern, $node->nodeValue, $matches) === 1) {
                    return strtoupper($matches[1]);
                }
            }
        }

        $strongs = $xpath->query('.//strong', $bq);
        if ($strongs !== false) {
            foreach ($strongs as $strong) {
                $text = strtolower(trim($strong->textContent));
                $types = array_map('strtolower', array_keys(self::CSS['alerts']));

                if (in_array($text, $types, true) === true) {
                    if ($strong->parentNode !== null) {
                        $strong->parentNode->removeChild($strong);
                    }
                    return strtoupper($text);
                }
            }
        }

        return null;
    }

    private function removeAlertMarkers(\DOMXPath $xpath, \DOMElement $bq, string $pattern): void
    {
        $textNodes = $xpath->query('.//text()', $bq);
        if ($textNodes === false) {
            return;
        }

        foreach ($textNodes as $node) {
            if (preg_match($pattern, $node->nodeValue) === 1) {
                $result = preg_replace($pattern, '', $node->nodeValue);
                if ($result !== null) {
                    $node->nodeValue = $result;
                }
            }
        }
    }

    private function shortenAutoLinks(\DOMXPath $xpath, string $owner, string $repo): void
    {
        $ownerQuoted = preg_quote($owner, '~');
        $repoQuoted = preg_quote($repo, '~');

        $links = $xpath->query('//a[@href]');
        if ($links === false) {
            return;
        }

        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            $text = trim($link->textContent);

            if ($text !== $href) {
                continue;
            }

            if (preg_match('~^' . preg_quote(self::URI, '~') . '/' . $ownerQuoted . '/' . $repoQuoted . '/(?:issues|pull)/(\d+)(?:[/?#].*)?$~i', $href, $matches) === 1) {
                $link->nodeValue = '#' . $matches[1];
            } elseif (preg_match('~^' . preg_quote(self::URI, '~') . '/([^/]+)/([^/]+)/(?:issues|pull)/(\d+)(?:[/?#].*)?$~i', $href, $matches) === 1) {
                $link->nodeValue = $matches[1] . '/' . $matches[2] . '#' . $matches[3];
            } elseif (preg_match('~^' . preg_quote(self::URI, '~') . '/([a-zA-Z0-9](?:[a-zA-Z0-9]|-(?=[a-zA-Z0-9])){0,38})$~', $href, $matches) === 1) {
                $link->nodeValue = '@' . $matches[1];
            }
        }
    }

    private function applyListStyles(\DOMXPath $xpath): void
    {
        $lists = $xpath->query('//ul | //ol');
        if ($lists === false) {
            return;
        }

        foreach ($lists as $list) {
            $existing = $list->getAttribute('style');
            $newStyle = $list->nodeName === 'ul' ? self::CSS['ul'] : self::CSS['ol'];
            $list->setAttribute('style', trim(($existing !== '' ? $existing . ' ' : '') . $newStyle));
        }
    }
}
