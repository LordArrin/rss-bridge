<?php

declare(strict_types=1);

namespace RSSBridge\Formats;

use RSSBridge\Utils\Url;

/**
 * AtomFormat - RFC 4287: The Atom Syndication Format
 * https://tools.ietf.org/html/rfc4287
 *
 * Validator: https://validator.w3.org/feed/
 */
final class AtomFormat extends FormatAbstract
{
    public const MIME_TYPE = 'application/atom+xml';

    protected const ATOM_NS = 'http://www.w3.org/2005/Atom';
    protected const MRSS_NS = 'http://search.yahoo.com/mrss/';
    protected const ITUNES_NS = 'http://www.itunes.com/dtds/podcast-1.0.dtd';

    public function getMimeType(): string
    {
        return self::MIME_TYPE;
    }

    public function render(): string
    {
        $document = \Dom\XMLDocument::createEmpty('1.0', 'UTF-8');
        $document->formatOutput = true;

        $feedUrl = Url::getCurrentUrl();

        $feed = $document->createElementNS(self::ATOM_NS, 'feed');
        $document->appendChild($feed);
        $feed->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:media', self::MRSS_NS);

        $feedArray = $this->getFeed();
        foreach ($feedArray as $feedKey => $feedValue) {
            if ($feedKey === 'name') {
                $title = $document->createElementNS(self::ATOM_NS, 'title');
                $feed->appendChild($title);
                $title->setAttribute('type', 'text');
                $title->appendChild($document->createTextNode((string) $feedValue));
            } elseif ($feedKey === 'icon') {
                if ((bool) $feedValue === true) {
                    $icon = $document->createElementNS(self::ATOM_NS, 'icon');
                    $feed->appendChild($icon);
                    $icon->appendChild($document->createTextNode((string) $feedValue));

                    $logo = $document->createElementNS(self::ATOM_NS, 'logo');
                    $feed->appendChild($logo);
                    $logo->appendChild($document->createTextNode((string) $feedValue));
                }
            } elseif ($feedKey === 'uri') {
                if ((bool) $feedValue === true) {
                    $linkAlternate = $document->createElementNS(self::ATOM_NS, 'link');
                    $feed->appendChild($linkAlternate);
                    $linkAlternate->setAttribute('rel', 'alternate');
                    $linkAlternate->setAttribute('type', 'text/html');
                    $linkAlternate->setAttribute('href', (string) $feedValue);

                    $linkSelf = $document->createElementNS(self::ATOM_NS, 'link');
                    $feed->appendChild($linkSelf);
                    $linkSelf->setAttribute('rel', 'self');
                    $linkSelf->setAttribute('type', 'application/atom+xml');
                    $linkSelf->setAttribute('href', $feedUrl);
                }
            } elseif ($feedKey === 'itunes') {
                $feed->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:itunes', self::ITUNES_NS);
                foreach ((array) $feedValue as $itunesKey => $itunesValue) {
                    $itunesProperty = $document->createElementNS(self::ITUNES_NS, (string) $itunesKey);
                    $feed->appendChild($itunesProperty);
                    $itunesProperty->appendChild($document->createTextNode((string) $itunesValue));
                }
            } else {
                $element = $document->createElementNS(self::ATOM_NS, (string) $feedKey);
                $feed->appendChild($element);
                $element->appendChild($document->createTextNode((string) $feedValue));
            }
        }

        $idNode = $document->createElementNS(self::ATOM_NS, 'id');
        $feed->appendChild($idNode);
        $idNode->appendChild($document->createTextNode($feedUrl));

        $updatedNode = $document->createElementNS(self::ATOM_NS, 'updated');
        $feed->appendChild($updatedNode);
        $updatedNode->appendChild($document->createTextNode(gmdate(\DATE_ATOM, $this->getLastModified())));

        $feedAuthor = 'RSS-Bridge';
        $authorNode = $document->createElementNS(self::ATOM_NS, 'author');
        $feed->appendChild($authorNode);
        $authorName = $document->createElementNS(self::ATOM_NS, 'name');
        $authorNode->appendChild($authorName);
        $authorName->appendChild($document->createTextNode($feedAuthor));

        foreach ($this->getItems() as $item) {
            $itemArray = $item->toArray();
            $entryTimestamp = $item->getTimestamp();
            $entryTitle = $item->getTitle();
            $entryContent = $item->getContent();
            $entryUri = $item->getURI();
            $entryID = '';

            $uid = $item->getUid();
            if ($uid !== null && $uid !== '') {
                $entryID = 'urn:sha1:' . $uid;
            }

            if ($entryID === '') {
                $entryID = $entryUri;
            }

            if ($entryID === '') {
                $entryID = 'urn:sha1:' . hash('sha1', (string) $entryTitle . (string) $entryContent);
            }

            if ($entryTitle === null || $entryTitle === '') {
                $entryTitle = str_replace("\n", ' ', strip_tags((string) $entryContent));
                if (strlen($entryTitle) > 140) {
                    $wrapPos = strpos(wordwrap($entryTitle, 140), "\n");
                    if ($wrapPos !== false) {
                        $entryTitle = substr($entryTitle, 0, $wrapPos) . '...';
                    }
                }
            }

            if ($entryContent === null || $entryContent === '') {
                $entryContent = ' ';
            }

            $entry = $document->createElementNS(self::ATOM_NS, 'entry');
            $feed->appendChild($entry);

            $title = $document->createElementNS(self::ATOM_NS, 'title');
            $entry->appendChild($title);
            $title->setAttribute('type', 'html');
            $title->appendChild($document->createTextNode((string) $entryTitle));

            if ((bool) $entryTimestamp === true) {
                $timestamp = gmdate(\DATE_ATOM, $entryTimestamp);

                $published = $document->createElementNS(self::ATOM_NS, 'published');
                $entry->appendChild($published);
                $published->appendChild($document->createTextNode($timestamp));

                $entryUpdated = $document->createElementNS(self::ATOM_NS, 'updated');
                $entry->appendChild($entryUpdated);
                $entryUpdated->appendChild($document->createTextNode($timestamp));
            }

            $idElem = $document->createElementNS(self::ATOM_NS, 'id');
            $entry->appendChild($idElem);
            $idElem->appendChild($document->createTextNode($entryID));

            if (isset($itemArray['itunes']) === true) {
                $feed->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:itunes', self::ITUNES_NS);
                foreach ((array) $itemArray['itunes'] as $itunesKey => $itunesValue) {
                    $itunesProperty = $document->createElementNS(self::ITUNES_NS, (string) $itunesKey);
                    $entry->appendChild($itunesProperty);
                    $itunesProperty->appendChild($document->createTextNode((string) $itunesValue));
                }
                if (isset($itemArray['enclosure']) === true) {
                    $itunesEnclosure = $document->createElementNS(self::ATOM_NS, 'enclosure');
                    $entry->appendChild($itunesEnclosure);
                    $itunesEnclosure->setAttribute('url', (string) $itemArray['enclosure']['url']);
                    $itunesEnclosure->setAttribute('length', (string) $itemArray['enclosure']['length']);
                    $itunesEnclosure->setAttribute('type', (string) $itemArray['enclosure']['type']);
                }
            } elseif ($entryUri !== null && $entryUri !== '') {
                $entryLinkAlternate = $document->createElementNS(self::ATOM_NS, 'link');
                $entry->appendChild($entryLinkAlternate);
                $entryLinkAlternate->setAttribute('rel', 'alternate');
                $entryLinkAlternate->setAttribute('type', 'text/html');
                $entryLinkAlternate->setAttribute('href', (string) $entryUri);
            }

            $authorStr = $item->getAuthor();
            if ($authorStr !== null && $authorStr !== '') {
                $authorElem = $document->createElementNS(self::ATOM_NS, 'author');
                $entry->appendChild($authorElem);
                $authorNameElem = $document->createElementNS(self::ATOM_NS, 'name');
                $authorElem->appendChild($authorNameElem);
                $authorNameElem->appendChild($document->createTextNode((string) $authorStr));
            }

            $content = $document->createElementNS(self::ATOM_NS, 'content');
            $content->setAttribute('type', 'html');
            $content->appendChild($document->createTextNode((string) $entryContent));
            $entry->appendChild($content);

            foreach ($item->getEnclosures() as $enclosure) {
                $entryEnclosure = $document->createElementNS(self::ATOM_NS, 'link');
                $entry->appendChild($entryEnclosure);
                $entryEnclosure->setAttribute('rel', 'enclosure');
                $entryEnclosure->setAttribute('type', Url::parseMimeType($enclosure));
                $entryEnclosure->setAttribute('href', (string) $enclosure);
            }

            foreach ($item->getCategories() as $category) {
                $entryCategory = $document->createElementNS(self::ATOM_NS, 'category');
                $entry->appendChild($entryCategory);
                $entryCategory->setAttribute('term', (string) $category);
            }

            $thumbnail = method_exists($item, 'getThumbnail') === true ? $item->getThumbnail() : ($itemArray['thumbnail'] ?? null);

            if ($thumbnail !== null && $thumbnail !== '') {
                $thumbnailElement = $document->createElementNS(self::MRSS_NS, 'media:thumbnail');
                $entry->appendChild($thumbnailElement);
                $thumbnailElement->setAttribute('url', (string) $thumbnail);
            }
        }

        return $document->saveXml();
    }
}
