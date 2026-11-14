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
        'img' => 'display: block; float: none; clear: both; max-width: 400px; width: auto; height: auto; margin: 16px 0; padding: 0;',
        'img_table' => 'display: inline-block; max-width: 100px; max-height: 100px; width: auto; height: auto; vertical-align: middle;',
        'table' => 'border-collapse: collapse; max-width: 100%; width: auto; table-layout: auto; border: 1px solid #ccc;',
        'cell' => 'border: 1px solid #ccc; padding: 6px 10px; text-align: left; vertical-align: top;',
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
            throwServerException('Empty response from Testfakta homepage');
        }

        try {
            libxml_use_internal_errors(true);
            $dom = \Dom\HTMLDocument::createFromString($html);
            libxml_use_internal_errors(false);
        } catch (\Throwable) {
            libxml_use_internal_errors(false);
            throwServerException('Failed to parse Testfakta homepage');
        }

        $rows = $dom->querySelectorAll('.row-container');

        foreach ($rows as $element) {
            if ($element instanceof \Dom\Element === false) {
                continue;
            }

            $titleNode = $element->querySelector('h2');
            $title = ($titleNode !== null) ? trim($titleNode->textContent ?? '') : '';

            $categoryNode = $element->querySelector('.red-label');
            $category = ($categoryNode !== null) ? trim($categoryNode->textContent ?? '') : '';

            $linkNode = $element->querySelector('a');
            $href = ($linkNode !== null) ? ($linkNode->getAttribute('href') ?? '') : '';
            if ($href === '') {
                continue;
            }
            $url = urljoin(self::URI, $href);
            if ($url === null || $url === '') {
                continue;
            }

            $figureNode = $element->querySelector('img');
            $figureSrc = ($figureNode !== null) ? ($figureNode->getAttribute('src') ?? '') : '';

            $preambleNode = $element->querySelector('.text');
            $preamble = ($preambleNode !== null) ? trim($preambleNode->textContent ?? '') : '';

            try {
                $articleHtml = getContents($url);
            } catch (\Exception) {
                continue;
            }

            if (is_string($articleHtml) === false || $articleHtml === '') {
                continue;
            }

            try {
                libxml_use_internal_errors(true);
                $articleDom = \Dom\HTMLDocument::createFromString($articleHtml);
                libxml_use_internal_errors(false);
            } catch (\Throwable) {
                libxml_use_internal_errors(false);
                continue;
            }

            $articleTextNode = $articleDom->querySelector('article');

            $requestorNode = $articleDom->querySelector('div.uppdrag');
            $requestor = ($requestorNode !== null) ? trim($requestorNode->textContent ?? '') : '';

            $authorNode = $articleDom->querySelector('span.name');
            $author = ($authorNode !== null) ? trim($authorNode->textContent ?? '') : '';
            $author = $this->cleanAuthorName($author);

            $publishedNode = $articleDom->querySelector('span.created');
            $publishedText = ($publishedNode !== null) ? trim($publishedNode->textContent ?? '') : '';
            $publishedText = str_replace('Publicerad: ', '', $publishedText);
            $published = $this->parseSwedishDates($publishedText);

            $content = '';

            if ($figureSrc !== '') {
                $fullFigureSrc = urljoin($url, $figureSrc);
                if ($fullFigureSrc !== null && $fullFigureSrc !== '') {
                    $content .= '<p><img src="' . htmlspecialchars($fullFigureSrc) . '" style="' . self::CSS['img'] . '" alt="" /></p>';
                }
            }

            if ($category !== '' || $requestor !== '') {
                $content .= '<p><b>' . strtoupper(htmlspecialchars($category)) . '</b>  ' . htmlspecialchars($requestor) . '</p>';
            }

            if ($preamble !== '') {
                $content .= '<p><b><i>' . htmlspecialchars($preamble) . '</i></b></p>';
            }

            if ($articleTextNode !== null) {
                $this->removeJunk($articleTextNode);
                $this->cleanTableStyles($articleTextNode);
                $this->limitImageSize($articleTextNode, $url);
                $articleContent = $articleTextNode->innerHTML;
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

    private function removeJunk(\Dom\Element $node): void
    {
        foreach (['script', 'svg'] as $tag) {
            foreach (iterator_to_array($node->querySelectorAll($tag)) as $el) {
                if ($el->parentNode !== null) {
                    $el->parentNode->removeChild($el);
                }
            }
        }
    }

    private function cleanTableStyles(\Dom\Element $node): void
    {
        foreach (iterator_to_array($node->querySelectorAll('table, tr, td, th')) as $el) {
            $el->removeAttribute('style');
            $el->removeAttribute('bgcolor');
            $el->removeAttribute('color');
            $el->removeAttribute('background');
        }

        foreach (iterator_to_array($node->querySelectorAll('table')) as $table) {
            $table->setAttribute('style', self::CSS['table']);
        }

        foreach (iterator_to_array($node->querySelectorAll('td, th')) as $cell) {
            $cell->setAttribute('style', self::CSS['cell']);
        }
    }

    private function limitImageSize(\Dom\Element $node, string $baseUrl): void
    {
        foreach (iterator_to_array($node->querySelectorAll('img')) as $img) {
            $src = $img->getAttribute('src') ?? '';
            if ($src !== '') {
                $absolute = urljoin($baseUrl, $src);
                if ($absolute !== null && $absolute !== '') {
                    $img->setAttribute('src', $absolute);
                }
            }
            $img->removeAttribute('width');
            $img->removeAttribute('height');
            $img->removeAttribute('align');

            if ($this->isInsideTable($img) === true) {
                $img->setAttribute('style', self::CSS['img_table']);
            } else {
                $img->setAttribute('style', self::CSS['img']);
            }
        }
    }

    private function isInsideTable(\Dom\Element $img): bool
    {
        $current = $img->parentElement;
        while ($current !== null) {
            if ($current->localName === 'table' || $current->localName === 'td' || $current->localName === 'th') {
                return true;
            }
            $current = $current->parentElement;
        }
        return false;
    }
}
