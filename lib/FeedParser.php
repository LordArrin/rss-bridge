<?php

declare(strict_types=1);

namespace RSSBridge;

/**
 * Modernized feed parser using PHP 8.4+ \Dom\XMLDocument API.
 *
 * Scrapes RSS 0.91, 1.0, 2.0 and Atom 1.0.
 * Produces an array structure compatible with RSSBridge\FeedItem.
 */
final class FeedParser
{
    public function parseFeed(string $xmlString): array
    {
        $xmlString = self::prepareXml($xmlString);

        libxml_use_internal_errors(true);
        $doc = \Dom\XMLDocument::createFromString($xmlString, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xmlErrors = libxml_get_errors();
        libxml_use_internal_errors(false);

        if ($doc->documentElement === null) {
            $firstXmlErrorMessage = $xmlErrors !== [] ? $xmlErrors[0]->message : 'Unknown parsing error';
            throw new \Exception(sprintf('Unable to parse xml: %s', $firstXmlErrorMessage));
        }

        $root = $doc->documentElement;
        $rootName = strtolower($root->localName);

        $feed = [
            'title'  => '',
            'uri'    => '',
            'icon'   => '',
            'items'  => [],
        ];

        if ($rootName === 'rdf') {
            $channel = $root->querySelector('channel');
            if ($channel !== null) {
                $titleNode = $channel->querySelector('title');
                $feed['title'] = $titleNode !== null ? $this->getNodeText($titleNode) : '';

                $linkNode = $channel->querySelector('link');
                $feed['uri'] = $linkNode !== null ? $this->getNodeText($linkNode) : '';

                $image = $channel->querySelector('image');
                if ($image !== null) {
                    $urlNode = $image->querySelector('url');
                    $feed['icon'] = $urlNode !== null ? $this->getNodeText($urlNode) : '';
                }
            }
            foreach ($root->querySelectorAll('item') as $item) {
                $feed['items'][] = $this->parseRss1Item($item);
            }
        } elseif ($rootName === 'rss') {
            $channel = $root->querySelector('channel');
            if ($channel !== null) {
                $titleNode = $channel->querySelector('title');
                $feed['title'] = $titleNode !== null ? $this->getNodeText($titleNode) : '';

                $linkNode = $channel->querySelector('link');
                $feed['uri'] = $linkNode !== null ? $this->getNodeText($linkNode) : '';

                $image = $channel->querySelector('image');
                if ($image !== null) {
                    $urlNode = $image->querySelector('url');
                    $feed['icon'] = $urlNode !== null ? $this->getNodeText($urlNode) : '';
                }
            }
            foreach ($root->querySelectorAll('channel > item') as $item) {
                $feed['items'][] = $this->parseRss2Item($item);
            }
        } elseif ($rootName === 'feed') {
            $titleNode = $root->querySelector('title');
            $feed['title'] = $titleNode !== null ? $this->getNodeText($titleNode) : '';

            $feed['uri'] = '';
            foreach ($root->querySelectorAll('link') as $link) {
                $rel = $this->getNodeAttr($link, 'rel');
                if ($rel === 'alternate' || $rel === null || $rel === '') {
                    $href = $this->getNodeAttr($link, 'href');
                    $feed['uri'] = $href !== null ? $href : '';
                    break;
                }
            }

            $iconNode = $root->querySelector('icon');
            if ($iconNode === null) {
                $iconNode = $root->querySelector('logo');
            }
            if ($iconNode !== null) {
                $feed['icon'] = $this->getNodeText($iconNode);
            }

            foreach ($root->querySelectorAll('entry') as $item) {
                $feed['items'][] = $this->parseAtomItem($item);
            }
        } else {
            throw new \Exception('Unable to detect feed format');
        }

        return $feed;
    }

    private static function prepareXml(string $xmlString): string
    {
        $bom = pack('H*', 'EFBBBF');
        $xmlString = preg_replace("/^$bom/", '', $xmlString) ?? $xmlString;

        $problematicStrings = [
            '&nbsp;',
            '&raquo;',
            '&rsquo;',
        ];
        return str_replace($problematicStrings, '', $xmlString);
    }

    public function parseAtomItem(\Dom\Element $feedItem): array
    {
        $item = [
            'uri'        => '',
            'title'      => '',
            'content'    => '',
            'timestamp'  => '',
            'author'     => '',
            'categories' => [],
            'enclosures' => [],
        ];

        $idNode = $feedItem->querySelector('id');
        if ($idNode !== null) {
            $item['uri'] = $this->getNodeText($idNode);
        }

        $titleNode = $feedItem->querySelector('title');
        if ($titleNode !== null) {
            $item['title'] = trim(html_entity_decode($this->getNodeText($titleNode)));
        }

        $updatedNode = $feedItem->querySelector('updated');
        if ($updatedNode !== null) {
            $timestamp = strtotime($this->getNodeText($updatedNode));
            $item['timestamp'] = $timestamp !== false ? $timestamp : '';
        }

        $authorNode = $feedItem->querySelector('author > name');
        if ($authorNode !== null) {
            $item['author'] = $this->getNodeText($authorNode);
        }

        $contentNode = $feedItem->querySelector('content');
        if ($contentNode !== null) {
            if ($contentNode->hasChildNodes() === true && $contentNode->childNodes->count() > 0) {
                $content = '';
                foreach ($contentNode->childNodes as $child) {
                    $content .= $child->C14N();
                }
                $item['content'] = $content;
            } else {
                $item['content'] = $this->getNodeText($contentNode);
            }
        }

        foreach ($feedItem->querySelectorAll('link') as $link) {
            $rel = $this->getNodeAttr($link, 'rel');
            $href = $this->getNodeAttr($link, 'href');
            if ($href === null) {
                continue;
            }

            if ($rel === 'alternate' || $rel === null || $rel === '') {
                $item['uri'] = $href;
            }
            if ($rel === 'enclosure') {
                $item['enclosures'][] = $href;
            }
        }

        foreach ($feedItem->querySelectorAll('category') as $cat) {
            $item['categories'][] = $this->getNodeText($cat);
        }

        return $item;
    }

    public function parseRss2Item(\Dom\Element $feedItem): array
    {
        $item = [
            'uri'        => '',
            'title'      => '',
            'content'    => '',
            'timestamp'  => '',
            'author'     => '',
            'categories' => [],
            'enclosures' => [],
        ];

        $linkNode = $feedItem->querySelector('link');
        if ($linkNode !== null) {
            $item['uri'] = $this->getNodeText($linkNode);
        }

        $titleNode = $feedItem->querySelector('title');
        if ($titleNode !== null) {
            $item['title'] = trim(html_entity_decode($this->getNodeText($titleNode)));
        }

        $contentEncodedList = $feedItem->getElementsByTagNameNS('http://purl.org/rss/1.0/modules/content/', 'encoded');
        $contentEncoded = $contentEncodedList->item(0);
        if ($contentEncoded instanceof \Dom\Element) {
            $item['content'] = $contentEncoded->textContent;
        } else {
            $descNode = $feedItem->querySelector('description');
            if ($descNode !== null) {
                $item['content'] = $this->getNodeText($descNode);
            }
        }

        $dcCreatorList = $feedItem->getElementsByTagNameNS('http://purl.org/dc/elements/1.1/', 'creator');
        $dcCreator = $dcCreatorList->item(0);

        $mediaCreditList = $feedItem->getElementsByTagNameNS('http://search.yahoo.com/mrss/', 'credit');
        $mediaCredit = $mediaCreditList->item(0);

        $authorNode = $feedItem->querySelector('author');
        if ($authorNode === null) {
            $authorNode = $dcCreator instanceof \Dom\Element ? $dcCreator : null;
        }
        if ($authorNode === null) {
            $authorNode = $mediaCredit instanceof \Dom\Element ? $mediaCredit : null;
        }
        if ($authorNode instanceof \Dom\Element) {
            $item['author'] = $this->getNodeText($authorNode);
        }

        $dcDateList = $feedItem->getElementsByTagNameNS('http://purl.org/dc/elements/1.1/', 'date');
        $dcDate = $dcDateList->item(0);

        $pubDate = $feedItem->querySelector('pubDate');
        $dateNode = $pubDate !== null ? $pubDate : ($dcDate instanceof \Dom\Element ? $dcDate : null);
        if ($dateNode instanceof \Dom\Element) {
            $timestamp = strtotime($this->getNodeText($dateNode));
            $item['timestamp'] = $timestamp !== false ? $timestamp : '';
        }

        foreach ($feedItem->querySelectorAll('category') as $cat) {
            $item['categories'][] = $this->getNodeText($cat);
        }

        $enclosure = $feedItem->querySelector('enclosure');
        if ($enclosure !== null && $enclosure->hasAttribute('url') === true) {
            $url = $enclosure->getAttribute('url');
            $item['enclosures'][] = $url;
            $item['enclosure'] = [
                'url'    => $url,
                'length' => $enclosure->hasAttribute('length') === true ? $enclosure->getAttribute('length') : '',
                'type'   => $enclosure->hasAttribute('type') === true ? $enclosure->getAttribute('type') : '',
            ];
        }

        if ($item['uri'] === '') {
            $guid = $feedItem->querySelector('guid');
            if ($guid !== null) {
                $isPermaLink = $this->getNodeAttr($guid, 'isPermaLink');
                $guidText = $this->getNodeText($guid);
                if (($isPermaLink === 'true' || filter_var($guidText, FILTER_VALIDATE_URL) !== false)) {
                    $item['uri'] = $guidText;
                }
            }
        }

        $modules = [];
        foreach ($feedItem->childNodes as $child) {
            if ($child instanceof \Dom\Element && $child->namespaceURI !== null) {
                $prefix = $child->prefix ?? '';
                $localName = $child->localName;

                if ($prefix === '' || $prefix === 'content') {
                    continue;
                }

                if (isset($modules[$prefix]) === false) {
                    $modules[$prefix] = [];
                }

                if ($child->hasChildNodes() === true && $child->childNodes->count() > 0) {
                    $modules[$prefix][$localName] = $this->parseDomModule($child);
                } else {
                    $modules[$prefix][$localName] = $this->getNodeText($child);
                }
            }
        }

        foreach ($modules as $ns => $data) {
            $item[$ns] = $data;
        }

        return $item;
    }

    public function parseRss1Item(\Dom\Element $feedItem): array
    {
        $item = [
            'uri'        => '',
            'title'      => '',
            'content'    => '',
            'timestamp'  => '',
            'author'     => '',
            'categories' => [],
        ];

        $linkNode = $feedItem->querySelector('link');
        if ($linkNode !== null) {
            $item['uri'] = $this->getNodeText($linkNode);
        }

        $titleNode = $feedItem->querySelector('title');
        if ($titleNode !== null) {
            $item['title'] = trim(html_entity_decode($this->getNodeText($titleNode)));
        }

        $descNode = $feedItem->querySelector('description');
        if ($descNode !== null) {
            $item['content'] = $this->getNodeText($descNode);
        }

        $dcDateList = $feedItem->getElementsByTagNameNS('http://purl.org/dc/elements/1.1/', 'date');
        $dcDate = $dcDateList->item(0);
        if ($dcDate instanceof \Dom\Element) {
            $timestamp = strtotime($this->getNodeText($dcDate));
            $item['timestamp'] = $timestamp !== false ? $timestamp : '';
        }

        $dcCreatorList = $feedItem->getElementsByTagNameNS('http://purl.org/dc/elements/1.1/', 'creator');
        $dcCreator = $dcCreatorList->item(0);
        if ($dcCreator instanceof \Dom\Element) {
            $item['author'] = $this->getNodeText($dcCreator);
        }

        return $item;
    }

    private function parseDomModule(\Dom\Element $element): array|string
    {
        $result = [];
        $hasElements = false;

        foreach ($element->childNodes as $child) {
            if ($child instanceof \Dom\Element) {
                $hasElements = true;
                $localName = $child->localName;

                if ($child->hasChildNodes() === true && $child->childNodes->count() > 0) {
                    $result[$localName] = $this->parseDomModule($child);
                } else {
                    $result[$localName] = $this->getNodeText($child);
                }
            }
        }

        if ($hasElements === false) {
            return $this->getNodeText($element);
        }

        return $result;
    }

    private function getNodeText(?\Dom\Element $element): string
    {
        return $element !== null ? trim((string)$element->textContent) : '';
    }

    private function getNodeAttr(?\Dom\Element $element, string $name): ?string
    {
        return ($element !== null && $element->hasAttribute($name) === true) ? $element->getAttribute($name) : null;
    }
}
