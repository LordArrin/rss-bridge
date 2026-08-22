<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

use function urljoin;

final class NikonDownloadCenterBridge extends BridgeAbstract
{
    public const NAME = 'Nikon Download Center';
    public const URI = 'https://downloadcenter.nikonimglib.com/';
    public const DESCRIPTION = 'Firmware updates and new software from Nikon.';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 7200;

    public function getURI(): string
    {
        $year = date('Y');
        return self::URI . 'en/update/index/' . $year . '.html';
    }

    public function getIcon(): string
    {
        return self::URI . 'favicon.ico';
    }

    public function collectData(): void
    {
        $html = $this->fetchHtml($this->getURI());

        $items = $html->querySelectorAll('dd > ul > li');

        foreach ($items as $element) {
            $dateNode = $element->querySelector('.date');
            $productIcon = $element->querySelector('.icon > img');
            $linkNode = $element->querySelector('p > a');

            if ($dateNode === null || $productIcon === null || $linkNode === null) {
                continue;
            }

            $date = trim($dateNode->textContent);
            $productType = $productIcon->getAttribute('alt');
            $desc = trim($linkNode->textContent);
            $href = $linkNode->getAttribute('href');

            if ($productType === null) {
                $productType = 'product';
            }

            if ($href === null || $href === '') {
                continue;
            }

            $link = urljoin(self::URI, $href);

            $timestamp = strtotime($date);
            if ($timestamp === false) {
                $timestamp = time();
            }

            $content = '<p>';
            $content .= 'New/updated ' . htmlspecialchars($productType, ENT_QUOTES, 'UTF-8') . ':<br>';
            $content .= '<strong><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">';
            $content .= htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') . '</a></strong>';
            $content .= '</p>';

            $this->items[] = [
                'title' => $desc,
                'uri' => $link,
                'timestamp' => $timestamp,
                'content' => $content,
                'uid' => md5($link),
            ];
        }

        if ($this->items === []) {
            throwServerException('No items found');
        }
    }

    private function fetchHtml(string $url): \Dom\HTMLDocument
    {
        $html = getContents($url);

        if ($html === '') {
            throwServerException('Failed to fetch ' . $url);
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        return $dom;
    }
}
