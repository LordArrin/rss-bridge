<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class LWNprevBridge extends BridgeAbstract
{
    public const NAME = 'LWN Free Weekly Edition';
    public const URI = 'https://lwn.net/';
    public const DESCRIPTION = 'LWN Free Weekly Edition available one week late';
    public const CACHE_TIMEOUT = 604800;
    public const MAINTAINER = 'No maintainer';

    private ?int $editionTimeStamp = null;

    public function getURI(): string
    {
        return self::URI . 'free/bigpage';
    }

    private function jumpToNextTag(?\Dom\Node $node): ?\Dom\Node
    {
        while ($node !== null && $node->nodeType === XML_TEXT_NODE) {
            $nextNode = $node->nextSibling;
            if ($nextNode === null) {
                break;
            }
            $node = $nextNode;
        }
        return $node;
    }

    private function jumpToPreviousTag(?\Dom\Node $node): ?\Dom\Node
    {
        while ($node !== null && $node->nodeType === XML_TEXT_NODE) {
            $previousNode = $node->previousSibling;
            if ($previousNode === null) {
                break;
            }
            $node = $previousNode;
        }
        return $node;
    }

    public function collectData(): void
    {
        $content = getContents($this->getURI());
        if ($content === '') {
            throwServerException('Failed to fetch LWN content');
        }

        $contents = explode('<b>Page editor</b>', $content);

        foreach ($contents as $contentPart) {
            if (strpos($contentPart, '<html>') === false) {
                $contentPart = <<<EOD
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html><head><title>LWN</title></head><body>{$contentPart}</body></html>
EOD;
            } else {
                $contentPart = $contentPart . '</body></html>';
            }

            libxml_use_internal_errors(true);
            $html = \Dom\HTMLDocument::createFromString($contentPart);
            libxml_clear_errors();
            libxml_use_internal_errors(false);

            $edition = $html->getElementsByTagName('h1');
            if ($edition->length !== 0) {
                $firstH1 = $edition->item(0);
                $text = $firstH1 !== null ? ($firstH1->textContent ?? '') : '';
                $forPos = strpos($text, 'for ');
                if ($forPos !== false) {
                    $dateString = trim(substr($text, $forPos + strlen('for ')));
                    $parsedTime = strtotime($dateString);
                    if ($parsedTime !== false) {
                        $this->editionTimeStamp = $parsedTime;
                    } else {
                        $this->editionTimeStamp = time();
                    }
                } else {
                    $this->editionTimeStamp = time();
                }
            }

            if (strpos($contentPart, 'Cat1HL') === false) {
                $items = $this->getFeatureContents($html);
            } elseif (strpos($contentPart, 'Cat3HL') === false) {
                $items = $this->getBriefItems($html);
            } else {
                $items = $this->getAnnouncements($html);
            }

            $this->items = array_merge($this->items, $items);
        }
    }

    private function extractAuthorAndDate(\Dom\Element $title): array
    {
        $author = null;
        $timestamp = $this->editionTimeStamp ?? time();

        $node = $title->nextSibling;
        $node = $this->jumpToNextTag($node);

        if ($node === null || ($node instanceof \Dom\Element) === false) {
            return ['author' => $author, 'timestamp' => $timestamp];
        }

        /** @var \Dom\Element $node */
        if ($node->getAttribute('class') !== 'FeatureByline') {
            return ['author' => $author, 'timestamp' => $timestamp];
        }

        $boldTags = $node->getElementsByTagName('b');
        if ($boldTags->length > 0) {
            $firstBold = $boldTags->item(0);
            $authorText = $firstBold !== null ? trim($firstBold->textContent ?? '') : '';
            if ($authorText !== '') {
                $author = $authorText;
            }
        }

        $fullText = $node->textContent ?? '';
        $datePattern = '/(January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},\s+\d{4}/';
        if (preg_match($datePattern, $fullText, $matches) === 1) {
            $parsedTime = strtotime($matches[0]);
            if ($parsedTime !== false) {
                $timestamp = $parsedTime;
            }
        }

        if ($node->parentNode !== null) {
            $node->parentNode->removeChild($node);
        }

        return ['author' => $author, 'timestamp' => $timestamp];
    }

    private function getArticleContent(\Dom\Element $title, ?int $timestamp = null): array
    {
        $link = $title->firstChild;
        $link = $this->jumpToNextTag($link);

        $item = [];
        $item['uri'] = self::URI;

        if ($link !== null && $link instanceof \Dom\Element && $link->localName === 'a') {
            $href = $link->getAttribute('href') ?? '';
            if ($href !== '') {
                $item['uri'] .= $href;
            }
        }

        $item['timestamp'] = $timestamp ?? ($this->editionTimeStamp ?? time());

        $node = $title;
        $content = '';
        $contentEnd = false;

        $ownerDocument = $title->ownerDocument;

        while ($contentEnd === false) {
            $node = $node->nextSibling;

            if ($node === null) {
                $contentEnd = true;
            } else {
                $isTextNode = $node->nodeType === XML_TEXT_NODE;
                $isH3 = ($node instanceof \Dom\Element) && $node->localName === 'h3';
                $hasClass = false;

                if ($isTextNode === false && $node instanceof \Dom\Element) {
                    $classValue = $node->getAttribute('class') ?? '';
                    if ($classValue === 'Cat1HL' || $classValue === 'Cat2HL') {
                        $hasClass = true;
                    }
                }

                if ($isTextNode === false && ($isH3 === true || $hasClass === true)) {
                    $contentEnd = true;
                } elseif ($ownerDocument instanceof \Dom\HTMLDocument) {
                    $content .= $ownerDocument->saveHtml($node);
                } else {
                    $content .= $node->C14N();
                }
            }
        }

        $content = $this->cleanArticleContent($content);

        $item['content'] = $content;
        return $item;
    }

    private function cleanArticleContent(string $content): string
    {
        libxml_use_internal_errors(true);
        $doc = \Dom\HTMLDocument::createFromString(
            '<!DOCTYPE html><html><body>' . $content . '</body></html>'
        );
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        $this->fixImages($doc);
        $this->removeCommentsBlock($doc);

        $bodies = $doc->getElementsByTagName('body');
        $body = $bodies->item(0);
        if ($body === null) {
            return $content;
        }

        $result = '';
        foreach ($body->childNodes as $child) {
            $result .= $doc->saveHtml($child);
        }

        return $result;
    }

    private function fixImages(\Dom\HTMLDocument $doc): void
    {
        $images = $doc->getElementsByTagName('img');
        $imageList = [];

        foreach ($images as $img) {
            $imageList[] = $img;
        }

        foreach ($imageList as $img) {
            if ($img instanceof \Dom\Element === false) {
                continue;
            }

            $existingStyle = $img->getAttribute('style') ?? '';
            $newStyle = 'float: left; margin: 0 15px 10px 0; max-width: 300px;';

            if ($existingStyle !== '') {
                $newStyle = $existingStyle . '; ' . $newStyle;
            }

            $img->setAttribute('style', $newStyle);
        }

        foreach ($imageList as $img) {
            if ($img->parentNode === null) {
                continue;
            }

            $clearDiv = $doc->createElement('div');
            $clearDiv->setAttribute('style', 'clear: both; height: 10px;');
            $nextSibling = $img->nextSibling;

            if ($nextSibling !== null) {
                $img->parentNode->insertBefore($clearDiv, $nextSibling);
            } else {
                $img->parentNode->appendChild($clearDiv);
            }
        }
    }

    private function removeCommentsBlock(\Dom\HTMLDocument $doc): void
    {
        $toRemove = [];

        $links = $doc->getElementsByTagName('a');
        foreach ($links as $link) {
            if ($link instanceof \Dom\Element === false) {
                continue;
            }

            $href = $link->getAttribute('href') ?? '';
            if (str_contains($href, '#Comments') === false) {
                continue;
            }

            $parent = $link->parentNode;
            while ($parent !== null && ($parent instanceof \Dom\Element === false || $parent->localName !== 'body')) {
                if ($parent instanceof \Dom\Element && ($parent->localName === 'p' || $parent->localName === 'div')) {
                    $toRemove[] = $parent;
                    break;
                }
                $parent = $parent->parentNode;
            }

            if ($parent === null || ($parent instanceof \Dom\Element && $parent->localName === 'body')) {
                $toRemove[] = $link;
            }
        }

        foreach ($toRemove as $node) {
            if ($node->parentNode !== null) {
                $node->parentNode->removeChild($node);
            }
        }
    }

    private function getFeatureContents(\Dom\HTMLDocument $html): array
    {
        $items = [];

        foreach ($html->getElementsByTagName('h3') as $title) {
            if ($title instanceof \Dom\Element === false) {
                continue;
            }

            if ($title->getAttribute('class') !== 'SummaryHL') {
                continue;
            }

            $titleText = $title->textContent ?? '';
            if (str_contains($titleText, 'Welcome to the LWN.net') === true) {
                continue;
            }

            $item = [];

            $metadata = $this->extractAuthorAndDate($title);

            if ($metadata['author'] !== null) {
                $item['author'] = $metadata['author'];
            }

            $item['title'] = $titleText;
            $items[] = array_merge($item, $this->getArticleContent($title, $metadata['timestamp']));
        }

        return $items;
    }

    private function getItemPrefix(\Dom\Node $cat, array &$cats): string
    {
        $cat1 = '';
        $cat2 = '';
        $cat3 = '';

        if ($cat instanceof \Dom\Element) {
            $catClass = $cat->getAttribute('class') ?? '';
        } else {
            $catClass = '';
        }

        if ($catClass === 'Cat3HL') {
            $cat3 = $cat->textContent ?? '';
            $cat = $cat->previousSibling;
            $cat = $this->jumpToPreviousTag($cat);
            $cats[2] = $cat3;

            if ($cat !== null && $cat instanceof \Dom\Element && ($cat->getAttribute('class') ?? '') === 'Cat2HL') {
                $cat2 = $cat->textContent ?? '';
                $cat = $cat->previousSibling;
                $cat = $this->jumpToPreviousTag($cat);
                $cats[1] = $cat2;

                if ($cat3 === '') {
                    $cats[2] = '';
                }

                if ($cat !== null && $cat instanceof \Dom\Element && ($cat->getAttribute('class') ?? '') === 'Cat1HL') {
                    $cat1 = $cat->textContent ?? '';
                    $cats[0] = $cat1;

                    if ($cat3 === '') {
                        $cats[2] = '';
                    }
                    if ($cat2 === '') {
                        $cats[1] = '';
                    }
                }
            }
        } elseif ($catClass === 'Cat2HL') {
            $cat2 = $cat->textContent ?? '';
            $cat = $cat->previousSibling;
            $cat = $this->jumpToPreviousTag($cat);
            $cats[1] = $cat2;

            if ($cat3 === '') {
                $cats[2] = '';
            }

            if ($cat !== null && $cat instanceof \Dom\Element && ($cat->getAttribute('class') ?? '') === 'Cat1HL') {
                $cat1 = $cat->textContent ?? '';
                $cats[0] = $cat1;

                if ($cat3 === '') {
                    $cats[2] = '';
                }
                if ($cat2 === '') {
                    $cats[1] = '';
                }
            }
        } elseif ($catClass === 'Cat1HL') {
            $cat1 = $cat->textContent ?? '';
            $cats[0] = $cat1;

            if ($cat3 === '') {
                $cats[2] = '';
            }
            if ($cat2 === '') {
                $cats[1] = '';
            }
        }

        $prefix = '';
        if ($cats[0] !== '') {
            $prefix .= '[' . $cats[0];
            if ($cats[1] !== '') {
                $prefix .= '/' . $cats[1];
            }
            $prefix .= '] ';
        }

        return $prefix;
    }

    private function getAnnouncements(\Dom\HTMLDocument $html): array
    {
        $items = [];
        $cats = ['', '', ''];

        foreach ($html->getElementsByTagName('p') as $newsletters) {
            if ($newsletters instanceof \Dom\Element === false) {
                continue;
            }

            if ($newsletters->getAttribute('class') !== 'Cat3HL') {
                continue;
            }

            $item = [];
            $item['uri'] = self::URI . '#' . count($items);
            $item['timestamp'] = $this->editionTimeStamp ?? time();
            $item['author'] = 'LWN';

            $cat = $newsletters->previousSibling;
            $cat = $this->jumpToPreviousTag($cat);

            if ($cat !== null) {
                $prefix = $this->getItemPrefix($cat, $cats);
                $item['title'] = $prefix . ' ' . ($newsletters->textContent ?? '');
            } else {
                $item['title'] = $newsletters->textContent ?? '';
            }

            $node = $newsletters;
            $content = '';
            $contentEnd = false;

            $ownerDocument = $newsletters->ownerDocument;

            while ($contentEnd === false) {
                $node = $node->nextSibling;

                if ($node === null) {
                    $contentEnd = true;
                } else {
                    $isTextNode = $node->nodeType === XML_TEXT_NODE;
                    $hasClass = false;

                    if ($isTextNode === false && $node instanceof \Dom\Element) {
                        $classValue = $node->getAttribute('class') ?? '';
                        if ($classValue === 'Cat1HL' || $classValue === 'Cat2HL' || $classValue === 'Cat3HL') {
                            $hasClass = true;
                        }
                    }

                    if ($isTextNode === false && $hasClass === true) {
                        $contentEnd = true;
                    } elseif ($ownerDocument instanceof \Dom\HTMLDocument) {
                        $content .= $ownerDocument->saveHtml($node);
                    } else {
                        $content .= $node->C14N();
                    }
                }
            }

            $item['content'] = $this->cleanArticleContent($content);
            $items[] = $item;
        }

        foreach ($html->getElementsByTagName('h2') as $title) {
            if ($title instanceof \Dom\Element === false) {
                continue;
            }

            if ($title->getAttribute('class') !== 'SummaryHL') {
                continue;
            }

            $item = [];

            $cat = $title->previousSibling;
            $cat = $this->jumpToPreviousTag($cat);

            if ($cat !== null) {
                $cat = $cat->previousSibling;
                $cat = $this->jumpToPreviousTag($cat);

                if ($cat !== null) {
                    $prefix = $this->getItemPrefix($cat, $cats);
                    $item['title'] = $prefix . ' ' . ($title->textContent ?? '');
                } else {
                    $item['title'] = $title->textContent ?? '';
                }
            } else {
                $item['title'] = $title->textContent ?? '';
            }

            $items[] = array_merge($item, $this->getArticleContent($title));
        }

        return $items;
    }

    private function getBriefItems(\Dom\HTMLDocument $html): array
    {
        $items = [];
        $cats = ['', '', ''];

        foreach ($html->getElementsByTagName('h2') as $title) {
            if ($title instanceof \Dom\Element === false) {
                continue;
            }

            if ($title->getAttribute('class') !== 'SummaryHL') {
                continue;
            }

            $item = [];

            $cat = $title->previousSibling;
            $cat = $this->jumpToPreviousTag($cat);

            if ($cat !== null) {
                $cat = $cat->previousSibling;
                $cat = $this->jumpToPreviousTag($cat);

                if ($cat !== null) {
                    $prefix = $this->getItemPrefix($cat, $cats);
                    $item['title'] = $prefix . ' ' . ($title->textContent ?? '');
                } else {
                    $item['title'] = $title->textContent ?? '';
                }
            } else {
                $item['title'] = $title->textContent ?? '';
            }

            $items[] = array_merge($item, $this->getArticleContent($title));
        }

        return $items;
    }
}
