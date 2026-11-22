<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

use function urljoin;

final class YoutubeBridge extends BridgeAbstract
{
    public const NAME = 'YouTube';
    public const URI = 'https://www.youtube.com';
    public const DESCRIPTION = 'Returns the 10 newest videos by username/channel/playlist or search';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 10800;

    private const ATOM_NS = 'http://www.w3.org/2005/Atom';
    private const MEDIA_NS = 'http://search.yahoo.com/mrss/';
    private const MAX_ITEMS = 99;
    private const PLAYLIST_XML_LIMIT = 15;
    private const DESCRIPTION_WHITESPACE = " \t\n\r\0\x0B\u{A0}\u{2060}\u{202F}\u{2007}";
    private const URI_REGEX = '/(https?:\/\/(?:www\.)?[a-zA-Z0-9.-]{2,256}\.[a-z]{2,20}(?::[0-9]{2,4})?(?:\/[a-zA-Z0-9@:%_+.,~#"\'!?&\/=-]*)?)/i';

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
        if ($this->cache === null) {
            $this->collectDataInternal();
            return;
        }

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
        $playlist = $this->stringInput('p');
        if ($playlist !== '') {
            return self::URI . '/playlist?list=' . $playlist;
        }

        if ($this->feeduri !== '') {
            return $this->feeduri;
        }

        return parent::getURI();
    }

    public function getIcon(): string
    {
        return $this->feedIconUrl !== '' ? $this->feedIconUrl : parent::getIcon();
    }

    private function collectDataInternal(): void
    {
        [$urlFeed, $urlListing, $isCustom] = $this->resolveSources();

        if ($urlListing !== '') {
            $this->collectFromChannel($urlFeed, $urlListing, $isCustom);
            return;
        }

        $playlist = $this->stringInput('p');
        if ($playlist !== '') {
            $this->collectFromPlaylist($playlist);
            return;
        }

        $search = $this->stringInput('s');
        if ($search !== '') {
            $this->collectFromSearch($search);
            return;
        }

        throwClientException(
            "You must specify one of:\n - YouTube username (?u=...)\n - Channel id (?c=...)\n - Playlist id (?p=...)\n - Search (?s=...)"
        );
    }

    /**
     * @return array{0: string, 1: string, 2: bool}
     */
    private function resolveSources(): array
    {
        $username = $this->stringInput('u');
        if ($username !== '') {
            return [
                self::URI . '/feeds/videos.xml?user=' . urlencode($username),
                self::URI . '/user/' . urlencode($username) . '/videos',
                false,
            ];
        }

        $channel = $this->stringInput('c');
        if ($channel !== '') {
            return [
                self::URI . '/feeds/videos.xml?channel_id=' . urlencode($channel),
                self::URI . '/channel/' . urlencode($channel) . '/videos',
                false,
            ];
        }

        $custom = $this->stringInput('custom');
        if ($custom !== '') {
            return ['', self::URI . '/' . urlencode($custom) . '/videos', true];
        }

        return ['', '', false];
    }

    private function collectFromChannel(string $urlFeed, string $urlListing, bool $isCustom): void
    {
        $this->feeduri = $urlListing;
        $html = '';
        $jsonData = null;

        if ($isCustom === true) {
            $html = $this->fetch($urlListing);
            $jsonData = $this->extractJsonFromHtml($html);
            $renderer = $jsonData->metadata->channelMetadataRenderer ?? null;

            if (is_object($renderer) === true) {
                $rssUrl = $renderer->rssUrl ?? null;
                if (is_string($rssUrl) === true && $rssUrl !== '') {
                    $urlFeed = $rssUrl;
                }

                $avatar = $renderer->avatar->thumbnails[0]->url ?? null;
                if (is_string($avatar) === true && $avatar !== '') {
                    $this->feedIconUrl = $avatar;
                }
            }
        }

        if ($this->hasDurationFilter() === true) {
            if ($isCustom === false) {
                $html = $this->fetch($urlListing);
                $jsonData = $this->extractJsonFromHtml($html);
            }

            if ($jsonData === null) {
                throwServerException('Unable to extract ytInitialData from ' . $urlListing);
            }

            $grid = $this->extractTabGrid($jsonData, '/videos');
            if ($grid === null) {
                throwServerException('Unable to locate video grid on ' . $urlListing);
            }

            $this->fetchItemsFromJsonData($grid);
        } else {
            $this->extractItemsFromXmlFeed($this->fetch($urlFeed));
        }

        if ($html !== '') {
            $this->feedName = $this->extractPageTitle($html);
        }
    }

    private function collectFromPlaylist(string $playlist): void
    {
        $urlFeed = self::URI . '/feeds/videos.xml?playlist_id=' . urlencode($playlist);
        $urlListing = self::URI . '/playlist?list=' . urlencode($playlist);

        $html = $this->fetch($urlListing);
        $jsonData = $this->extractJsonFromHtml($html);
        if ($jsonData === null) {
            throwServerException('Unable to find playlist: ' . $urlListing);
        }

        $items = $this->extractPlaylistItems($jsonData);

        if ($items !== null && (count($items) > self::PLAYLIST_XML_LIMIT || $this->hasDurationFilter() === true)) {
            $this->fetchItemsFromJsonData($items);
        } else {
            $this->extractItemsFromXmlFeed($this->fetch($urlFeed));
        }

        $this->feedName = 'Playlist: ' . $this->extractPageTitle($html);

        usort($this->items, fn(array $a, array $b): int => $this->getItemTimestamp($b) <=> $this->getItemTimestamp($a));
    }

    private function collectFromSearch(string $search): void
    {
        $urlListing = self::URI . '/results?sp=EgIIAg&search_query=' . urlencode($search);
        $fetchUrl = $urlListing;

        if (preg_match('/\b(?:before|after):/i', $search) !== 1) {
            $since = strtotime('-6 hours');
            if ($since !== false) {
                $fetchUrl .= urlencode(' after:' . date('Y-m-d', $since));
            }
        }

        $jsonData = $this->extractJsonFromHtml($this->fetch($fetchUrl));
        if ($jsonData !== null) {
            $items = $this->extractSearchItems($jsonData);
            if ($items !== null) {
                $this->fetchItemsFromJsonData($items);
            }
        }

        $this->feeduri = $urlListing;
        $this->feedName = 'Search: ' . $search;
    }

    private function stringInput(string $key): string
    {
        $value = $this->getInput($key);
        return is_string($value) === true ? trim($value) : '';
    }

    private function intInput(string $key): int
    {
        $value = $this->getInput($key);
        if (is_int($value) === true) {
            return $value;
        }
        return is_numeric($value) === true ? (int) $value : 0;
    }

    private function boolInput(string $key): bool
    {
        $value = $this->getInput($key);
        if (is_bool($value) === true) {
            return $value;
        }
        return is_string($value) === true && $value === 'on';
    }

    private function hasDurationFilter(): bool
    {
        return $this->intInput('duration_min') > 0 || $this->intInput('duration_max') > 0;
    }

    /**
     * @return list<object>
     */
    private static function objectList(mixed $value): array
    {
        if (is_array($value) === false) {
            return [];
        }

        return array_values(array_filter($value, static fn($item): bool => is_object($item) === true));
    }

    /**
     * @return list<object>|null
     */
    private function extractTabGrid(object $data, string $urlSuffix): ?array
    {
        $tabs = self::objectList($data->contents->twoColumnBrowseResultsRenderer->tabs ?? null);
        $withGrid = array_values(array_filter(
            $tabs,
            static fn(object $tab): bool => is_array($tab->tabRenderer->content->richGridRenderer->contents ?? null) === true
        ));

        if ($withGrid === []) {
            return null;
        }

        $chosen = array_find($withGrid, static function (object $tab) use ($urlSuffix): bool {
            if (($tab->tabRenderer->selected ?? false) === true) {
                return true;
            }
            $url = $tab->tabRenderer->endpoint->commandMetadata->webCommandMetadata->url ?? null;
            return is_string($url) === true && $urlSuffix !== '' && str_ends_with($url, $urlSuffix) === true;
        });

        if ($chosen === null) {
            $chosen = $withGrid[0];
        }

        return self::objectList($chosen->tabRenderer->content->richGridRenderer->contents ?? null);
    }

    /**
     * @return list<object>|null
     */
    private function extractPlaylistItems(object $data): ?array
    {
        $tabs = self::objectList($data->contents->twoColumnBrowseResultsRenderer->tabs ?? null);
        $fallback = null;

        foreach ($tabs as $tab) {
            $sections = self::objectList($tab->tabRenderer->content->sectionListRenderer->contents ?? null);

            foreach ($sections as $section) {
                $inner = $section->itemSectionRenderer ?? null;
                if (is_object($inner) === false) {
                    continue;
                }

                $list = self::objectList($inner->contents[0]->playlistVideoListRenderer->contents ?? null);
                if ($list !== []) {
                    return $list;
                }

                if ($fallback === null) {
                    $items = self::objectList($inner->contents ?? null);
                    if ($items !== []) {
                        $fallback = $items;
                    }
                }
            }
        }

        return $fallback;
    }

    /**
     * @return list<object>|null
     */
    private function extractSearchItems(object $data): ?array
    {
        $sections = self::objectList($data->contents->twoColumnSearchResultsRenderer->primaryContents->sectionListRenderer->contents ?? null);

        foreach ($sections as $section) {
            $items = self::objectList($section->itemSectionRenderer->contents ?? null);
            if ($items !== []) {
                return $items;
            }
        }

        return null;
    }

    private function extractItemsFromXmlFeed(string $xmlString): void
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = \Dom\XMLDocument::createFromString($xmlString, LIBXML_NOERROR | LIBXML_NOWARNING);
        } catch (\Throwable $e) {
            throwServerException('Failed to parse XML feed: ' . $e->getMessage());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $doc->documentElement;
        if ($root === null) {
            throwServerException('Failed to parse XML feed: empty document');
        }

        $feedTitle = $this->firstNodeText($root, self::ATOM_NS, 'title');
        if ($feedTitle !== '') {
            $this->feedName = $this->decodeTitle($feedTitle);
        }

        foreach ($root->getElementsByTagNameNS(self::ATOM_NS, 'entry') as $element) {
            $videoId = str_replace('yt:video:', '', $this->firstNodeText($element, self::ATOM_NS, 'id'));
            if ($videoId === '' || str_contains($videoId, 'googleads') === true) {
                continue;
            }

            $title = $this->firstNodeText($element, self::ATOM_NS, 'title');
            $author = $this->firstNodeText($element, self::ATOM_NS, 'name');

            $description = '';
            $group = $element->getElementsByTagNameNS(self::MEDIA_NS, 'group')->item(0);
            if ($group instanceof \Dom\Element) {
                $description = $this->firstNodeText($group, self::MEDIA_NS, 'description');
            }

            $description = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
            $description = preg_replace(self::URI_REGEX, '<a href="$1" target="_blank">$1</a>', $description) ?? $description;

            $timestamp = strtotime($this->firstNodeText($element, self::ATOM_NS, 'published'));

            $this->addItem(
                $videoId,
                $title !== '' ? $this->decodeTitle($title) : 'Untitled',
                $author,
                $description,
                $timestamp === false ? time() : $timestamp
            );
        }
    }

    private function firstNodeText(\Dom\Element $scope, string $namespace, string $localName): string
    {
        $node = $scope->getElementsByTagNameNS($namespace, $localName)->item(0);
        return $node instanceof \Dom\Element ? trim((string) $node->textContent) : '';
    }

    /**
     * @param list<object> $jsonData
     */
    private function fetchItemsFromJsonData(array $jsonData): void
    {
        $durationMin = $this->intInput('duration_min');
        $durationMax = $this->intInput('duration_max');
        $minSeconds = $durationMin > 0 ? $durationMin * 60 : -1;
        $maxSeconds = $durationMax > 0 ? $durationMax * 60 : INF;
        $skipDescription = $this->boolInput('skip_description');

        foreach ($jsonData as $item) {
            $wrapper = $this->unwrapVideo($item);
            if ($wrapper === null) {
                continue;
            }

            $videoId = $wrapper->videoId ?? null;
            if (is_string($videoId) === false || $videoId === '') {
                continue;
            }

            $title = $this->videoTitle($wrapper);
            $author = null;
            $snippet = $wrapper->descriptionSnippet->runs[0]->text ?? null;
            $description = is_string($snippet) === true ? $snippet : null;
            $timestamp = $this->parsePublishedTime(
                $wrapper->publishedTimeText->simpleText ?? ($wrapper->videoInfo->runs[2]->text ?? null)
            );

            $duration = self::parseDuration($this->videoDurationText($wrapper));
            if ($duration !== null && ($duration < $minSeconds || $duration > $maxSeconds)) {
                continue;
            }

            if ($skipDescription === false && ($description === null || $description === '' || $timestamp === null)) {
                $details = $this->fetchVideoDetails($videoId);
                if ($details['author'] !== null) {
                    $author = $details['author'];
                }
                if ($details['description'] !== null && $details['description'] !== '') {
                    $description = $details['description'];
                }
                if ($details['timestamp'] !== null) {
                    $timestamp = $details['timestamp'];
                }
            }

            $this->addItem($videoId, $title, $author, $description ?? '', $timestamp ?? time());

            if (count($this->items) >= self::MAX_ITEMS) {
                break;
            }
        }
    }

    private function unwrapVideo(object $item): ?object
    {
        $grid = $item->gridVideoRenderer ?? null;
        if (is_object($grid) === true) {
            return $grid;
        }

        $video = $item->videoRenderer ?? null;
        if (is_object($video) === true) {
            return $video;
        }

        $playlistVideo = $item->playlistVideoRenderer ?? null;
        if (is_object($playlistVideo) === true) {
            return $playlistVideo;
        }

        $rich = $item->richItemRenderer->content ?? null;
        if (is_object($rich) === true) {
            $richVideo = $rich->videoRenderer ?? null;
            if (is_object($richVideo) === true) {
                return $richVideo;
            }

            $richLockup = $rich->lockupViewModel ?? null;
            if (is_object($richLockup) === true) {
                return $this->wrapLockupViewModel($richLockup);
            }
        }

        $lockup = $item->lockupViewModel ?? null;
        if (is_object($lockup) === true) {
            return $this->wrapLockupViewModel($lockup);
        }

        return null;
    }

    private function wrapLockupViewModel(object $lockup): ?object
    {
        $videoId = $lockup->contentId ?? null;
        $metadata = $lockup->metadata->lockupMetadataViewModel ?? null;
        $title = is_object($metadata) === true ? ($metadata->title->content ?? null) : null;

        if (is_string($videoId) === false || $videoId === '' || is_string($title) === false || $title === '') {
            return null;
        }

        if ($this->isMembersOnly($metadata) === true) {
            return null;
        }

        $wrapper = new \stdClass();
        $wrapper->videoId = $videoId;
        $wrapper->title = (object) ['runs' => [(object) ['text' => $title]]];
        $wrapper->thumbnailOverlays = [];

        $duration = $this->lockupDuration($lockup->contentImage ?? null);
        if ($duration !== null) {
            $wrapper->lengthText = (object) ['simpleText' => $duration];
        }

        return $wrapper;
    }

    private function isMembersOnly(mixed $metadata): bool
    {
        if ($this->boolInput('skip_members_only') === false || is_object($metadata) === false) {
            return false;
        }

        foreach (self::objectList($metadata->metadata->contentMetadataViewModel->metadataRows ?? null) as $row) {
            foreach (self::objectList($row->badges ?? null) as $badge) {
                if (($badge->badgeViewModel->badgeStyle ?? null) === 'BADGE_MEMBERS_ONLY') {
                    return true;
                }
            }
        }

        return false;
    }

    private function lockupDuration(mixed $contentImage): ?string
    {
        if (is_object($contentImage) === false) {
            return null;
        }

        foreach (self::objectList($contentImage->thumbnailViewModel->overlays ?? null) as $overlay) {
            foreach (self::objectList($overlay->thumbnailBottomOverlayViewModel->badges ?? null) as $badge) {
                $text = $badge->thumbnailBadgeViewModel->text ?? null;
                if (is_string($text) === true && preg_match('/^\d{1,2}(?::\d{2}){1,2}$/', $text) === 1) {
                    return $text;
                }
            }
        }

        return null;
    }

    private function videoTitle(object $wrapper): string
    {
        $title = $wrapper->title->runs[0]->text ?? ($wrapper->title->accessibility->accessibilityData->label ?? null);
        return is_string($title) === true && $title !== '' ? $title : 'Untitled';
    }

    private function videoDurationText(object $wrapper): ?string
    {
        $text = $wrapper->lengthText->simpleText ?? null;
        if (is_string($text) === true && $text !== '') {
            return $text;
        }

        foreach (self::objectList($wrapper->thumbnailOverlays ?? null) as $overlay) {
            $status = $overlay->thumbnailOverlayTimeStatusRenderer->text ?? null;
            if (is_object($status) === true) {
                $simple = $status->simpleText ?? null;
                if (is_string($simple) === true && $simple !== '') {
                    return $simple;
                }
            } elseif (is_string($status) === true && $status !== '') {
                return $status;
            }
        }

        return null;
    }

    private static function parseDuration(?string $text): ?int
    {
        if ($text === null) {
            return null;
        }

        $text = trim($text);
        if (preg_match('/^\d{1,3}(?::\d{1,2}){1,2}$/', $text) !== 1) {
            return null;
        }

        $seconds = 0;
        foreach (explode(':', $text) as $part) {
            $seconds = $seconds * 60 + (int) $part;
        }

        return $seconds;
    }

    private function parsePublishedTime(mixed $text): ?int
    {
        if (is_string($text) === false || $text === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($text))->setTime(0, 0, 0)->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @return array{author: ?string, description: ?string, timestamp: ?int}
     */
    private function fetchVideoDetails(string $videoId): array
    {
        $result = ['author' => null, 'description' => null, 'timestamp' => null];
        $htmlString = $this->fetch(self::URI . '/watch?v=' . $videoId, true);

        if (str_contains($htmlString, 'IS_UNAVAILABLE_PAGE') === true) {
            return $result;
        }

        $dom = parseHtmlDocument($htmlString);

        $authorContent = $dom->querySelector('span[itemprop=author] > link[itemprop=name]')?->getAttribute('content');
        if ($authorContent !== null && $authorContent !== '') {
            $result['author'] = $authorContent;
        }

        $dateContent = $dom->querySelector('meta[itemprop=datePublished]')?->getAttribute('content');
        if ($dateContent !== null && $dateContent !== '') {
            $parsed = strtotime($dateContent);
            if ($parsed !== false) {
                $result['timestamp'] = $parsed;
            }
        }

        $jsonData = $this->extractJsonFromHtml($htmlString);
        $contents = self::objectList($jsonData->contents->twoColumnWatchNextResults->results->results->contents ?? null);
        if ($contents === []) {
            return $result;
        }

        $primaryInfo = null;
        $secondaryInfo = null;
        foreach ($contents as $item) {
            $primary = $item->videoPrimaryInfoRenderer ?? null;
            if (is_object($primary) === true) {
                $primaryInfo = $primary;
            }

            $secondary = $item->videoSecondaryInfoRenderer ?? null;
            if (is_object($secondary) === true) {
                $secondaryInfo = $secondary;
            }
        }

        if ($secondaryInfo === null) {
            throwServerException('Could not find videoSecondaryInfoRenderer. Error at: ' . $videoId);
        }

        if ($result['author'] === null) {
            $owner = $secondaryInfo->owner->videoOwnerRenderer->title->runs[0]->text ?? null;
            if (is_string($owner) === true && $owner !== '') {
                $result['author'] = $owner;
            }
        }

        if ($result['timestamp'] === null && $primaryInfo !== null) {
            $dateText = $primaryInfo->dateText->simpleText ?? null;
            if (is_string($dateText) === true && preg_match('/[A-Z][a-z]{2} \d{1,2}, \d{4}/', $dateText, $matches) === 1) {
                $parsed = strtotime($matches[0]);
                if ($parsed !== false) {
                    $result['timestamp'] = $parsed;
                }
            }
        }

        $raw = $secondaryInfo->attributedDescription->content ?? '';
        $description = normalize_unicode(strip_invisible_unicode(is_string($raw) === true ? $raw : ''));
        $enhancements = $this->descriptionEnhancements($secondaryInfo, $description, self::URI, self::DESCRIPTION_WHITESPACE);

        foreach ($enhancements as $enhancement) {
            $before = mb_substr($description, 0, $enhancement['pos']);
            $value = mb_substr($description, $enhancement['pos'], $enhancement['len']);
            $after = mb_substr($description, $enhancement['pos'] + $enhancement['len']);
            $value = trim($value, self::DESCRIPTION_WHITESPACE . "\u{2022}/");

            $description = sprintf(
                '%s<a href="%s" target="_blank">%s</a>%s',
                $before,
                htmlspecialchars($enhancement['url'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($value, ENT_QUOTES, 'UTF-8'),
                $after
            );
        }

        $result['description'] = $description;

        return $result;
    }

    private function resolveCommandUrl(object $commandMetadata, string $baseUrl): string
    {
        $rawUrl = $commandMetadata->url ?? null;
        if (is_string($rawUrl) === false || $rawUrl === '') {
            return $baseUrl;
        }

        $parsed = parse_url($rawUrl);
        if ($parsed === false) {
            return urljoin($baseUrl, $rawUrl);
        }

        if (($parsed['path'] ?? '') === '/redirect') {
            parse_str((string) ($parsed['query'] ?? ''), $queryParts);
            $target = $queryParts['q'] ?? null;
            return is_string($target) === true && $target !== '' ? urldecode($target) : $baseUrl;
        }

        if (array_key_exists('host', $parsed) === true) {
            return $rawUrl;
        }

        return urljoin($baseUrl, $rawUrl);
    }

    /**
     * @return list<array{pos: int, len: int, url: string}>
     */
    private function descriptionEnhancements(object $secondaryInfo, string $description, string $baseUrl, string $whitespaceChars): array
    {
        $commandRuns = self::objectList($secondaryInfo->attributedDescription->commandRuns ?? null);
        if ($commandRuns === []) {
            return [];
        }

        $boundaryWhitespace = mb_str_split($whitespaceChars);
        $startChars = array_merge($boundaryWhitespace, [':', '-', '(']);
        $endChars = array_merge($boundaryWhitespace, [',', '.', "'", ')']);
        $hashtagEndChars = array_merge($endChars, ['#', '-']);

        $descriptionLength = mb_strlen($description);
        $minPositionOffset = 0;
        $prevStartPosition = 0;
        $totalLength = 0;
        $maxPositionByStartIndex = [];

        foreach (array_reverse($commandRuns) as $commandRun) {
            if (isset($commandRun->startIndex, $commandRun->length) === false) {
                continue;
            }
            $startIndex = (int) $commandRun->startIndex;
            $length = (int) $commandRun->length;
            if (($startIndex + $length) < $prevStartPosition) {
                $totalLength++;
            }
            $totalLength += $length;
            $maxPositionByStartIndex[$startIndex] = $totalLength;
            $prevStartPosition = $startIndex;
        }

        $enhancements = [];
        $minPosition = 0;

        foreach ($commandRuns as $commandRun) {
            if (isset($commandRun->startIndex, $commandRun->length) === false) {
                continue;
            }

            $commandMetadata = $commandRun->onTap->innertubeCommand->commandMetadata->webCommandMetadata ?? null;
            if (is_object($commandMetadata) === false) {
                continue;
            }

            $pageType = $commandMetadata->webPageType ?? null;
            $isHashtag = is_string($pageType) === true && $pageType === 'WEB_PAGE_TYPE_BROWSE';
            $startIndex = (int) $commandRun->startIndex;
            $commandLength = (int) $commandRun->length;
            $maxPosition = $descriptionLength - ($maxPositionByStartIndex[$startIndex] ?? 0);
            $position = min($startIndex - $minPositionOffset, $maxPosition);
            $found = null;

            while ($position >= $minPosition) {
                $newLinePosition = mb_strpos($description, "\n", $position);
                if ($newLinePosition !== false && $newLinePosition < $position + ($commandLength - 1)) {
                    $position = $newLinePosition - ($commandLength - 1);
                    continue;
                }

                $firstChar = mb_substr($description, $position, 1);
                $boundaryStart = mb_substr($description, $position - 1, 1);
                $boundaryEndIndex = $position + $commandLength;
                $boundaryEnd = mb_substr($description, $boundaryEndIndex, 1);

                $startValid = $position === 0
                    || in_array($boundaryStart, $startChars, true) === true
                    || ($isHashtag === true && $firstChar === '#');

                if ($boundaryEndIndex === $descriptionLength) {
                    $endValid = true;
                } elseif ($isHashtag === true) {
                    $endValid = in_array($boundaryEnd, $hashtagEndChars, true) === true;
                } else {
                    $endValid = in_array($boundaryEnd, $endChars, true) === true;
                }

                if ($startValid === true && $endValid === true) {
                    $minPositionOffset = $startIndex - $position;
                    $found = ['pos' => $position, 'len' => $commandLength];
                    break;
                }

                $position--;
            }

            if ($found === null) {
                continue;
            }

            if (mb_substr($description, $found['pos'] + $found['len'] - 1, 1) === "\n") {
                $found['len']--;
            }

            $found['url'] = $this->resolveCommandUrl($commandMetadata, $baseUrl);
            $minPosition = $found['pos'] + $found['len'];
            $enhancements[] = $found;
        }

        if (count($enhancements) !== count($commandRuns)) {
            return [];
        }

        return array_reverse($enhancements);
    }

    private function fetch(string $url, bool $cache = false): string
    {
        if ($this->cache === null) {
            $cache = false;
        }

        $cacheKey = 'yt_fetch_' . md5($url);

        if ($cache === true) {
            $cached = $this->cache->get($cacheKey);
            if (is_string($cached) === true && $cached !== '') {
                return $cached;
            }
        }

        $html = (string) getContents($url, ['Accept-Language: en-US', 'Cookie: SOCS=CAI']);

        if ($cache === true && $html !== '') {
            $this->cache->set($cacheKey, $html, 86400 * 3);
        }

        return $html;
    }

    private function extractJsonFromHtml(string $html): ?object
    {
        foreach (['var ytInitialData = ', 'window["ytInitialData"] = ', 'ytInitialData = '] as $marker) {
            $pos = strpos($html, $marker);
            if ($pos === false) {
                continue;
            }

            $json = self::scanJsonObject($html, $pos + strlen($marker));
            if ($json === null) {
                continue;
            }

            try {
                $data = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (is_object($data) === true) {
                return $data;
            }
        }

        return null;
    }

    private static function scanJsonObject(string $html, int $start): ?string
    {
        $length = strlen($html);
        while ($start < $length && ($html[$start] === ' ' || $html[$start] === "\n" || $html[$start] === "\r" || $html[$start] === "\t")) {
            $start++;
        }

        if ($start >= $length || $html[$start] !== '{') {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;

        for ($i = $start; $i < $length; $i++) {
            $char = $html[$i];

            if ($inString === true) {
                if ($escaped === true) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($html, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    private function extractPageTitle(string $htmlString): string
    {
        $title = parseHtmlDocument($htmlString)->querySelector('title')?->textContent;
        return str_replace(' - YouTube', '', trim((string) $title));
    }

    private function addItem(string $videoId, string $title, ?string $author, string $description, int $timestamp): void
    {
        $uri = self::URI . '/watch?v=' . $videoId;
        $thumbnail = 'https://img.youtube.com/vi/' . $videoId . '/0.jpg';

        $this->items[] = [
            'id' => $videoId,
            'title' => strip_invisible_unicode($title),
            'author' => $author ?? '',
            'timestamp' => $timestamp,
            'uri' => $uri,
            'content' => sprintf(
                '<a href="%s"><img src="%s" /></a><br />%s',
                htmlspecialchars($uri, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($thumbnail, ENT_QUOTES, 'UTF-8'),
                nl2br(strip_invisible_unicode($description))
            ),
            'uid' => $videoId,
        ];
    }

    private function decodeTitle(string $title): string
    {
        return html_entity_decode($title, ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $item
     */
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
}
