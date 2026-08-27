<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class LWNprevBridge extends BridgeAbstract
{
    public const NAME = 'LWN Free Weekly Edition';
    public const URI = 'https://lwn.net/';
    public const DESCRIPTION = 'LWN Free Weekly Edition available one week late';
    public const CACHE_TIMEOUT = 604800;
    public const MAINTAINER = 'No maintainer';

    private ?int $editionTimeStamp = null;

    public function getURI()
    {
        return self::URI . 'free/bigpage';
    }

    private function jumpToNextTag(?\DOMNode $node): ?\DOMNode
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

    private function jumpToPreviousTag(?\DOMNode $node): ?\DOMNode
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

    public function collectData()
    {
        $content = getContents($this->getURI());
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
            $html = new \DOMDocument();
            $html->loadHTML($contentPart);
            libxml_clear_errors();

            $edition = $html->getElementsByTagName('h1');
            if ($edition->length !== 0) {
                $text = $edition->item(0)->textContent ?? '';
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

    private function extractAuthorAndDate(\DOMNode $title): array
    {
        $author = null;
        $timestamp = $this->editionTimeStamp ?? time();

        $node = $title->nextSibling;
        $node = $this->jumpToNextTag($node);

        if ($node === null || ($node instanceof \DOMElement) === false) {
            return ['author' => $author, 'timestamp' => $timestamp];
        }

        if ($node->getAttribute('class') !== 'FeatureByline') {
            return ['author' => $author, 'timestamp' => $timestamp];
        }

        $boldTags = $node->getElementsByTagName('b');
        if ($boldTags->length > 0) {
            $authorText = trim($boldTags->item(0)->textContent ?? '');
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

    private function getArticleContent(\DOMNode $title, ?int $timestamp = null): array
    {
        $link = $title->firstChild;
        $link = $this->jumpToNextTag($link);

        $item = [];
        $item['uri'] = self::URI;

        if ($link !== null && $link->nodeName === 'a') {
            $href = $link->getAttribute('href');
            if ($href !== '') {
                $item['uri'] .= $href;
            }
        }

        $item['timestamp'] = $timestamp ?? ($this->editionTimeStamp ?? time());

        $node = $title;
        $content = '';
        $contentEnd = false;

        while ($contentEnd === false) {
            $node = $node->nextSibling;

            if ($node === null) {
                $contentEnd = true;
            } else {
                $isTextNode = $node->nodeType === XML_TEXT_NODE;
                $isH3 = $node->nodeName === 'h3';
                $hasClass = false;

                if ($isTextNode === false && $node->attributes !== null) {
                    $class = $node->attributes->getNamedItem('class');
                    if ($class !== null) {
                        $classValue = $class->nodeValue;
                        if ($classValue === 'Cat1HL' || $classValue === 'Cat2HL') {
                            $hasClass = true;
                        }
                    }
                }

                if ($isTextNode === false && ($isH3 === true || $hasClass === true)) {
                    $contentEnd = true;
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
        $doc = new \DOMDocument();
        $doc->loadHTML(
            '<!DOCTYPE html><html><body>' . $content . '</body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $this->fixImages($doc);
        $this->removeCommentsBlock($doc);

        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return $content;
        }

        $result = '';
        foreach ($body->childNodes as $child) {
            $result .= $doc->saveHTML($child);
        }

        return $result;
    }

    private function fixImages(\DOMDocument $doc): void
    {
        $images = $doc->getElementsByTagName('img');
        $imageList = [];

        foreach ($images as $img) {
            $imageList[] = $img;
        }

        foreach ($imageList as $img) {
            $existingStyle = $img->getAttribute('style');
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

    private function removeCommentsBlock(\DOMDocument $doc): void
    {
        $toRemove = [];

        $links = $doc->getElementsByTagName('a');
        foreach ($links as $link) {
            $href = $link->getAttribute('href') ?? '';
            if (str_contains($href, '#Comments') === false) {
                continue;
            }

            $parent = $link->parentNode;
            while ($parent !== null && $parent->nodeName !== 'body') {
                if ($parent->nodeName === 'p' || $parent->nodeName === 'div') {
                    $toRemove[] = $parent;
                    break;
                }
                $parent = $parent->parentNode;
            }

            if ($parent === null || $parent->nodeName === 'body') {
                $toRemove[] = $link;
            }
        }

        foreach ($toRemove as $node) {
            if ($node->parentNode !== null) {
                $node->parentNode->removeChild($node);
            }
        }
    }

    private function getFeatureContents(\DOMDocument $html): array
    {
        $items = [];

        foreach ($html->getElementsByTagName('h3') as $title) {
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

    private function getItemPrefix(\DOMNode $cat, array &$cats): string
    {
        $cat1 = '';
        $cat2 = '';
        $cat3 = '';

        $catClass = $cat->getAttribute('class');

        if ($catClass === 'Cat3HL') {
            $cat3 = $cat->textContent ?? '';
            $cat = $cat->previousSibling;
            $cat = $this->jumpToPreviousTag($cat);
            $cats[2] = $cat3;

            if ($cat !== null && $cat->getAttribute('class') === 'Cat2HL') {
                $cat2 = $cat->textContent ?? '';
                $cat = $cat->previousSibling;
                $cat = $this->jumpToPreviousTag($cat);
                $cats[1] = $cat2;

                if ($cat3 === '') {
                    $cats[2] = '';
                }

                if ($cat !== null && $cat->getAttribute('class') === 'Cat1HL') {
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

            if ($cat !== null && $cat->getAttribute('class') === 'Cat1HL') {
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

    private function getAnnouncements(\DOMDocument $html): array
    {
        $items = [];
        $cats = ['', '', ''];

        foreach ($html->getElementsByTagName('p') as $newsletters) {
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

            while ($contentEnd === false) {
                $node = $node->nextSibling;

                if ($node === null) {
                    $contentEnd = true;
                } else {
                    $isTextNode = $node->nodeType === XML_TEXT_NODE;
                    $hasClass = false;

                    if ($isTextNode === false && $node->attributes !== null) {
                        $class = $node->attributes->getNamedItem('class');
                        if ($class !== null) {
                            $classValue = $class->nodeValue;
                            if ($classValue === 'Cat1HL' || $classValue === 'Cat2HL' || $classValue === 'Cat3HL') {
                                $hasClass = true;
                            }
                        }
                    }

                    if ($isTextNode === false && $hasClass === true) {
                        $contentEnd = true;
                    } else {
                        $content .= $node->C14N();
                    }
                }
            }

            $item['content'] = $this->cleanArticleContent($content);
            $items[] = $item;
        }

        foreach ($html->getElementsByTagName('h2') as $title) {
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

    private function getBriefItems(\DOMDocument $html): array
    {
        $items = [];
        $cats = ['', '', ''];

        foreach ($html->getElementsByTagName('h2') as $title) {
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
