<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class VieDeMerdeBridge extends BridgeAbstract
{
    public const NAME = 'VieDeMerde';
    public const URI = 'https://www.viedemerde.fr';
    public const DESCRIPTION = 'Returns latest quotes from VieDeMerde.';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 7200;

    private const ITEM_LIMIT = 10;

    public function collectData(): void
    {
        $htmlString = getContents(self::URI);

        if ($htmlString === '') {
            throwServerException('Failed to fetch content from VieDeMerde');
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($htmlString);
        libxml_use_internal_errors(false);

        $quotes = $dom->querySelectorAll('article.bg-white');

        foreach ($quotes as $quote) {
            $item = $this->extractQuoteItem($quote);

            if ($item === null) {
                continue;
            }

            $this->items[] = $item;

            if (count($this->items) >= self::ITEM_LIMIT) {
                break;
            }
        }

        if ($this->items === []) {
            throwServerException('No quotes could be extracted');
        }
    }

    private function extractQuoteItem(\Dom\Element $quote): ?array
    {
        $titleNode = $quote->querySelector('h2');
        if ($titleNode === null) {
            return null;
        }

        $links = $quote->querySelectorAll('a');
        if (count($links) < 2) {
            return null;
        }

        $linkNode = $links[0];
        $quoteTextNode = $links[1];

        $href = $linkNode->getAttribute('href');
        if ($href === null || $href === '') {
            return null;
        }

        $uri = self::URI . $href;
        $title = html_entity_decode(trim($titleNode->textContent), ENT_QUOTES, 'UTF-8');
        $quoteText = trim($quoteTextNode->textContent);

        $content = htmlspecialchars($quoteText, ENT_QUOTES, 'UTF-8');

        $voteButtons = $quote->querySelectorAll('.vote-btn');
        if (count($voteButtons) >= 1) {
            $content .= '<br>' . htmlspecialchars(trim($voteButtons[0]->textContent), ENT_QUOTES, 'UTF-8');
        }
        if (count($voteButtons) >= 2) {
            $content .= '<br>' . htmlspecialchars(trim($voteButtons[1]->textContent), ENT_QUOTES, 'UTF-8');
        }

        $authorNode = $quote->querySelector('p');
        $author = '';
        if ($authorNode !== null) {
            $author = trim($authorNode->textContent);
        }

        return [
            'uri' => $uri,
            'title' => $title,
            'content' => $content,
            'author' => $author,
            'uid' => hash('sha256', $title),
        ];
    }
}
