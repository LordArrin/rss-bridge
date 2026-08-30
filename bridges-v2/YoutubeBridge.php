<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class YoutubeBridge extends BridgeAbstract
{
    public const NAME = 'YouTube';
    public const URI = 'https://www.youtube.com';
    public const DESCRIPTION = 'Returns the 10 newest videos by username/channel/playlist or search';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 10800;

    private const URI_REGEX = '/(https?:\/\/(?:www\.)?(?:[a-zA-Z0-9-.]{2,256}\.[a-z]{2,20})(\:[0-9]{2,4})?(?:\/[a-zA-Z0-9@:%_\+.,~#"\'!?&\/\/=\-*]+|\/)?)/ims';

    public const PARAMETERS = [
        'By username' => [
            'u' => [
                'name' => 'username',
                'exampleValue' => 'LinusTechTips',
                'required' => true,
            ],
        ],
        'By channel id' => [
            'c' => [
                'name' => 'channel id',
                'exampleValue' => 'UCw38-8_Ibv_L6hlKChHO9dQ',
                'required' => true,
            ],
        ],
        'By custom name' => [
            'custom' => [
                'name' => 'custom name',
                'exampleValue' => 'LinusTechTips',
                'required' => true,
            ],
        ],
        'By playlist Id' => [
            'p' => [
                'name' => 'playlist id',
                'exampleValue' => 'PL8mG-RkN2uTzJc8N0EoyhdC54prvBBLpj',
                'required' => true,
            ],
        ],
        'Search result' => [
            's' => [
                'name' => 'search keyword',
                'exampleValue' => 'LinusTechTips',
                'required' => true,
            ],
            'pa' => [
                'name' => 'page',
                'type' => 'number',
                'title' => 'This option is not work anymore, as YouTube will always return the same page',
                'exampleValue' => 1,
            ],
        ],
        'global' => [
            'duration_min' => [
                'name' => 'min. duration (minutes)',
                'type' => 'number',
                'title' => 'Minimum duration for the video in minutes',
                'exampleValue' => 5,
            ],
            'duration_max' => [
                'name' => 'max. duration (minutes)',
                'type' => 'number',
                'title' => 'Maximum duration for the video in minutes',
                'exampleValue' => 10,
            ],
            'skip_members_only' => [
                'name' => 'Skip members-only videos',
                'type' => 'checkbox',
                'title' => 'Hide videos that require a channel membership to watch',
            ],
            'skip_description' => [
                'name' => 'Skip full video description',
                'type' => 'checkbox',
                'title' => 'Do not fetch the full description for each video (uses short snippet instead, much faster)',
            ],
        ],
    ];

    private string $feedName = '';
    private string $feeduri = '';
    private string $feedIconUrl = '';

    public function collectData(): void
    {
        $cacheKey = 'rate_limit';
        if ($this->cache->get($cacheKey) !== null) {
            throwRateLimitException();
        }

        try {
            $this->collectDataInternal();
        } catch (\Exception $e) {
            if ($e->getCode() === 429) {
                $this->cache->set($cacheKey, true, 60 * 16);
                throwRateLimitException();
            }
            throw $e;
        }
    }

    public function getName(): string
    {
        switch ($this->queriedContext) {
            case 'By username':
            case 'By channel id':
            case 'By custom name':
            case 'By playlist Id':
            case 'Search result':
                return htmlspecialchars_decode($this->feedName) . ' - YouTube';
            default:
                return parent::getName();
        }
    }

    public function getURI(): string
    {
        $playlistInput = $this->getInput('p');
        if ($playlistInput !== null) {
            return self::URI . '/playlist?list=' . (string) $playlistInput;
        }

        if ($this->feeduri !== '') {
            return $this->feeduri;
        }

        return parent::getURI();
    }

    public function getIcon(): string
    {
        if ($this->feedIconUrl === '') {
            return parent::getIcon();
        }
        return $this->feedIconUrl;
    }

    private function shouldSkipDescription(): bool
    {
        $input = $this->getInput('skip_description');
        if ($input === true) {
            return true;
        }
        if ($input === 'on') {
            return true;
        }
        return false;
    }

    private function collectDataInternal(): void
    {
        $html = '';
        $url_feed = '';
        $url_listing = '';

        $username = $this->getInput('u');
        $channel = $this->getInput('c');
        $custom = $this->getInput('custom');
        $playlist = $this->getInput('p');
        $search = $this->getInput('s');

        $durationMinInput = $this->getInput('duration_min');
        $durationMaxInput = $this->getInput('duration_max');

        $durationMin = 0;
        if ($durationMinInput !== null && $durationMinInput !== '') {
            $durationMin = (int) $durationMinInput;
        }

        $durationMax = 0;
        if ($durationMaxInput !== null && $durationMaxInput !== '') {
            $durationMax = (int) $durationMaxInput;
        }

        $filterByDuration = false;
        if ($durationMin > 0) {
            $filterByDuration = true;
        }
        if ($durationMax > 0) {
            $filterByDuration = true;
        }

        $hasUsername = false;
        if ($username !== null && $username !== '') {
            $hasUsername = true;
        }

        $hasChannel = false;
        if ($channel !== null && $channel !== '') {
            $hasChannel = true;
        }

        $hasCustom = false;
        if ($custom !== null && $custom !== '') {
            $hasCustom = true;
        }

        if ($hasUsername === true) {
            $url_feed = self::URI . '/feeds/videos.xml?user=' . urlencode((string) $username);
            $url_listing = self::URI . '/user/' . urlencode((string) $username) . '/videos';
        } elseif ($hasChannel === true) {
            $url_feed = self::URI . '/feeds/videos.xml?channel_id=' . urlencode((string) $channel);
            $url_listing = self::URI . '/channel/' . urlencode((string) $channel) . '/videos';
        } elseif ($hasCustom === true) {
            $url_listing = self::URI . '/' . urlencode((string) $custom) . '/videos';
        }

        $jsonData = null;

        if ($url_feed !== '' || $url_listing !== '') {
            $this->feeduri = $url_listing;

            if ($hasCustom === true) {
                $html = $this->fetch($url_listing);
                $jsonData = $this->extractJsonFromHtml($html);

                if ($jsonData !== null && isset($jsonData->metadata->channelMetadataRenderer->rssUrl) === true) {
                    $url_feed = $jsonData->metadata->channelMetadataRenderer->rssUrl;
                    if (isset($jsonData->metadata->channelMetadataRenderer->avatar->thumbnails[0]->url) === true) {
                        $this->feedIconUrl = $jsonData->metadata->channelMetadataRenderer->avatar->thumbnails[0]->url;
                    }
                }
            }

            if ($filterByDuration === true) {
                if ($hasCustom === false) {
                    $html = $this->fetch($url_listing);
                    $jsonData = $this->extractJsonFromHtml($html);
                }

                if ($jsonData !== null && isset($jsonData->contents) === true) {
                    $jsonData = $jsonData->contents->twoColumnBrowseResultsRenderer->tabs[1] ?? null;
                    if ($jsonData !== null && isset($jsonData->tabRenderer->content->richGridRenderer->contents) === true) {
                        $jsonData = $jsonData->tabRenderer->content->richGridRenderer->contents;
                        $this->fetchItemsFromJsonData($jsonData);
                    } else {
                        throwServerException('Unable to get data from YouTube');
                    }
                } else {
                    throwServerException('Unable to get data from YouTube');
                }
            } else {
                $xmlString = $this->fetch($url_feed);
                $this->extractItemsFromXmlFeed($xmlString);
            }

            if ($html !== '') {
                $this->feedName = $this->extractPageTitle($html);
            }
        } elseif ($playlist !== null && $playlist !== '') {
            $url_feed = self::URI . '/feeds/videos.xml?playlist_id=' . urlencode((string) $playlist);
            $url_listing = self::URI . '/playlist?list=' . urlencode((string) $playlist);
            $html = $this->fetch($url_listing);
            $jsonData = $this->extractJsonFromHtml($html);

            $jsonData = $jsonData->contents->twoColumnBrowseResultsRenderer->tabs[0] ?? null;
            if ($jsonData === null) {
                throwServerException('Unable to find playlist: ' . $url_listing);
            }

            if (isset($jsonData->tabRenderer->content->sectionListRenderer->contents[0]->itemSectionRenderer->contents[0]->playlistVideoListRenderer) === true) {
                $jsonData = $jsonData->tabRenderer->content->sectionListRenderer->contents[0]->itemSectionRenderer;
                $jsonData = $jsonData->contents[0]->playlistVideoListRenderer->contents;
            } elseif (isset($jsonData->tabRenderer->content->sectionListRenderer->contents[0]->itemSectionRenderer->contents) === true) {
                $jsonData = $jsonData->tabRenderer->content->sectionListRenderer->contents[0]->itemSectionRenderer->contents;
            }

            $item_count = 0;
            if (is_array($jsonData) === true) {
                $item_count = count($jsonData);
            }

            if ($item_count > 15 || $filterByDuration === true) {
                if (is_array($jsonData) === true) {
                    $this->fetchItemsFromJsonData($jsonData);
                }
            } else {
                $xmlString = $this->fetch($url_feed);
                $this->extractItemsFromXmlFeed($xmlString);
            }

            $this->feedName = 'Playlist: ' . $this->extractPageTitle($html);

            usort($this->items, function ($item1, $item2): int {
                return $this->getItemTimestamp($item2) - $this->getItemTimestamp($item1);
            });
        } elseif ($search !== null && $search !== '') {
            $today_filter = 'EgIIAg';
            $url_listing = self::URI . '/results?sp=' . $today_filter . '&search_query=' . urlencode((string) $search);

            if (preg_match('/\b(before|after):/i', (string) $search) !== 1) {
                $html = $this->fetch($url_listing . urlencode(' after:' . date('Y-m-d', strtotime('-6 hours'))));
            } else {
                $html = $this->fetch($url_listing);
            }

            $jsonData = $this->extractJsonFromHtml($html);

            if ($jsonData !== null && isset($jsonData->contents->twoColumnSearchResultsRenderer->primaryContents) === true) {
                $jsonData = $jsonData->contents->twoColumnSearchResultsRenderer->primaryContents;
                $jsonData = $jsonData->sectionListRenderer->contents[0]->itemSectionRenderer->contents ?? [];
                if (is_array($jsonData) === true) {
                    $this->fetchItemsFromJsonData($jsonData);
                }
            }

            $this->feeduri = $url_listing;
            $this->feedName = 'Search: ' . $search;
        } else {
            throwClientException(
                "You must specify one of:\n - YouTube username (?u=...)\n - Channel id (?c=...)\n - Playlist id (?p=...)\n - Search (?s=...)"
            );
        }
    }

    private function getItemTimestamp(array $item): int
    {
        $timestamp = $item['timestamp'] ?? null;

        if (is_int($timestamp) === true) {
            return $timestamp;
        }

        if (is_string($timestamp) === true) {
            $parsed = strtotime($timestamp);
            if ($parsed !== false) {
                return $parsed;
            }
        }

        return 0;
    }

    private function extractPageTitle(string $htmlString): string
    {
        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($htmlString);
        libxml_use_internal_errors(false);

        $titleNode = $dom->querySelector('title');
        if ($titleNode === null) {
            return '';
        }

        $title = trim($titleNode->textContent);
        return str_replace(' - YouTube', '', $title);
    }

    private function fetchVideoDetails(string $videoId, ?string &$author, ?string &$description, ?int &$timestamp): void
    {
        $url = self::URI . '/watch?v=' . $videoId;
        $htmlString = $this->fetch($url, true);

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($htmlString);
        libxml_use_internal_errors(false);

        $bodyHtml = '';
        if ($dom->documentElement !== null) {
            $bodyHtml = $dom->documentElement->innerHTML;
        }

        if (str_contains($bodyHtml, 'IS_UNAVAILABLE_PAGE') === true) {
            return;
        }

        $elAuthor = $dom->querySelector('span[itemprop=author] > link[itemprop=name]');
        if ($elAuthor !== null) {
            $authorContent = $elAuthor->getAttribute('content');
            if ($authorContent !== null && $authorContent !== '') {
                $author = $authorContent;
            }
        }

        $elDatePublished = $dom->querySelector('meta[itemprop=datePublished]');
        if ($elDatePublished !== null) {
            $dateContent = $elDatePublished->getAttribute('content');
            if ($dateContent !== null && $dateContent !== '') {
                $parsed = strtotime($dateContent);
                if ($parsed !== false) {
                    $timestamp = $parsed;
                }
            }
        }

        $jsonData = $this->extractJsonFromHtml($htmlString);
        if ($jsonData === null || isset($jsonData->contents) === false) {
            return;
        }

        $contents = $jsonData->contents->twoColumnWatchNextResults->results->results->contents ?? null;
        if ($contents === null) {
            throwServerException('Unable to find json data');
        }

        $videoSecondaryInfo = null;
        if (is_array($contents) === true) {
            foreach ($contents as $item) {
                if (isset($item->videoSecondaryInfoRenderer) === true) {
                    $videoSecondaryInfo = $item->videoSecondaryInfoRenderer;
                    break;
                }
            }
        }

        if ($videoSecondaryInfo === null) {
            throwServerException('Could not find videoSecondaryInfoRenderer. Error at: ' . $videoId);
        }

        $description = $videoSecondaryInfo->attributedDescription->content ?? '';

        $whitespaceChars = " \t\n\r\0\x0B\u{A0}\u{2060}\u{202F}\u{2007}";
        $descEnhancements = $this->ytBridgeGetVideoDescriptionEnhancements(
            $videoSecondaryInfo,
            (string) $description,
            self::URI,
            $whitespaceChars
        );

        foreach ($descEnhancements as $descEnhancement) {
            if (isset($descEnhancement['url']) === true) {
                $descBefore = mb_substr((string) $description, 0, $descEnhancement['pos']);
                $descValue = mb_substr((string) $description, $descEnhancement['pos'], $descEnhancement['len']);
                $descAfter = mb_substr((string) $description, $descEnhancement['pos'] + $descEnhancement['len']);

                $descValue = trim($descValue, $whitespaceChars . '•/');

                $description = sprintf(
                    '%s<a href="%s" target="_blank">%s</a>%s',
                    $descBefore,
                    htmlspecialchars((string) $descEnhancement['url'], ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($descValue, ENT_QUOTES, 'UTF-8'),
                    $descAfter
                );
            }
        }
    }

    private function resolveCommandUrl(object $commandMetadata, string $baseUrl): string
    {
        $rawUrl = (string) $commandMetadata->url;
        $parsed = parse_url($rawUrl);

        if ($parsed === false) {
            return $baseUrl . $rawUrl;
        }

        $isRedirect = false;
        if (array_key_exists('path', $parsed) === true && $parsed['path'] === '/redirect') {
            $isRedirect = true;
        }

        if ($isRedirect === true) {
            parse_str((string) ($parsed['query'] ?? ''), $queryParts);
            return urldecode((string) ($queryParts['q'] ?? ''));
        }

        if (array_key_exists('host', $parsed) === true) {
            return $rawUrl;
        }

        return $baseUrl . $rawUrl;
    }

    private function ytBridgeGetVideoDescriptionEnhancements(
        object $videoSecondaryInfo,
        string $descriptionContent,
        string $baseUrl,
        string $whitespaceChars
    ): array {
        $commandRuns = $videoSecondaryInfo->attributedDescription->commandRuns ?? [];
        if (count($commandRuns) <= 0) {
            return [];
        }

        $enhancements = [];

        $boundaryWhitespaceChars = mb_str_split($whitespaceChars);
        $boundaryStartChars = array_merge($boundaryWhitespaceChars, [':', '-', '(']);
        $boundaryEndChars = array_merge($boundaryWhitespaceChars, [',', '.', "'", ')']);
        $hashtagBoundaryEndChars = array_merge($boundaryEndChars, ['#', '-']);

        $descriptionContentLength = mb_strlen($descriptionContent);

        $minPositionOffset = 0;

        $prevStartPosition = 0;
        $totalLength = 0;
        $maxPositionByStartIndex = [];
        foreach (array_reverse($commandRuns) as $commandRun) {
            $endPosition = $commandRun->startIndex + $commandRun->length;
            if ($endPosition < $prevStartPosition) {
                $totalLength += 1;
            }
            $totalLength += $commandRun->length;
            $maxPositionByStartIndex[$commandRun->startIndex] = $totalLength;
            $prevStartPosition = $commandRun->startIndex;
        }

        foreach ($commandRuns as $commandRun) {
            $commandMetadata = $commandRun->onTap->innertubeCommand->commandMetadata->webCommandMetadata ?? null;
            if (isset($commandMetadata) === false) {
                continue;
            }

            $enhancement = null;

            $isHashtag = false;
            if (($commandMetadata->webPageType ?? '') === 'WEB_PAGE_TYPE_BROWSE') {
                $isHashtag = true;
            }

            $prevEnhancement = end($enhancements);
            $minPosition = 0;
            if ($prevEnhancement !== false) {
                $minPosition = $prevEnhancement['pos'] + $prevEnhancement['len'];
            }

            $maxPosition = $descriptionContentLength - ($maxPositionByStartIndex[$commandRun->startIndex] ?? 0);
            $position = min($commandRun->startIndex - $minPositionOffset, $maxPosition);

            while ($position >= $minPosition) {
                $newLinePosition = mb_strpos($descriptionContent, "\n", $position);
                if ($newLinePosition !== false && $newLinePosition < $position + ($commandRun->length - 1)) {
                    $position = $newLinePosition - ($commandRun->length - 1);
                    continue;
                }

                $firstChar = mb_substr($descriptionContent, $position, 1);
                $boundaryStart = mb_substr($descriptionContent, $position - 1, 1);
                $boundaryEndIndex = $position + $commandRun->length;
                $boundaryEnd = mb_substr($descriptionContent, $boundaryEndIndex, 1);

                $boundaryStartIsValid = false;
                if ($position === 0) {
                    $boundaryStartIsValid = true;
                } elseif (in_array($boundaryStart, $boundaryStartChars, true) === true) {
                    $boundaryStartIsValid = true;
                } elseif ($isHashtag === true && $firstChar === '#') {
                    $boundaryStartIsValid = true;
                }

                $boundaryEndIsValid = false;
                if ($boundaryEndIndex === $descriptionContentLength) {
                    $boundaryEndIsValid = true;
                } elseif ($isHashtag === true) {
                    if (in_array($boundaryEnd, $hashtagBoundaryEndChars, true) === true) {
                        $boundaryEndIsValid = true;
                    }
                } else {
                    if (in_array($boundaryEnd, $boundaryEndChars, true) === true) {
                        $boundaryEndIsValid = true;
                    }
                }

                if ($boundaryStartIsValid === true && $boundaryEndIsValid === true) {
                    $minPositionOffset = $commandRun->startIndex - $position;
                    $enhancement = [
                        'pos' => $position,
                        'len' => $commandRun->length,
                    ];
                    break;
                }

                $position--;
            }

            if (isset($enhancement) === false) {
                continue;
            }

            $lastChar = mb_substr($descriptionContent, $enhancement['pos'] + $enhancement['len'] - 1, 1);
            if ($lastChar === "\n") {
                $enhancement['len'] -= 1;
            }

            $enhancement['url'] = $this->resolveCommandUrl($commandMetadata, $baseUrl);

            $enhancements[] = $enhancement;
        }

        if (count($enhancements) !== count($commandRuns)) {
            return [];
        }

        return array_reverse($enhancements);
    }

    private function extractItemsFromXmlFeed(string $xmlString): void
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlString);
        libxml_use_internal_errors(false);

        if ($xml === false) {
            throwServerException('Failed to parse XML feed');
        }

        $xml->registerXPathNamespace('media', 'http://search.yahoo.com/mrss/');
        $xml->registerXPathNamespace('yt', 'http://www.youtube.com/xml/schemas/2015');

        $titleNodes = $xml->xpath('title');
        if ($titleNodes !== false && count($titleNodes) > 0) {
            $this->feedName = $this->decodeTitle((string) $titleNodes[0]);
        }

        $entries = $xml->xpath('entry');
        if ($entries === false) {
            $entries = [];
        }

        foreach ($entries as $element) {
            $idStr = (string) $element->id;
            $videoId = str_replace('yt:video:', '', $idStr);

            if (str_contains($videoId, 'googleads') === true) {
                continue;
            }

            $title = $this->decodeTitle((string) $element->title);
            $author = (string) $element->author->name;

            $descNodes = $element->xpath('media:group/media:description');
            $desc = '';
            if ($descNodes !== false && count($descNodes) > 0) {
                $desc = (string) $descNodes[0];
            }

            $desc = htmlspecialchars($desc, ENT_QUOTES, 'UTF-8');
            $desc = nl2br($desc);
            $desc = preg_replace(self::URI_REGEX, '<a href="$1" target="_blank">$1</a> ', $desc) ?? $desc;

            $published = (string) $element->published;
            $time = time();
            if ($published !== '') {
                $parsedTime = strtotime($published);
                if ($parsedTime !== false) {
                    $time = $parsedTime;
                }
            }

            $this->addItem($videoId, $title, $author, $desc, $time);
        }
    }

    private function fetch(string $url, bool $cache = false): string
    {
        $cacheKey = 'yt_fetch_' . md5($url);
        $ttl = 86400 * 3;

        if ($cache === true) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return (string) $cached;
            }
        }

        $headers = ['Accept-Language: en-US'];
        $html = getContents($url, $headers);

        if ($cache === true && $html !== '') {
            $this->cache->set($cacheKey, $html, $ttl);
        }

        return $html;
    }

    private function extractJsonFromHtml(string $html): ?object
    {
        $previousBacktrackLimit = @ini_get('pcre.backtrack_limit');
        @ini_set('pcre.backtrack_limit', '10000000');

        $scriptRegex = '/var ytInitialData = (.*?);<\/script>/';
        $result = preg_match($scriptRegex, $html, $matches);

        if ($previousBacktrackLimit !== false) {
            @ini_set('pcre.backtrack_limit', $previousBacktrackLimit);
        }

        if ($result === 0 || $result === false) {
            return null;
        }

        $data = json_decode((string) $matches[1], false);

        if (is_object($data) === false) {
            return null;
        }

        return $data;
    }

    private function fetchItemsFromJsonData(array $jsonData): void
    {
        $durationMinInput = $this->getInput('duration_min');
        $durationMaxInput = $this->getInput('duration_max');

        $durationMinVal = 0;
        if ($durationMinInput !== null && $durationMinInput !== '') {
            $durationMinVal = (int) $durationMinInput;
        }

        $durationMaxVal = 0;
        if ($durationMaxInput !== null && $durationMaxInput !== '') {
            $durationMaxVal = (int) $durationMaxInput;
        }

        $minimumDurationSeconds = -1;
        if ($durationMinVal > 0) {
            $minimumDurationSeconds = $durationMinVal * 60;
        }

        $maximumDurationSeconds = INF;
        if ($durationMaxVal > 0) {
            $maximumDurationSeconds = $durationMaxVal * 60;
        }

        $skipDescription = $this->shouldSkipDescription();

        foreach ($jsonData as $item) {
            if (is_object($item) === false) {
                continue;
            }

            $wrapper = null;
            if (isset($item->gridVideoRenderer) === true) {
                $wrapper = $item->gridVideoRenderer;
            } elseif (isset($item->videoRenderer) === true) {
                $wrapper = $item->videoRenderer;
            } elseif (isset($item->playlistVideoRenderer) === true) {
                $wrapper = $item->playlistVideoRenderer;
            } elseif (isset($item->richItemRenderer->content->videoRenderer) === true) {
                $wrapper = $item->richItemRenderer->content->videoRenderer;
            } elseif (isset($item->richItemRenderer->content->lockupViewModel) === true) {
                $wrapper = $this->wrapLockupViewModel($item->richItemRenderer->content->lockupViewModel);
                if ($wrapper === null) {
                    continue;
                }
            } elseif (isset($item->lockupViewModel) === true) {
                $wrapper = $this->wrapLockupViewModel($item->lockupViewModel);
                if ($wrapper === null) {
                    continue;
                }
            } else {
                continue;
            }

            $lengthText = $wrapper->lengthText->simpleText ?? null;
            $viewCount = $wrapper->viewCountText->simpleText ?? null;
            $videoId = $wrapper->videoId ?? null;

            if ($videoId === null || $videoId === '') {
                continue;
            }

            $title = $wrapper->title->runs[0]->text ?? ($wrapper->title->accessibility->accessibilityData->label ?? null);
            $author = null;
            $description = $wrapper->descriptionSnippet->runs[0]->text ?? null;
            $publishedTimeText = $wrapper->publishedTimeText->simpleText ?? ($wrapper->videoInfo->runs[2]->text ?? null);

            $timestamp = null;
            if ($publishedTimeText !== null && $publishedTimeText !== '') {
                try {
                    $publicationDate = new \DateTimeImmutable((string) $publishedTimeText);
                    $publicationDate = $publicationDate->setTime(0, 0, 0);
                    $timestamp = $publicationDate->getTimestamp();
                } catch (\Exception $e) {
                    $timestamp = null;
                }
            }

            $durationText = 0;
            if ($lengthText !== null && $lengthText !== '') {
                $durationText = $lengthText;
            } else {
                $overlays = $wrapper->thumbnailOverlays ?? [];
                if (is_array($overlays) === true) {
                    foreach ($overlays as $overlay) {
                        if (isset($overlay->thumbnailOverlayTimeStatusRenderer->text) === true) {
                            $durationText = $overlay->thumbnailOverlayTimeStatusRenderer->text;
                            break;
                        }
                    }
                }
            }

            if (is_string($durationText) === true) {
                if (preg_match('/([\d]{1,2})\:([\d]{1,2})\:([\d]{2})/', $durationText) === 1) {
                    $durationText = preg_replace('/([\d]{1,2})\:([\d]{1,2})\:([\d]{2})/', '$1:$2:$3', $durationText) ?? $durationText;
                } else {
                    $durationText = preg_replace('/([\d]{1,2})\:([\d]{2})/', '00:$1:$2', $durationText) ?? $durationText;
                }

                $hours = 0;
                $minutes = 0;
                $seconds = 0;
                sscanf((string) $durationText, '%d:%d:%d', $hours, $minutes, $seconds);
                $duration = $hours * 3600 + $minutes * 60 + $seconds;

                if ($duration < $minimumDurationSeconds || $duration > $maximumDurationSeconds) {
                    continue;
                }
            }

            $shouldFetchDetails = false;
            if ($skipDescription === false) {
                if ($description === null || $description === '' || $timestamp === null) {
                    $shouldFetchDetails = true;
                }
            }

            if ($shouldFetchDetails === true) {
                $this->fetchVideoDetails((string) $videoId, $author, $description, $timestamp);
            }

            if ($timestamp === null) {
                $timestamp = time();
            }

            $this->addItem(
                (string) $videoId,
                (string) ($title ?? 'Untitled'),
                $author,
                (string) ($description ?? ''),
                (int) $timestamp
            );

            if (count($this->items) >= 99) {
                break;
            }
        }
    }

    private function wrapLockupViewModel(object $lockup): ?object
    {
        $videoId = $lockup->contentId ?? null;
        $title = $lockup->metadata->lockupMetadataViewModel->title->content ?? null;

        if ($videoId === null || $videoId === '' || $title === null || $title === '') {
            return null;
        }

        $skipMembersOnlyInput = $this->getInput('skip_members_only');
        $skipMembersOnly = false;
        if ($skipMembersOnlyInput === true) {
            $skipMembersOnly = true;
        }
        if ($skipMembersOnlyInput === 'on') {
            $skipMembersOnly = true;
        }

        if ($skipMembersOnly === true) {
            $rows = $lockup->metadata->lockupMetadataViewModel->metadata->contentMetadataViewModel->metadataRows ?? [];
            if (is_array($rows) === true) {
                foreach ($rows as $row) {
                    $badges = $row->badges ?? [];
                    if (is_array($badges) === true) {
                        foreach ($badges as $badge) {
                            $badgeStyle = $badge->badgeViewModel->badgeStyle ?? null;
                            if ($badgeStyle === 'BADGE_MEMBERS_ONLY') {
                                return null;
                            }
                        }
                    }
                }
            }
        }

        $wrapper = new \stdClass();
        $wrapper->videoId = $videoId;
        $wrapper->title = (object) ['runs' => [(object) ['text' => $title]]];
        $wrapper->thumbnailOverlays = [];

        $contentImage = $lockup->contentImage ?? null;
        if ($contentImage !== null && isset($contentImage->thumbnailViewModel->overlays) === true) {
            $overlays = $contentImage->thumbnailViewModel->overlays;
            if (is_array($overlays) === true) {
                foreach ($overlays as $overlay) {
                    $badges = $overlay->thumbnailBottomOverlayViewModel->badges ?? [];
                    if (is_array($badges) === true) {
                        foreach ($badges as $badge) {
                            $text = $badge->thumbnailBadgeViewModel->text ?? null;
                            if (is_string($text) === true && preg_match('/^\d{1,2}(:\d{2}){1,2}$/', $text) === 1) {
                                $wrapper->lengthText = (object) ['simpleText' => $text];
                                break 2;
                            }
                        }
                    }
                }
            }
        }

        return $wrapper;
    }

    private function addItem(
        string $videoId,
        string $title,
        ?string $author,
        string $description,
        int $timestamp,
        string $thumbnail = ''
    ): void {
        $description = nl2br($description);

        $item = [];
        $item['id'] = $videoId;
        $item['title'] = $title;
        $item['author'] = $author ?? '';
        $item['timestamp'] = $timestamp;
        $item['uri'] = self::URI . '/watch?v=' . $videoId;

        if ($thumbnail === '') {
            $thumbnail = '0';
        }

        $thumbnailUri = str_replace('/www.', '/img.', self::URI) . '/vi/' . $videoId . '/' . $thumbnail . '.jpg';
        $item['content'] = sprintf(
            '<a href="%s"><img src="%s" /></a><br />%s',
            htmlspecialchars($item['uri'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($thumbnailUri, ENT_QUOTES, 'UTF-8'),
            $description
        );
        $item['uid'] = $videoId;

        $this->items[] = $item;
    }

    private function decodeTitle(string $title): string
    {
        return html_entity_decode($title, ENT_QUOTES, 'UTF-8');
    }
}
