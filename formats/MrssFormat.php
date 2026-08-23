<?php

declare(strict_types=1);

namespace RSSBridge\Formats;

/**
 * MrssFormat - RSS 2.0 + Media RSS
 * http://www.rssboard.org/rss-specification
 * http://www.rssboard.org/media-rss
 *
 * Validators:
 * https://validator.w3.org/feed/
 * http://www.rssboard.org/rss-validator/
 */
final class MrssFormat extends FormatAbstract
{
    public const MIME_TYPE = 'application/rss+xml';

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

        $feed = $document->createElement('rss');
        $document->appendChild($feed);
        $feed->setAttribute('version', '2.0');
        $feed->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:atom', self::ATOM_NS);
        $feed->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:media', self::MRSS_NS);

        $channel = $document->createElement('channel');
        $feed->appendChild($channel);

        $feedArray = $this->getFeed();
        $uri = $feedArray['uri'] ?? '';
        $title = $feedArray['name'] ?? '';

        foreach ($feedArray as $feedKey => $feedValue) {
            if (in_array($feedKey, ['atom', 'donationUri'], true) === true) {
                continue;
            }
            if ($feedKey === 'name') {
                $channelTitle = $document->createElement('title');
                $channel->appendChild($channelTitle);
                $channelTitle->appendChild($document->createTextNode((string) $title));

                $description = $document->createElement('description');
                $channel->appendChild($description);
                $description->appendChild($document->createTextNode((string) $title));
            } elseif ($feedKey === 'uri') {
                $link = $document->createElement('link');
                $channel->appendChild($link);
                $link->appendChild($document->createTextNode((string) $uri));

                $linkAlternate = $document->createElementNS(self::ATOM_NS, 'link');
                $channel->appendChild($linkAlternate);
                $linkAlternate->setAttribute('rel', 'alternate');
                $linkAlternate->setAttribute('type', 'text/html');
                $linkAlternate->setAttribute('href', (string) $uri);

                $linkSelf = $document->createElementNS(self::ATOM_NS, 'link');
                $channel->appendChild($linkSelf);
                $linkSelf->setAttribute('rel', 'self');
                $linkSelf->setAttribute('type', 'application/atom+xml');
                $feedUrl = get_current_url();
                $linkSelf->setAttribute('href', $feedUrl);
            } elseif ($feedKey === 'icon') {
                $icon = $feedValue;
                if ((bool) $icon === true) {
                    $feedImage = $document->createElement('image');
                    $channel->appendChild($feedImage);
                    $iconUrl = $document->createElement('url');
                    $iconUrl->appendChild($document->createTextNode((string) $icon));
                    $feedImage->appendChild($iconUrl);
                    $iconTitle = $document->createElement('title');
                    $iconTitle->appendChild($document->createTextNode((string) $title));
                    $feedImage->appendChild($iconTitle);
                    $iconLink = $document->createElement('link');
                    $iconLink->appendChild($document->createTextNode((string) $uri));
                    $feedImage->appendChild($iconLink);
                }
            } elseif ($feedKey === 'itunes') {
                $feed->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:itunes', self::ITUNES_NS);
                foreach ((array) $feedValue as $itunesKey => $itunesValue) {
                    $itunesProperty = $document->createElementNS(self::ITUNES_NS, (string) $itunesKey);
                    $channel->appendChild($itunesProperty);
                    $itunesProperty->appendChild($document->createTextNode((string) $itunesValue));
                }
            } else {
                $element = $document->createElement((string) $feedKey);
                $channel->appendChild($element);
                $element->appendChild($document->createTextNode((string) $feedValue));
            }
        }

        foreach ($this->getItems() as $item) {
            $itemArray = $item->toArray();
            $itemTimestamp = $item->getTimestamp();
            $itemTitle = $item->getTitle();
            $itemUri = $item->getURI();
            $itemContent = $item->getContent() ?? '';
            $itemUid = $item->getUid();
            $isPermaLink = 'false';

            if (empty($itemUid) === true && empty($itemUri) === false) {
                $itemUid = $itemUri;
                $isPermaLink = 'true';
            }

            if (empty($itemUid) === true) {
                $itemUid = hash('sha1', (string) $itemTitle . $itemContent);
            }

            $entry = $document->createElement('item');
            $channel->appendChild($entry);

            if (empty($itemTitle) === false) {
                $entryTitle = $document->createElement('title');
                $entry->appendChild($entryTitle);
                $entryTitle->appendChild($document->createTextNode((string) $itemTitle));
            }

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
            }

            if (empty($itemUri) === false) {
                $entryLink = $document->createElement('link');
                $entry->appendChild($entryLink);
                $entryLink->appendChild($document->createTextNode((string) $itemUri));
            }

            $entryGuid = $document->createElement('guid');
            $entryGuid->setAttribute('isPermaLink', $isPermaLink);
            $entry->appendChild($entryGuid);
            $entryGuid->appendChild($document->createTextNode((string) $itemUid));

            if (empty($itemTimestamp) === false) {
                $entryPublished = $document->createElement('pubDate');
                $entry->appendChild($entryPublished);
                $entryPublished->appendChild($document->createTextNode(gmdate(\DATE_RFC2822, $itemTimestamp)));
            }

            if (empty($itemContent) === false) {
                $entryDescription = $document->createElement('description');
                $entry->appendChild($entryDescription);
                $entryDescription->appendChild($document->createTextNode($itemContent));
            }

            foreach ($item->getEnclosures() as $enclosure) {
                $entryEnclosure = $document->createElementNS(self::MRSS_NS, 'content');
                $entry->appendChild($entryEnclosure);
                $entryEnclosure->setAttribute('url', (string) $enclosure);
                $entryEnclosure->setAttribute('type', parse_mime_type($enclosure));
            }

            foreach ($item->getCategories() as $category) {
                $entryCategory = $document->createElement('category');
                $entry->appendChild($entryCategory);
                $entryCategory->appendChild($document->createTextNode((string) $category));
            }
        }

        return $document->saveXML();
    }
}
