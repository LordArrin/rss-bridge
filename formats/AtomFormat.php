<?php

declare(strict_types=1);

namespace RSSBridge\Formats;

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
        $document = new \DomDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $feedUrl = get_current_url();

        $feed = $document->createElementNS(self::ATOM_NS, 'feed');
        $document->appendChild($feed);
        $feed->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:media', self::MRSS_NS);

        $feedArray = $this->getFeed();
        foreach ($feedArray as $feedKey => $feedValue) {
            if (in_array($feedKey, ['donationUri'], true) === true) {
                continue;
            }
            if ($feedKey === 'name') {
                $title = $document->createElement('title');
                $feed->appendChild($title);
                $title->setAttribute('type', 'text');
                $title->appendChild($document->createTextNode((string) $feedValue));
            } elseif ($feedKey === 'icon') {
                if ((bool) $feedValue === true) {
                    $icon = $document->createElement('icon');
                    $feed->appendChild($icon);
                    $icon->appendChild($document->createTextNode((string) $feedValue));

                    $logo = $document->createElement('logo');
                    $feed->appendChild($logo);
                    $logo->appendChild($document->createTextNode((string) $feedValue));
                }
            } elseif ($feedKey === 'uri') {
                if ((bool) $feedValue === true) {
                    $linkAlternate = $document->createElement('link');
                    $feed->appendChild($linkAlternate);
                    $linkAlternate->setAttribute('rel', 'alternate');
                    $linkAlternate->setAttribute('type', 'text/html');
                    $linkAlternate->setAttribute('href', (string) $feedValue);

                    $linkSelf = $document->createElement('link');
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
                $element = $document->createElement((string) $feedKey);
                $feed->appendChild($element);
                $element->appendChild($document->createTextNode((string) $feedValue));
            }
        }

        $id = $document->createElement('id');
        $feed->appendChild($id);
        $id->appendChild($document->createTextNode($feedUrl));

        $updated = $document->createElement('updated');
        $feed->appendChild($updated);
        $updated->appendChild($document->createTextNode(gmdate(\DATE_ATOM, $this->getLastModified())));

        $feedAuthor = 'RSS-Bridge';
        $author = $document->createElement('author');
        $feed->appendChild($author);
        $authorName = $document->createElement('name');
        $author->appendChild($authorName);
        $authorName->appendChild($document->createTextNode($feedAuthor));

        foreach ($this->getItems() as $item) {
            $itemArray = $item->toArray();
            $entryTimestamp = $item->getTimestamp();
            $entryTitle = $item->getTitle();
            $entryContent = $item->getContent();
            $entryUri = $item->getURI();
            $entryID = '';

            if (empty($item->getUid()) === false) {
                $entryID = 'urn:sha1:' . $item->getUid();
            }

            if (empty($entryID) === true) {
                $entryID = $entryUri;
            }

            if (empty($entryID) === true) {
                $entryID = 'urn:sha1:' . hash('sha1', (string) $entryTitle . (string) $entryContent);
            }

            if (empty($entryTitle) === true) {
                $entryTitle = str_replace("\n", ' ', strip_tags((string) $entryContent));
                if (strlen($entryTitle) > 140) {
                    $wrapPos = strpos(wordwrap($entryTitle, 140), "\n");
                    $entryTitle = substr($entryTitle, 0, $wrapPos) . '...';
                }
            }

            if (empty($entryContent) === true) {
                $entryContent = ' ';
            }

            $entry = $document->createElement('entry');
            $feed->appendChild($entry);

            $title = $document->createElement('title');
            $entry->appendChild($title);
            $title->setAttribute('type', 'html');
            $title->appendChild($document->createTextNode((string) $entryTitle));

            if ((bool) $entryTimestamp === true) {
                $timestamp = gmdate(\DATE_ATOM, $entryTimestamp);

                $published = $document->createElement('published');
                $entry->appendChild($published);
                $published->appendChild($document->createTextNode($timestamp));

                $updated = $document->createElement('updated');
                $entry->appendChild($updated);
                $updated->appendChild($document->createTextNode($timestamp));
            }

            $id = $document->createElement('id');
            $entry->appendChild($id);
            $id->appendChild($document->createTextNode($entryID));

            if (isset($itemArray['itunes']) === true) {
                $feed->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:itunes', self::ITUNES_NS);
                foreach ((array) $itemArray['itunes'] as $itunesKey => $itunesValue) {
                    $itunesProperty = $document->createElementNS(self::ITUNES_NS, (string) $itunesKey);
                    $entry->appendChild($itunesProperty);
                    $itunesProperty->appendChild($document->createTextNode((string) $itunesValue));
                }
                if (isset($itemArray['enclosure']) === true) {
                    $itunesEnclosure = $document->createElement('enclosure');
                    $entry->appendChild($itunesEnclosure);
                    $itunesEnclosure->setAttribute('url', (string) $itemArray['enclosure']['url']);
                    $itunesEnclosure->setAttribute('length', (string) $itemArray['enclosure']['length']);
                    $itunesEnclosure->setAttribute('type', (string) $itemArray['enclosure']['type']);
                }
            } elseif (empty($entryUri) === false) {
                $entryLinkAlternate = $document->createElement('link');
                $entry->appendChild($entryLinkAlternate);
                $entryLinkAlternate->setAttribute('rel', 'alternate');
                $entryLinkAlternate->setAttribute('type', 'text/html');
                $entryLinkAlternate->setAttribute('href', (string) $entryUri);
            }

            if (empty($item->getAuthor()) === false) {
                $author = $document->createElement('author');
                $entry->appendChild($author);
                $authorName = $document->createElement('name');
                $author->appendChild($authorName);
                $authorName->appendChild($document->createTextNode((string) $item->getAuthor()));
            }

            $content = $document->createElement('content');
            $content->setAttribute('type', 'html');
            $content->appendChild($document->createTextNode((string) $entryContent));
            $entry->appendChild($content);

            foreach ($item->getEnclosures() as $enclosure) {
                $entryEnclosure = $document->createElement('link');
                $entry->appendChild($entryEnclosure);
                $entryEnclosure->setAttribute('rel', 'enclosure');
                $entryEnclosure->setAttribute('type', parse_mime_type($enclosure));
                $entryEnclosure->setAttribute('href', (string) $enclosure);
            }

            foreach ($item->getCategories() as $category) {
                $entryCategory = $document->createElement('category');
                $entry->appendChild($entryCategory);
                $entryCategory->setAttribute('term', (string) $category);
            }

            $thumbnail = method_exists($item, 'getThumbnail') === true ? $item->getThumbnail() : ($itemArray['thumbnail'] ?? null);

            if (empty($thumbnail) === false) {
                $thumbnailElement = $document->createElementNS(self::MRSS_NS, 'thumbnail');
                $entry->appendChild($thumbnailElement);
                $thumbnailElement->setAttribute('url', (string) $thumbnail);
            }
        }

        return $document->saveXML();
    }
}
