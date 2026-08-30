<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

use function urljoin;

final class TestFaktaBridge extends BridgeAbstract
{
    public const NAME = 'Testfakta';
    public const URI = 'https://www.testfakta.se';
    public const DESCRIPTION = 'Letest independent tests by Testfakta';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 18000;

    private const CSS = [
        'img' => 'display: block; float: none; clear: both; max-width: 800px; width: auto; height: auto; margin: 16px 0; padding: 0;',
    ];

    private const SWEDISH_MONTHS = [
        'Jan' => 'Jan',
        'Feb' => 'Feb',
        'Mar' => 'Mar',
        'Apr' => 'Apr',
        'Maj' => 'May',
        'Jun' => 'Jun',
        'Jul' => 'Jul',
        'Aug' => 'Aug',
        'Sep' => 'Sep',
        'Okt' => 'Oct',
        'Nov' => 'Nov',
        'Dec' => 'Dec'
    ];

    public function collectData(): void
    {
        $newsUrl = self::URI . '/sv';
        $html = getContents($newsUrl);

        if (is_string($html) === false || $html === '') {
            \throwServerException('Empty response from Testfakta homepage');
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $rows = $dom->querySelectorAll('.row-container');

        foreach ($rows as $element) {
            if ($element instanceof \Dom\Element === false) {
                continue;
            }

            $titleNode = $element->querySelector('h2');
            $title = ($titleNode !== null) ? trim((string) $titleNode->textContent) : '';

            $categoryNode = $element->querySelector('.red-label');
            $category = ($categoryNode !== null) ? trim((string) $categoryNode->textContent) : '';

            $linkNode = $element->querySelector('a');
            $href = ($linkNode !== null) ? (string) ($linkNode->getAttribute('href') ?? '') : '';
            if ($href === '') {
                continue;
            }
            $url = urljoin(self::URI, $href);

            $figureNode = $element->querySelector('img');
            $figureSrc = ($figureNode !== null) ? (string) ($figureNode->getAttribute('src') ?? '') : '';

            $preambleNode = $element->querySelector('.text');
            $preamble = ($preambleNode !== null) ? trim((string) $preambleNode->textContent) : '';

            $articleHtml = getContents($url);
            if (is_string($articleHtml) === false || $articleHtml === '') {
                continue;
            }

            libxml_use_internal_errors(true);
            $articleDom = \Dom\HTMLDocument::createFromString($articleHtml);
            libxml_use_internal_errors(false);

            $contentNode = $articleDom->querySelector('div.content');
            $articleTextNode = $articleDom->querySelector('article');

            $requestorNode = $articleDom->querySelector('div.uppdrag');
            $requestor = ($requestorNode !== null) ? trim((string) $requestorNode->textContent) : '';

            $authorNode = $articleDom->querySelector('span.name');
            $author = ($authorNode !== null) ? trim((string) $authorNode->textContent) : '';
            $author = $this->cleanAuthorName($author);

            $publishedNode = $articleDom->querySelector('span.created');
            $publishedText = ($publishedNode !== null) ? trim((string) $publishedNode->textContent) : '';
            $publishedText = str_replace('Publicerad: ', '', $publishedText);
            $published = $this->parseSwedishDates($publishedText);

            $content = '';

            if ($figureSrc !== '') {
                $fullFigureSrc = urljoin($url, $figureSrc);
                $content .= '<p><img src="' . htmlspecialchars($fullFigureSrc) . '" style="' . self::CSS['img'] . '" alt="" /></p>';
            }

            if ($category !== '' || $requestor !== '') {
                $content .= '<p><b>' . strtoupper(htmlspecialchars($category)) . '</b>  ' . htmlspecialchars($requestor) . '</p>';
            }

            if ($preamble !== '') {
                $content .= '<p><b><i>' . htmlspecialchars($preamble) . '</i></b></p>';
            }

            if ($articleTextNode !== null) {
                $this->limitImageSize($articleTextNode, $url);
                $articleContent = (string) $articleTextNode->innerHTML;
                if ($articleContent !== '') {
                    $content .= $articleContent;
                }
            }

            $item = [
                'uri' => $url,
                'title' => $title,
                'author' => $author,
                'content' => trim($content),
                'uid' => $url,
            ];

            if ($published !== false && $published > 0) {
                $item['timestamp'] = $published;
            }

            $this->items[] = $item;
        }
    }

    private function cleanAuthorName(string $author): string
    {
        if ($author === '') {
            return '';
        }

        $result = preg_replace('/^Redaktör:\s*/i', '', $author);
        if (is_string($result) === true) {
            return trim($result);
        }
        return $author;
    }

    private function parseSwedishDates(string $dateString): int|false
    {
        if ($dateString === '') {
            return false;
        }

        $result = preg_replace_callback(
            '/\b(' . implode('|', array_keys(self::SWEDISH_MONTHS)) . ')\b/',
            function (array $matches): string {
                return self::SWEDISH_MONTHS[$matches[0]] ?? $matches[0];
            },
            $dateString
        );

        if (is_string($result) === false) {
            return false;
        }

        $dateValue = \DateTime::createFromFormat(
            'd M, Y',
            trim($result),
            new \DateTimeZone('Europe/Stockholm')
        );

        if ($dateValue instanceof \DateTime === true) {
            $dateValue->setTime(0, 0);
            return $dateValue->getTimestamp();
        }

        return false;
    }

    private function limitImageSize(\Dom\Node $node, string $baseUrl): void
    {
        if ($node instanceof \Dom\Element === false && $node instanceof \Dom\HTMLDocument === false) {
            return;
        }

        foreach ($node->querySelectorAll('img') as $img) {
            if ($img instanceof \Dom\Element === true) {
                $src = (string) ($img->getAttribute('src') ?? '');
                if ($src !== '') {
                    $img->setAttribute('src', urljoin($baseUrl, $src));
                }
                $img->removeAttribute('width');
                $img->removeAttribute('height');
                $img->removeAttribute('align');
                $img->setAttribute('style', self::CSS['img']);
            }
        }
    }
}
