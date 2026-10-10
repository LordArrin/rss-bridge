<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;
use RSSBridge\GithubClient;

use function RSSBridge\Exceptions\throwServerException;

final class GitHubReleaseBridge extends BridgeAbstract
{
    public const NAME = 'GitHub Releases';
    public const URI = 'https://github.com';
    public const DESCRIPTION = 'Returns releases for a GitHub repository (excluding tag-only entries)';
    public const MAINTAINER = 'LordArrin';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [[
        'owner' => [
            'name' => 'Owner',
            'type' => 'text',
            'required' => true,
            'exampleValue' => 'immich-app',
        ],
        'repo' => [
            'name' => 'Repository',
            'type' => 'text',
            'required' => true,
            'exampleValue' => 'immich',
        ],
        'pre_release' => [
            'name' => 'Include pre-releases',
            'type' => 'checkbox',
            'defaultValue' => false,
        ],
        'hide_assets' => [
            'name' => 'Hide attachments',
            'type' => 'checkbox',
            'defaultValue' => false,
        ],
        'limit' => [
            'name' => 'Posts limit',
            'type' => 'number',
            'defaultValue' => 10,
        ],
    ]];

    private const ALLOWED_TAGS = [
        'div', 'a', 'p', 'ul', 'ol', 'li', 'strong', 'em', 'code', 'pre', 'blockquote', 'span',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'br', 'hr', 'img', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'picture', 'source', 'figure', 'figcaption', 'del', 'details', 'summary', 'svg', 'path',
    ];

    private const CSS = [
        'wrapper' => 'font-size:14px; line-height:1.6; word-wrap:break-word;',
        'alert_base' => 'padding:8px 16px; margin:16px 0; border-radius:6px;',
        'alert_title' => 'font-weight:600; margin-bottom:4px; display:flex; align-items:center; gap:8px;',
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

    public function collectData(): void
    {
        $owner = (string) $this->getInput('owner');
        $repo = (string) $this->getInput('repo');
        $includePrereleases = (bool) $this->getInput('pre_release');
        $hideAssets = (bool) $this->getInput('hide_assets');
        $limitInput = $this->getInput('limit');
        $limit = max(1, min(self::MAX_LIMIT, (int) ($limitInput !== null ? $limitInput : 10)));

        $client = new GithubClient($this->cache, $this->logger);

        try {
            $releases = $client->fetchReleases($owner, $repo);
        } catch (\Exception $e) {
            throwServerException($e->getMessage());
        }

        foreach ($releases as $release) {
            if (count($this->items) >= $limit) {
                break;
            }

            if ($this->shouldSkipRelease($release, $includePrereleases) === true) {
                continue;
            }

            $this->items[] = $this->buildReleaseItem($release, $owner, $repo, $hideAssets);
        }

        if ($this->items === []) {
            throwServerException('No releases found.');
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

    public function detectParameters(string $url): ?array
    {
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

    private function buildReleaseItem(array $release, string $owner, string $repo, bool $hideAssets): array
    {
        $name = $release['name'] ?? '';
        $tagName = $release['tag_name'] ?? '';
        $title = $name !== '' ? $name : ($tagName !== '' ? $tagName : 'Untitled');

        $body = $release['body'] ?? '';
        if ($body === '' && $tagName !== '') {
            $body = $this->fetchTagCommitMessage($owner, $repo, $tagName);
        }

        $content = $body !== '' ? $this->processMarkdown((string) $body, $owner, $repo) : '';

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
            'uid' => $tagName !== '' ? $tagName : (string) ($release['id'] ?? uniqid()),
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
            $size = $this->formatFileSize((int) ($asset['size'] ?? 0));
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
        $size = (float) $bytes;

        while ($size >= 1024 && $index < count(self::FILE_UNITS) - 1) {
            $size /= 1024;
            $index++;
        }

        return round($size, 2) . ' ' . self::FILE_UNITS[$index];
    }

    private function fetchTagCommitMessage(string $owner, string $repo, string $tagName): string
    {
        $client = new GithubClient($this->cache, $this->logger);
        return $client->fetchTagCommitMessage($owner, $repo, $tagName);
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
        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString('<div id="w">' . $html . '</div>');
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        $xpath = new \Dom\XPath($dom);

        $this->transformAlerts($xpath);
        $this->shortenAutoLinks($xpath, $owner, $repo);
        $this->applyListStyles($xpath);
        $this->sanitizeHtml($xpath);

        $wrapper = $dom->getElementById('w');
        if ($wrapper === null) {
            return '';
        }

        $content = $dom->saveHtml($wrapper);
        $c1 = preg_replace('#^\s*<div[^>]*>#', '', $content);
        $content = $c1 !== null ? $c1 : $content;
        $c2 = preg_replace('#</div>\s*$#', '', $content);
        $content = $c2 !== null ? $c2 : $content;

        $allowedTags = '<' . implode('><', self::ALLOWED_TAGS) . '>';
        $content = strip_tags(trim($content), $allowedTags);

        return sprintf('<div style="%s">%s</div>', self::CSS['wrapper'], $content);
    }

    private function sanitizeHtml(\Dom\XPath $xpath): void
    {
        $nodes = $xpath->query('//*');
        if ($nodes === false) {
            return;
        }

        foreach ($nodes as $node) {
            if ($node instanceof \Dom\Element === false) {
                continue;
            }

            $attrsToRemove = [];

            foreach ($node->attributes as $attr) {
                $name = strtolower($attr->name ?? '');
                $value = strtolower(trim($attr->value ?? ''));

                if (str_starts_with($name, 'on') === true) {
                    $attrsToRemove[] = $attr->name ?? '';
                    continue;
                }

                if (in_array($name, ['href', 'src', 'action', 'formaction', 'xlink:href'], true) === true) {
                    if (preg_match('/^\s*(javascript|vbscript|data(?!:image\/))/i', $value) === 1) {
                        $attrsToRemove[] = $attr->name ?? '';
                    }
                }
            }

            foreach ($attrsToRemove as $attrName) {
                if ($attrName !== '') {
                    $node->removeAttribute($attrName);
                }
            }

            foreach (['src', 'href'] as $attr) {
                if ($node->hasAttribute($attr) === true) {
                    $value = $node->getAttribute($attr) ?? '';
                    if (str_starts_with($value, '/') === true) {
                        $node->setAttribute($attr, self::URI . $value);
                    }
                }
            }

            if ($node->hasAttribute('srcset') === true) {
                $srcset = $node->getAttribute('srcset') ?? '';
                $result = preg_replace('#(^|[\s,])(/[^,\s]+)#', '$1' . self::URI . '$2', $srcset);
                if ($result !== null) {
                    $node->setAttribute('srcset', $result);
                }
            }
        }
    }

    private function transformAlerts(\Dom\XPath $xpath): void
    {
        $alerts = $xpath->query('//div[contains(@class, "markdown-alert")]');
        if ($alerts === false) {
            return;
        }

        foreach ($alerts as $alert) {
            if ($alert instanceof \Dom\Element === false) {
                continue;
            }

            $alertType = $this->extractAlertType($alert);
            if ($alertType === null) {
                continue;
            }

            $color = self::CSS['alerts'][$alertType] ?? '#666';

            $existing = $alert->getAttribute('style') ?? '';
            $alertStyle = sprintf(
                'border-left:4px solid %s; background-color:%s1a; %s',
                $color,
                $color,
                self::CSS['alert_base']
            );
            $style = trim(($existing !== '' ? $existing . ' ' : '') . $alertStyle);
            $alert->setAttribute('style', $style);

            $titleNode = $alert->querySelector('p.markdown-alert-title');
            if ($titleNode !== null && $titleNode instanceof \Dom\Element) {
                $titleExisting = $titleNode->getAttribute('style') ?? '';
                $titleStyle = sprintf('color:%s; %s', $color, self::CSS['alert_title']);
                $titleFull = trim(($titleExisting !== '' ? $titleExisting . ' ' : '') . $titleStyle);
                $titleNode->setAttribute('style', $titleFull);
            }
        }
    }

    private function extractAlertType(\Dom\Element $alert): ?string
    {
        $className = $alert->getAttribute('class') ?? '';

        foreach (array_keys(self::CSS['alerts']) as $type) {
            if (str_contains($className, 'markdown-alert-' . strtolower($type)) === true) {
                return $type;
            }
        }

        return null;
    }

    private function shortenAutoLinks(\Dom\XPath $xpath, string $owner, string $repo): void
    {
        $ownerQuoted = preg_quote($owner, '~');
        $repoQuoted = preg_quote($repo, '~');

        $links = $xpath->query('//a[@href]');
        if ($links === false) {
            return;
        }

        foreach ($links as $link) {
            if ($link instanceof \Dom\Element === false) {
                continue;
            }

            $href = $link->getAttribute('href') ?? '';
            $text = trim($link->textContent ?? '');

            if ($text !== $href) {
                continue;
            }

            $replacement = null;

            if (preg_match('~^' . preg_quote(self::URI, '~') . '/' . $ownerQuoted . '/' . $repoQuoted . '/(?:issues|pull)/(\d+)(?:[/?#].*)?$~i', $href, $matches) === 1) {
                $replacement = '#' . $matches[1];
            } elseif (preg_match('~^' . preg_quote(self::URI, '~') . '/([^/]+)/([^/]+)/(?:issues|pull)/(\d+)(?:[/?#].*)?$~i', $href, $matches) === 1) {
                $replacement = $matches[1] . '/' . $matches[2] . '#' . $matches[3];
            } elseif (preg_match('~^' . preg_quote(self::URI, '~') . '/([a-zA-Z0-9](?:[a-zA-Z0-9]|-(?=[a-zA-Z0-9])){0,38})$~', $href, $matches) === 1) {
                $replacement = '@' . $matches[1];
            }

            if ($replacement !== null) {
                $this->replaceElementText($link, $replacement);
            }
        }
    }

    private function replaceElementText(\Dom\Element $element, string $text): void
    {
        while ($element->firstChild !== null) {
            $element->removeChild($element->firstChild);
        }
        $element->appendChild($element->ownerDocument->createTextNode($text));
    }

    private function applyListStyles(\Dom\XPath $xpath): void
    {
        $lists = $xpath->query('//ul | //ol');
        if ($lists === false) {
            return;
        }

        foreach ($lists as $list) {
            if ($list instanceof \Dom\Element === false) {
                continue;
            }

            $existing = $list->getAttribute('style') ?? '';
            $newStyle = $list->localName === 'ul' ? self::CSS['ul'] : self::CSS['ol'];
            $list->setAttribute('style', trim(($existing !== '' ? $existing . ' ' : '') . $newStyle));
        }
    }
}
