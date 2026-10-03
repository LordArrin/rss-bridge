<?php

declare(strict_types=1);

namespace RSSBridge\Formats;

use RSSBridge\Configuration;

/**
 * HTML format for displaying feed items in browser.
 */
final class HtmlFormat extends FormatAbstract
{
    public const MIME_TYPE = 'text/html';

    public function getMimeType(): string
    {
        return self::MIME_TYPE;
    }

    public function render(): string
    {
        $bridgeName = $_GET['bridge'] ?? 'Unknown';

        $feedArray = $this->getFeed();

        // Create links to other formats. Rebuild the query string from the
        // actual GET parameters instead of doing a blind str_ireplace on it:
        // the old code only matched the literal "format=Html", so any URL
        // whose format parameter had a different spelling or value (or was
        // absent) produced broken switcher links, and it could also corrupt
        // unrelated parameters that merely contained that substring.
        $formats = [];
        $formatNames = ['Atom', 'Mrss', 'Json', 'Plaintext', 'Sfeed'];

        foreach ($formatNames as $formatName) {
            $formats[] = [
                'url'  => $this->buildFormatUrl($formatName),
                'name' => $formatName,
                'type' => $this->getMimeTypeForFormat($formatName),
            ];
        }

        $items = [];
        foreach ($this->getItems() as $item) {
            $items[] = [
                'url'        => (bool) $item->getURI() === true ? $item->getURI() : ($feedArray['uri'] ?? ''),
                'title'      => $item->getTitle() ?? '(no title)',
                'timestamp'  => $item->getTimestamp(),
                'author'     => $item->getAuthor(),
                'content'    => $item->getContent() ?? '',
                'enclosures' => $item->getEnclosures(),
                'categories' => $item->getCategories(),
            ];
        }

        return render_template(__DIR__ . '/../templates/html-format.html.php', [
            'bridge_name'  => $bridgeName,
            'title'        => $feedArray['name'] ?? '',
            'formats'      => $formats,
            'uri'          => $feedArray['uri'] ?? '',
            'items'        => $items,
        ]);
    }

    private function getMimeTypeForFormat(string $formatName): string
    {
        return match ($formatName) {
            'Atom' => 'application/atom+xml',
            'Mrss' => 'application/rss+xml',
            'Json' => 'application/json',
            'Plaintext' => 'text/plain',
            'Sfeed' => 'text/plain',
            default => 'application/octet-stream',
        };
    }

    /**
     * Build a URL for the current request with the format parameter replaced.
     * All other parameters (including the auth token, when configured to be
     * passed via query string) are preserved and properly re-encoded.
     */
    private function buildFormatUrl(string $formatName): string
    {
        $params = $_GET;
        // Remove the existing format key case-insensitively so we never end
        // up with two conflicting "format" entries after re-adding it.
        foreach (array_keys($params) as $key) {
            if (strtolower((string) $key) === 'format') {
                unset($params[$key]);
            }
        }
        $params['format'] = $formatName;
        return '?' . http_build_query($params);
    }
}
