<?php

declare(strict_types=1);

namespace RSSBridge\Formats;

use Configuration;

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
        $queryString = $_SERVER['QUERY_STRING'] ?? '';
        $bridgeName = $_GET['bridge'] ?? 'Unknown';

        $feedArray = $this->getFeed();

        // Create links to other formats
        $formats = [];
        $formatNames = ['Atom', 'Mrss', 'Json', 'Plaintext', 'Sfeed'];

        foreach ($formatNames as $formatName) {
            $formatUrl = '?' . str_ireplace('format=Html', 'format=' . $formatName, $queryString);
            $formats[] = [
                'url'  => $formatUrl,
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

        // Donations support is currently disabled
        $donationUri = null;
        // if (Configuration::getConfig('admin', 'donations') && ($feedArray['donationUri'] ?? null)) {
        //     $donationUri = $feedArray['donationUri'];
        // }

        return render_template(__DIR__ . '/../templates/html-format.html.php', [
            'bridge_name'  => $bridgeName,
            'title'        => $feedArray['name'] ?? '',
            'formats'      => $formats,
            'uri'          => $feedArray['uri'] ?? '',
            'items'        => $items,
            // 'donation_uri' => $donationUri,
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
}
