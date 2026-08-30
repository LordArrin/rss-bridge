<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class NasaApodBridge extends BridgeAbstract
{
    public const NAME = 'NASA Picture of the Day';
    public const URI = 'https://apod.nasa.gov/apod/';
    public const DESCRIPTION = 'Returns latest Picture of the Day with explanations by NASA';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 43200;

    private const ARCHIVE_PATH = 'archivepix.html';
    private const ITEM_COUNT = 3;

    public function collectData(): void
    {
        $archiveUrl = self::URI . self::ARCHIVE_PATH;
        $archiveHtml = getContents($archiveUrl);

        if ($archiveHtml === '') {
            throwServerException('Failed to fetch APOD archive page');
        }

        libxml_use_internal_errors(true);
        $archiveDom = \Dom\HTMLDocument::createFromString($archiveHtml);
        libxml_use_internal_errors(false);

        $links = $archiveDom->querySelectorAll('a');

        $extracted = 0;
        for ($i = 4; $i < count($links) && $extracted < self::ITEM_COUNT; $i++) {
            $linkNode = $links[$i];
            $href = $linkNode->getAttribute('href');

            if ($href === null || $href === '') {
                continue;
            }

            $uri = self::URI . $href;
            $item = $this->extractApodItem($uri);

            if ($item === null) {
                continue;
            }

            $this->items[] = $item;
            $extracted++;
        }

        if ($this->items === []) {
            throwServerException('No APOD entries could be extracted');
        }
    }

    private function extractApodItem(string $uri): ?array
    {
        $htmlString = getContents($uri);

        if ($htmlString === '') {
            return null;
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($htmlString);
        libxml_use_internal_errors(false);

        $title = $this->extractTitle($dom);
        if ($title === '') {
            return null;
        }

        $imageHtml = $this->extractImageHtml($dom);

        $timestamp = $this->extractTimestamp($dom);

        $explanation = $this->extractExplanation($dom);

        $content = '';
        if ($imageHtml !== '') {
            $content .= $imageHtml;
        }
        if ($explanation !== '') {
            if ($content !== '') {
                $content .= '<p></p>';
            }
            $content .= $explanation;
        }

        if ($content === '') {
            $content = '<p>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        return [
            'uri' => $uri,
            'title' => $title,
            'content' => $content,
            'timestamp' => $timestamp,
            'uid' => md5($uri),
        ];
    }

    private function extractTitle(\Dom\HTMLDocument $dom): string
    {
        $centers = $dom->querySelectorAll('center');

        if (count($centers) >= 2) {
            $titleNode = $centers[1]->querySelector('b');
            if ($titleNode !== null) {
                $title = trim($titleNode->textContent);
                if ($title !== '') {
                    return $title;
                }
            }
        }

        $titleNode = $dom->querySelector('b');
        if ($titleNode !== null) {
            return trim($titleNode->textContent);
        }

        return '';
    }

    private function extractTimestamp(\Dom\HTMLDocument $dom): ?int
    {
        $paragraphs = $dom->querySelectorAll('p');

        foreach ($paragraphs as $p) {
            // Iterate through all child nodes of the <p> element
            foreach ($p->childNodes as $node) {
                // Only process text nodes (nodeType 3)
                if ($node->nodeType === 3) {
                    $text = trim($node->textContent);

                    // Match pattern like "2026 August 20"
                    if (preg_match('/^(\d{4})\s+([A-Za-z]+)\s+(\d{1,2})$/', $text, $matches) === 1) {
                        $dateString = $matches[1] . ' ' . $matches[2] . ' ' . $matches[3];
                        $parsed = strtotime($dateString);
                        if ($parsed !== false) {
                            return $parsed;
                        }
                    }
                }
            }
        }

        return null;
    }

    private function extractImageHtml(\Dom\HTMLDocument $dom): string
    {
        $imageWrapper = $dom->querySelector('a[href*="image/"], a[href*=".jpg"], a[href*=".png"], a[href*=".gif"], a[href*=".jpeg"]');
        if ($imageWrapper === null) {
            return '';
        }

        $imagePath = $imageWrapper->getAttribute('href');
        if ($imagePath === null || $imagePath === '') {
            return '';
        }

        $imageUri = self::URI . $imagePath;
        $imgNode = $imageWrapper->querySelector('img');

        if ($imgNode !== null) {
            $imgAlt = htmlspecialchars((string) ($imgNode->getAttribute('alt') ?? ''), ENT_QUOTES, 'UTF-8');
            $imgStyle = htmlspecialchars((string) ($imgNode->getAttribute('style') ?? ''), ENT_QUOTES, 'UTF-8');
            $safeImageUri = htmlspecialchars($imageUri, ENT_QUOTES, 'UTF-8');

            $imgTag = '<img src="' . $safeImageUri . '" alt="' . $imgAlt . '"';
            if ($imgStyle !== '') {
                $imgTag .= ' style="' . $imgStyle . '"';
            }
            $imgTag .= '>';

            return '<a href="' . $safeImageUri . '">' . $imgTag . '</a>';
        }

        return $imageWrapper->outerHTML;
    }

    private function extractExplanation(\Dom\HTMLDocument $dom): string
    {
        $paragraphs = $dom->querySelectorAll('p');

        foreach ($paragraphs as $p) {
            $boldTags = $p->querySelectorAll('b');

            foreach ($boldTags as $b) {
                $bText = trim($b->textContent);
                if (strcasecmp($bText, 'Explanation:') === 0) {
                    $b->remove();

                    $html = $p->innerHTML;
                    $html = preg_replace('/^\s*(<br\s*\/?>\s*)*/i', '', $html) ?? $html;

                    return trim($html);
                }
            }
        }

        return '';
    }
}
