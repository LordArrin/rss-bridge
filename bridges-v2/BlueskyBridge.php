<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class BlueskyBridge extends BridgeAbstract
{
    public const NAME = 'Bluesky';
    public const URI = 'https://bsky.app';
    public const DESCRIPTION = 'Fetches posts from Bluesky';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 1800;

    public const PARAMETERS = [
        'Posts from a user' => [
            'user_id' => [
                'name' => 'User Handle or DID',
                'type' => 'text',
                'required' => true,
                'exampleValue' => 'did:plc:z72i7hdynmk6r22z27h6tvur',
                'title' => 'ATProto / Bsky.app handle or DID',
            ],
            'feed_filter' => [
                'name' => 'Feed type',
                'type' => 'list',
                'defaultValue' => 'posts_and_author_threads',
                'values' => [
                    'Authored posts / threads and reposts' => 'posts_and_author_threads',
                    'All posts, replies and reposts' => 'posts_with_replies',
                    'Root posts only' => 'posts_no_replies',
                    'Media only' => 'posts_with_media',
                ],
            ],
            'include_reposts' => [
                'name' => 'Include Reposts?',
                'type' => 'checkbox',
                'defaultValue' => 'checked',
            ],
            'include_reply_context' => [
                'name' => 'Include Reply context?',
                'type' => 'checkbox',
            ],
            'verbose_title' => [
                'name' => 'Use verbose feed item titles?',
                'type' => 'checkbox',
            ],
        ],
    ];

    private ?array $profile = null;

    public function getName(): string
    {
        if ($this->profile !== null) {
            if (($this->profile['handle'] ?? '') === 'handle.invalid') {
                return sprintf('Bluesky - %s', $this->profile['displayName'] ?? '');
            }
            return sprintf('Bluesky - %s (@%s)', $this->profile['displayName'] ?? '', $this->profile['handle'] ?? '');
        }
        return parent::getName();
    }

    public function getURI(): string
    {
        if ($this->profile !== null) {
            if (($this->profile['handle'] ?? '') === 'handle.invalid') {
                return self::URI . '/profile/' . ($this->profile['did'] ?? '');
            }
            return self::URI . '/profile/' . ($this->profile['handle'] ?? '');
        }
        return parent::getURI();
    }

    public function getIcon(): string
    {
        if ($this->profile !== null && isset($this->profile['avatar']) === true) {
            return $this->profile['avatar'];
        }
        return parent::getIcon();
    }

    public function getDescription(): string
    {
        if ($this->profile !== null && isset($this->profile['description']) === true) {
            return $this->profile['description'];
        }
        return parent::getDescription();
    }

    private function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function parseExternal(array $external, string $did): string
    {
        $description = '';
        $externalUri = $external['uri'] ?? '';
        $externalTitle = $this->e($external['title'] ?? '');
        $externalDescription = $this->e($external['description'] ?? '');
        $thumb = $external['thumb'] ?? null;

        if (preg_match('/http(|s):\/\/media\.tenor\.com/', $externalUri) === 1) {
            $tenorInterstitial = str_replace('media.tenor.com', 'media1.tenor.com/m', $externalUri);
            $description .= "<figure><a href=\"{$tenorInterstitial}\"><img src=\"{$externalUri}\"/></a><figcaption>{$externalTitle}</figcaption></figure>";
        } else {
            $host = parse_url($externalUri)['host'] ?? '';
            $thumbDesc = '';
            if ($thumb !== null && isset($thumb['ref']['$link']) === true) {
                $thumbDesc = '<img src="https://cdn.bsky.app/img/feed_thumbnail/plain/' . $did . '/' . $thumb['ref']['$link'] . '@jpeg"/>';
            }
            $externalDescriptionTag = strlen($externalDescription) > 0 ? "<figcaption>({$host}) {$externalDescription}</figcaption>" : '';
            $description .= '<br><blockquote><b><a href="' . $externalUri . '">' . $externalTitle . '</a></b>';
            $description .= '<figure>' . $thumbDesc . $externalDescriptionTag . '</figure></blockquote>';
        }
        return $description;
    }

    private function textToDescription(array $record): string
    {
        if (isset($record['value']) === true) {
            $record = $record['value'];
        }
        $text = $record['text'] ?? '';
        $textCopy = $text;
        $text = nl2br($this->e($text));
        if (isset($record['facets']) === true && is_array($record['facets']) === true) {
            foreach ($record['facets'] as $facet) {
                if (isset($facet['features'][0]['$type']) === true && $facet['features'][0]['$type'] === 'app.bsky.richtext.facet#link') {
                    $byteStart = (int)($facet['index']['byteStart'] ?? 0);
                    $byteEnd = (int)($facet['index']['byteEnd'] ?? 0);
                    $substring = substr($textCopy, $byteStart, $byteEnd - $byteStart);
                    $uri = $facet['features'][0]['uri'] ?? '';
                    $text = str_replace($substring, '<a href="' . $uri . '">' . $substring . '</a>', $text);
                }
            }
        }
        return $text;
    }

    public function collectData(): void
    {
        $userId = (string)$this->getInput('user_id');
        $handleMatch = preg_match('/(?:[a-zA-Z]*\.)+([a-zA-Z](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)/', $userId, $handleRes);
        $didMatch = preg_match('/did:plc:[a-z2-7]{24}/', $userId);
        $exclude = ['alt', 'arpa', 'example', 'internal', 'invalid', 'local', 'localhost', 'onion'];

        $did = '';
        if ($handleMatch === 1 && isset($handleRes[1]) === true && in_array($handleRes[1], $exclude, true) === false) {
            $did = $this->resolveHandle($userId);
        } elseif ($didMatch === 1) {
            $did = $userId;
        } else {
            throwClientException('Invalid ATproto handle or DID provided.');
        }

        $filterInput = $this->getInput('feed_filter');
        $filter = (is_string($filterInput) === true && $filterInput !== '') ? $filterInput : 'posts_and_author_threads';

        $replyContextInput = $this->getInput('include_reply_context');
        $replyContext = $replyContextInput === true || (is_string($replyContextInput) === true && $replyContextInput !== '');

        $includeRepostsInput = $this->getInput('include_reposts');
        $includeReposts = $includeRepostsInput === true || (is_string($includeRepostsInput) === true && $includeRepostsInput !== '');

        $verboseTitleInput = $this->getInput('verbose_title');
        $verboseTitle = $verboseTitleInput === true || (is_string($verboseTitleInput) === true && $verboseTitleInput !== '');

        $this->profile = $this->getProfile($did);
        $authorFeed = $this->getAuthorFeed($did, $filter);

        $feedItems = $authorFeed['feed'] ?? [];
        if (is_array($feedItems) === false) {
            return;
        }

        foreach ($feedItems as $post) {
            if ($includeReposts === false && isset($post['reason']['$type']) === true && str_contains((string)$post['reason']['$type'], 'reasonRepost') === true) {
                continue;
            }

            $postRecord = $post['post']['record'] ?? [];
            if (is_array($postRecord) === false) {
                continue;
            }

            $postUriParts = explode('app.bsky.feed.post/', (string)($post['post']['uri'] ?? ''));
            $postId = end($postUriParts);
            $postAuthorInfo = $this->fallbackAuthor($post['post']['author'] ?? [], 'url');

            $item = [];
            $item['uri'] = self::URI . '/profile/' . $postAuthorInfo . '/post/' . $postId;

            if ($verboseTitle === true) {
                $item['title'] = $this->generateVerboseTitle($post);
            } else {
                $item['title'] = strtok((string)($postRecord['text'] ?? ''), "\n");
            }

            $createdAt = $postRecord['createdAt'] ?? '';
            $parsedTime = strtotime((string)$createdAt);
            $item['timestamp'] = ($parsedTime !== false) ? $parsedTime : time();
            $item['author'] = $this->fallbackAuthor($post['post']['author'] ?? [], 'display');

            $postAuthorDID = (string)($post['post']['author']['did'] ?? '');
            $postAuthorHandleRaw = (string)($post['post']['author']['handle'] ?? '');
            $postAuthorHandle = $postAuthorHandleRaw !== 'handle.invalid' ? '<i>@' . $postAuthorHandleRaw . '</i>' : '';
            $postDisplayName = $this->e((string)($post['post']['author']['displayName'] ?? ''));
            $postUri = $item['uri'];

            $description = '<p>';
            $description .= $this->getPostDescription(
                $postDisplayName,
                $postAuthorHandle,
                $postUri,
                $postRecord,
                'post'
            );

            if (isset($postRecord['embed']['$type']) === true) {
                $embedType = (string)$postRecord['embed']['$type'];

                if ($embedType === 'app.bsky.embed.external') {
                    $description .= $this->parseExternal($postRecord['embed']['external'] ?? [], $postAuthorDID);
                } elseif (
                    $embedType === 'app.bsky.embed.recordWithMedia' &&
                    isset($postRecord['embed']['media']['$type']) === true &&
                    (string)$postRecord['embed']['media']['$type'] === 'app.bsky.embed.external'
                ) {
                    $description .= $this->parseExternal($postRecord['embed']['media']['external'] ?? [], $postAuthorDID);
                }

                if (
                    $embedType === 'app.bsky.embed.gallery' ||
                    $embedType === 'app.bsky.embed.images' ||
                    (
                        $embedType === 'app.bsky.embed.recordWithMedia' &&
                        isset($postRecord['embed']['media']['$type']) === true &&
                        (string)$postRecord['embed']['media']['$type'] === 'app.bsky.embed.images'
                    )
                ) {
                    $images = $post['post']['embed']['items'] ?? $post['post']['embed']['images'] ?? $post['post']['embed']['media']['images'] ?? [];
                    if (is_array($images) === true) {
                        foreach ($images as $image) {
                            if (is_array($image) === true) {
                                $description .= $this->getPostImageDescription($image);
                            }
                        }
                    }
                }

                if (
                    $embedType === 'app.bsky.embed.video' ||
                    (
                        $embedType === 'app.bsky.embed.recordWithMedia' &&
                        isset($postRecord['embed']['media']['$type']) === true &&
                        (string)$postRecord['embed']['media']['$type'] === 'app.bsky.embed.video'
                    )
                ) {
                    $videoData = $postRecord['embed']['video'] ?? $postRecord['embed']['media']['video'] ?? [];
                    if (is_array($videoData) === true) {
                        $description .= $this->getPostVideoDescription($videoData, $postAuthorDID);
                    }
                }
            }
            $description .= '</p>';

            if (
                isset($postRecord['embed']) === true &&
                isset($postRecord['embed']['$type']) === true &&
                (
                    (string)$postRecord['embed']['$type'] === 'app.bsky.embed.record' ||
                    (string)$postRecord['embed']['$type'] === 'app.bsky.embed.recordWithMedia'
                ) &&
                isset($post['post']['embed']['record']) === true
            ) {
                $description .= '<p>';
                $quotedRecord = $post['post']['embed']['record']['record'] ?? $post['post']['embed']['record'];

                if (is_array($quotedRecord) === true) {
                    if (isset($quotedRecord['notFound']) === true && $quotedRecord['notFound'] === true) {
                        $description .= 'Quoted post deleted.';
                    } elseif (isset($quotedRecord['detached']) === true && $quotedRecord['detached'] === true) {
                        $uriExplode = explode('/', (string)($quotedRecord['uri'] ?? ''));
                        $uriReconstructed = self::URI . '/profile/' . ($uriExplode[2] ?? '') . '/post/' . ($uriExplode[4] ?? '');
                        $description .= '<a href="' . $uriReconstructed . '">Quoted post detached.</a>';
                    } elseif (isset($quotedRecord['blocked']) === true && $quotedRecord['blocked'] === true) {
                        $description .= 'Author of quoted post has blocked OP.';
                    } elseif (
                        (($quotedRecord['$type'] ?? '') === 'app.bsky.feed.defs#generatorView') ||
                        (($quotedRecord['$type'] ?? '') === 'app.bsky.graph.defs#listView')
                    ) {
                        $description .= $this->getListFeedDescription($quotedRecord);
                    } elseif (
                        (($quotedRecord['$type'] ?? '') === 'app.bsky.graph.starterpack') ||
                        (($quotedRecord['$type'] ?? '') === 'app.bsky.graph.defs#starterPackViewBasic')
                    ) {
                        $description .= $this->getStarterPackDescription($post['post']['embed']['record'] ?? []);
                    } else {
                        $quotedAuthorDid = (string)($quotedRecord['author']['did'] ?? '');
                        $quotedDisplayName = $this->e((string)($quotedRecord['author']['displayName'] ?? ''));
                        $quotedAuthorHandleRaw = (string)($quotedRecord['author']['handle'] ?? '');
                        $quotedAuthorHandle = $quotedAuthorHandleRaw !== 'handle.invalid' ? '<i>@' . $quotedAuthorHandleRaw . '</i>' : '';

                        $parts = explode('/', (string)($quotedRecord['uri'] ?? ''));
                        $quotedPostId = end($parts);
                        $quotedPostUri = self::URI . '/profile/' . $this->fallbackAuthor($quotedRecord['author'] ?? [], 'url') . '/post/' . $quotedPostId;

                        $description .= $this->getPostDescription(
                            $quotedDisplayName,
                            $quotedAuthorHandle,
                            $quotedPostUri,
                            $quotedRecord,
                            'quote'
                        );

                        if (isset($quotedRecord['value']['embed']['$type']) === true) {
                            $quotedEmbedType = (string)$quotedRecord['value']['embed']['$type'];

                            if ($quotedEmbedType === 'app.bsky.embed.external') {
                                $description .= $this->parseExternal($quotedRecord['value']['embed']['external'] ?? [], $quotedAuthorDid);
                            }

                            if (
                                $quotedEmbedType === 'app.bsky.embed.video' ||
                                (
                                    $quotedEmbedType === 'app.bsky.embed.recordWithMedia' &&
                                    isset($quotedRecord['value']['embed']['media']['$type']) === true &&
                                    (string)$quotedRecord['value']['embed']['media']['$type'] === 'app.bsky.embed.video'
                                )
                            ) {
                                $quotedVideoData = $quotedRecord['value']['embed']['video'] ?? $quotedRecord['value']['embed']['media']['video'] ?? [];
                                if (is_array($quotedVideoData) === true) {
                                    $description .= $this->getPostVideoDescription($quotedVideoData, $quotedAuthorDid);
                                }
                            }

                            if (
                                $quotedEmbedType === 'app.bsky.embed.gallery' ||
                                $quotedEmbedType === 'app.bsky.embed.images' ||
                                (
                                    $quotedEmbedType === 'app.bsky.embed.recordWithMedia' &&
                                    isset($quotedRecord['value']['embed']['media']['$type']) === true &&
                                    (string)$quotedRecord['value']['embed']['media']['$type'] === 'app.bsky.embed.images'
                                )
                            ) {
                                $quotedEmbeds = $quotedRecord['embeds'] ?? [];
                                if (is_array($quotedEmbeds) === true) {
                                    foreach ($quotedEmbeds as $embed) {
                                        if (is_array($embed) === false) {
                                            continue;
                                        }
                                        $embedTypeCheck = (string)($embed['$type'] ?? '');
                                        if (
                                            $embedTypeCheck === 'app.bsky.embed.gallery#view' ||
                                            $embedTypeCheck === 'app.bsky.embed.images#view' ||
                                            ($embedTypeCheck === 'app.bsky.embed.recordWithMedia#view' && isset($embed['media']['$type']) === true && (string)$embed['media']['$type'] === 'app.bsky.embed.images#view')
                                        ) {
                                            $quotedImages = $embed['items'] ?? $embed['images'] ?? $embed['media']['images'] ?? [];
                                            if (is_array($quotedImages) === true) {
                                                foreach ($quotedImages as $quotedImage) {
                                                    if (is_array($quotedImage) === true) {
                                                        $description .= $this->getPostImageDescription($quotedImage);
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                $description .= '</p>';
            }

            if ($replyContext === true && isset($post['reply']['parent']) === true) {
                $replyPost = $post['reply']['parent'];
                $description .= '<hr/>';
                $description .= '<p>';

                if (is_array($replyPost) === true) {
                    if (isset($replyPost['notFound']) === true && $replyPost['notFound'] === true) {
                        $description .= 'Replied to post was deleted.';
                    } elseif (isset($replyPost['blocked']) === true && $replyPost['blocked'] === true) {
                        $description .= 'Author of replied to post has blocked OP.';
                    } else {
                        $replyPostRecord = $replyPost['record'] ?? [];
                        $replyPostAuthorDID = (string)($replyPost['author']['did'] ?? '');
                        $replyPostAuthorHandleRaw = (string)($replyPost['author']['handle'] ?? '');
                        $replyPostAuthorHandle = $replyPostAuthorHandleRaw !== 'handle.invalid' ? '<i>@' . $replyPostAuthorHandleRaw . '</i>' : '';
                        $replyPostDisplayName = $this->e((string)($replyPost['author']['displayName'] ?? ''));
                        $replyPostUriParts = explode('app.bsky.feed.post/', (string)($replyPost['uri'] ?? ''));
                        $replyPostUri = self::URI . '/profile/' . $this->fallbackAuthor($replyPost['author'] ?? [], 'url') . '/post/' . end($replyPostUriParts);

                        if (is_array($replyPostRecord) === true) {
                            $description .= $this->getPostDescription(
                                $replyPostDisplayName,
                                $replyPostAuthorHandle,
                                $replyPostUri,
                                $replyPostRecord,
                                'reply'
                            );

                            if (isset($replyPostRecord['embed']['$type']) === true) {
                                $replyEmbedType = (string)$replyPostRecord['embed']['$type'];

                                if ($replyEmbedType === 'app.bsky.embed.external') {
                                    $description .= $this->parseExternal($replyPostRecord['embed']['external'] ?? [], $replyPostAuthorDID);
                                } elseif (
                                    $replyEmbedType === 'app.bsky.embed.recordWithMedia' &&
                                    isset($replyPostRecord['embed']['media']['$type']) === true &&
                                    (string)$replyPostRecord['embed']['media']['$type'] === 'app.bsky.embed.external'
                                ) {
                                    $description .= $this->parseExternal($replyPostRecord['embed']['media']['external'] ?? [], $replyPostAuthorDID);
                                }

                                if (
                                    $replyEmbedType === 'app.bsky.embed.gallery' ||
                                    $replyEmbedType === 'app.bsky.embed.images' ||
                                    (
                                        $replyEmbedType === 'app.bsky.embed.recordWithMedia' &&
                                        isset($replyPostRecord['embed']['media']['$type']) === true &&
                                        (string)$replyPostRecord['embed']['media']['$type'] === 'app.bsky.embed.images'
                                    )
                                ) {
                                    $replyImages = $replyPost['embed']['items'] ?? $replyPost['embed']['images'] ?? $replyPost['embed']['media']['images'] ?? [];
                                    if (is_array($replyImages) === true) {
                                        foreach ($replyImages as $replyImage) {
                                            if (is_array($replyImage) === true) {
                                                $description .= $this->getPostImageDescription($replyImage);
                                            }
                                        }
                                    }
                                }

                                if (
                                    $replyEmbedType === 'app.bsky.embed.video' ||
                                    (
                                        $replyEmbedType === 'app.bsky.embed.recordWithMedia' &&
                                        isset($replyPostRecord['embed']['media']['$type']) === true &&
                                        (string)$replyPostRecord['embed']['media']['$type'] === 'app.bsky.embed.video'
                                    )
                                ) {
                                    $replyVideoData = $replyPostRecord['embed']['video'] ?? $replyPostRecord['embed']['media']['video'] ?? [];
                                    if (is_array($replyVideoData) === true) {
                                        $description .= $this->getPostVideoDescription($replyVideoData, $replyPostAuthorDID);
                                    }
                                }
                            }
                        }
                        $description .= '</p>';

                        if (
                            isset($replyPostRecord['embed']['$type']) === true &&
                            (
                                (string)$replyPostRecord['embed']['$type'] === 'app.bsky.embed.record' ||
                                (string)$replyPostRecord['embed']['$type'] === 'app.bsky.embed.recordWithMedia'
                            ) &&
                            isset($replyPost['embed']['record']) === true
                        ) {
                            $description .= '<p>';
                            $replyQuotedRecord = $replyPost['embed']['record']['record'] ?? $replyPost['embed']['record'];

                            if (is_array($replyQuotedRecord) === true) {
                                if (isset($replyQuotedRecord['notFound']) === true && $replyQuotedRecord['notFound'] === true) {
                                    $description .= 'Quoted post deleted.';
                                } elseif (isset($replyQuotedRecord['detached']) === true && $replyQuotedRecord['detached'] === true) {
                                    $uriExplode = explode('/', (string)($replyQuotedRecord['uri'] ?? ''));
                                    $uriReconstructed = self::URI . '/profile/' . ($uriExplode[2] ?? '') . '/post/' . ($uriExplode[4] ?? '');
                                    $description .= '<a href="' . $uriReconstructed . '">Quoted post detached.</a>';
                                } elseif (isset($replyQuotedRecord['blocked']) === true && $replyQuotedRecord['blocked'] === true) {
                                    $description .= 'Author of quoted post has blocked OP.';
                                } elseif (
                                    (($replyQuotedRecord['$type'] ?? '') === 'app.bsky.feed.defs#generatorView') ||
                                    (($replyQuotedRecord['$type'] ?? '') === 'app.bsky.graph.defs#listView')
                                ) {
                                    $description .= $this->getListFeedDescription($replyQuotedRecord);
                                } elseif (
                                    (($replyQuotedRecord['$type'] ?? '') === 'app.bsky.graph.starterpack') ||
                                    (($replyQuotedRecord['$type'] ?? '') === 'app.bsky.graph.defs#starterPackViewBasic')
                                ) {
                                    $description .= $this->getStarterPackDescription($replyPost['embed']['record'] ?? []);
                                } else {
                                    $rqAuthorDid = (string)($replyQuotedRecord['author']['did'] ?? '');
                                    $rqDisplayName = $this->e((string)($replyQuotedRecord['author']['displayName'] ?? ''));
                                    $rqAuthorHandleRaw = (string)($replyQuotedRecord['author']['handle'] ?? '');
                                    $rqAuthorHandle = $rqAuthorHandleRaw !== 'handle.invalid' ? '<i>@' . $rqAuthorHandleRaw . '</i>' : '';

                                    $rqParts = explode('/', (string)($replyQuotedRecord['uri'] ?? ''));
                                    $rqPostId = end($rqParts);
                                    $rqPostUri = self::URI . '/profile/' . $this->fallbackAuthor($replyQuotedRecord['author'] ?? [], 'url') . '/post/' . $rqPostId;

                                    $description .= $this->getPostDescription(
                                        $rqDisplayName,
                                        $rqAuthorHandle,
                                        $rqPostUri,
                                        $replyQuotedRecord,
                                        'quote'
                                    );

                                    if (isset($replyQuotedRecord['value']['embed']['$type']) === true) {
                                        $rqEmbedType = (string)$replyQuotedRecord['value']['embed']['$type'];

                                        if ($rqEmbedType === 'app.bsky.embed.external') {
                                            $description .= $this->parseExternal($replyQuotedRecord['value']['embed']['external'] ?? [], $rqAuthorDid);
                                        }

                                        if (
                                            $rqEmbedType === 'app.bsky.embed.video' ||
                                            (
                                                $rqEmbedType === 'app.bsky.embed.recordWithMedia' &&
                                                isset($replyQuotedRecord['value']['embed']['media']['$type']) === true &&
                                                (string)$replyQuotedRecord['value']['embed']['media']['$type'] === 'app.bsky.embed.video'
                                            )
                                        ) {
                                            $rqVideoData = $replyQuotedRecord['value']['embed']['video'] ?? $replyQuotedRecord['value']['embed']['media']['video'] ?? [];
                                            if (is_array($rqVideoData) === true) {
                                                $description .= $this->getPostVideoDescription($rqVideoData, $rqAuthorDid);
                                            }
                                        }

                                        if (
                                            $rqEmbedType === 'app.bsky.embed.gallery' ||
                                            $rqEmbedType === 'app.bsky.embed.images' ||
                                            (
                                                $rqEmbedType === 'app.bsky.embed.recordWithMedia' &&
                                                isset($replyQuotedRecord['value']['embed']['media']['$type']) === true &&
                                                (string)$replyQuotedRecord['value']['embed']['media']['$type'] === 'app.bsky.embed.images'
                                            )
                                        ) {
                                            $rqEmbeds = $replyQuotedRecord['embeds'] ?? [];
                                            if (is_array($rqEmbeds) === true) {
                                                foreach ($rqEmbeds as $rqEmbed) {
                                                    if (is_array($rqEmbed) === false) {
                                                        continue;
                                                    }
                                                    $rqEmbedTypeCheck = (string)($rqEmbed['$type'] ?? '');
                                                    if (
                                                        $rqEmbedTypeCheck === 'app.bsky.embed.gallery#view' ||
                                                        $rqEmbedTypeCheck === 'app.bsky.embed.images#view' ||
                                                        ($rqEmbedTypeCheck === 'app.bsky.embed.recordWithMedia#view' && isset($rqEmbed['media']['$type']) === true && (string)$rqEmbed['media']['$type'] === 'app.bsky.embed.images#view')
                                                    ) {
                                                        $rqImages = $rqEmbed['items'] ?? $rqEmbed['images'] ?? $rqEmbed['media']['images'] ?? [];
                                                        if (is_array($rqImages) === true) {
                                                            foreach ($rqImages as $rqImage) {
                                                                if (is_array($rqImage) === true) {
                                                                    $description .= $this->getPostImageDescription($rqImage);
                                                                }
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                            $description .= '</p>';
                        }
                    }
                }
            }

            $item['content'] = $description;
            $this->items[] = $item;
        }
    }

    private function getPostVideoDescription(array $video, string $authorDID): string
    {
        $videoCID = (string)($video['ref']['$link'] ?? '');
        $videoMime = (string)($video['mimeType'] ?? '');
        $thumbnail = "poster=\"https://video.bsky.app/watch/{$authorDID}/{$videoCID}/thumbnail.jpg\"";
        $videoURL = "https://bsky.social/xrpc/com.atproto.sync.getBlob?did={$authorDID}&cid={$videoCID}";
        return "<figure><video loop {$thumbnail} preload=\"none\" controls src=\"{$videoURL}\" type=\"{$videoMime}\"/></figure>";
    }

    private function getPostImageDescription(array $image): string
    {
        $thumbnailUrl = (string)($image['thumb'] ?? $image['thumbnail'] ?? '');
        $fullsizeUrl = (string)($image['fullsize'] ?? '');
        $alt = strlen((string)($image['alt'] ?? '')) > 0 ? '<figcaption>' . $this->e((string)$image['alt']) . '</figcaption>' : '';
        return "<figure><a href=\"{$fullsizeUrl}\"><img src=\"{$thumbnailUrl}\"></a>{$alt}</figure>";
    }

    private function getPostDescription(
        string $postDisplayName,
        string $postAuthorHandle,
        string $postUri,
        array $postRecord,
        string $type
    ): string {
        $description = '';
        if ($type === 'quote') {
            $postType = isset($postRecord['reply']) === true ? 'reply' : 'post';
            $description .= "<a href=\"{$postUri}\">Quoted {$postType}</a> from <b>{$postDisplayName}</b> {$postAuthorHandle}:<br>";
        } elseif ($type === 'reply') {
            $postType = isset($postRecord['reply']) === true ? 'reply' : 'post';
            $description .= "Replying to <b>{$postDisplayName}</b> {$postAuthorHandle}'s <a href=\"{$postUri}\">{$postType}</a>:<br>";
        } else {
            $postType = isset($postRecord['reply']) === true ? 'replied' : 'posted';
            $description .= "<b>{$postDisplayName}</b> {$postAuthorHandle} <a href=\"{$postUri}\">{$postType}</a>:<br>";
        }
        $description .= $this->textToDescription($postRecord);
        return $description;
    }

    private function fallbackAuthor(array $author, string $reason): string
    {
        if (($author['handle'] ?? '') === 'handle.invalid') {
            if ($reason === 'url') {
                return (string)($author['did'] ?? '');
            }
            if ($reason === 'display') {
                return $this->e((string)($author['displayName'] ?? ''));
            }
        }
        return (string)($author['handle'] ?? '');
    }

    private function generateVerboseTitle(array $post): string
    {
        $title = '';
        if (isset($post['reason']['$type']) === true && str_contains((string)$post['reason']['$type'], 'reasonRepost') === true) {
            $title .= 'Repost by ' . $this->fallbackAuthor($post['reason']['by'] ?? [], 'display');
            if (isset($post['reply']) === true) {
                $title .= ', reply by ';
            } else {
                $title .= ', post by ';
            }
            $title .= $this->fallbackAuthor($post['post']['author'] ?? [], 'display');
        } else {
            if (isset($post['reply']) === true) {
                $title .= 'Reply by ' . $this->fallbackAuthor($post['post']['author'] ?? [], 'display');
            } else {
                $title .= 'Post by ' . $this->fallbackAuthor($post['post']['author'] ?? [], 'display');
            }
        }

        if (isset($post['reply']) === true) {
            if (isset($post['reply']['parent']['blocked']) === true) {
                $replyAuthor = 'blocked user';
            } elseif (isset($post['reply']['parent']['notFound']) === true) {
                $replyAuthor = 'deleted post';
            } else {
                $replyAuthor = $this->fallbackAuthor($post['reply']['parent']['author'] ?? [], 'display');
            }
            $title .= ', replying to ' . $replyAuthor;
        }

        if (
            isset($post['post']['embed']['record']) === true &&
            (($post['post']['embed']['record']['$type'] ?? '') !== 'app.bsky.feed.defs#generatorView') &&
            (($post['post']['embed']['record']['$type'] ?? '') !== 'app.bsky.graph.defs#listView') &&
            (($post['post']['embed']['record']['$type'] ?? '') !== 'app.bsky.graph.defs#starterPackViewBasic')
        ) {
            if (isset($post['post']['embed']['record']['blocked']) === true) {
                $quotedAuthor = 'blocked user';
            } elseif (isset($post['post']['embed']['record']['notFound']) === true) {
                $quotedAuthor = 'deleted post';
            } elseif (isset($post['post']['embed']['record']['detached']) === true) {
                $quotedAuthor = 'detached post';
            } else {
                $quotedAuthorRecord = $post['post']['embed']['record']['record'] ?? $post['post']['embed']['record'];
                $quotedAuthor = $this->fallbackAuthor($quotedAuthorRecord['author'] ?? $quotedAuthorRecord ?? [], 'display');
            }
            $title .= ', quoting ' . $quotedAuthor;
        }
        return $title;
    }

    private function resolveHandle(string $handle): string
    {
        $uri = 'https://public.api.bsky.app/xrpc/com.atproto.identity.resolveHandle?handle=' . urlencode($handle);

        $cached = $this->cache->get($uri);
        if ($cached !== null && is_array($cached) === true && isset($cached['did']) === true) {
            return (string)$cached['did'];
        }

        $response = getContents($uri);
        if ($response === '') {
            throwClientException('Failed to resolve Bluesky handle.');
        }

        $data = json_decode($response, true);
        if (is_array($data) === false || isset($data['did']) === false) {
            throwClientException('Failed to resolve Bluesky handle.');
        }

        $this->cache->set($uri, $data, 7 * 24 * 60 * 60);
        return (string)$data['did'];
    }

    private function getProfile(string $did): array
    {
        $uri = 'https://public.api.bsky.app/xrpc/app.bsky.actor.getProfile?actor=' . urlencode($did);

        $cached = $this->cache->get($uri);
        if ($cached !== null && is_array($cached) === true && isset($cached['did']) === true) {
            return $cached;
        }

        $response = getContents($uri);
        if ($response === '') {
            return [];
        }

        $data = json_decode($response, true);
        if (is_array($data) === false) {
            return [];
        }

        if (isset($data['did']) === true && (string)$data['did'] === $did) {
            $this->cache->set($uri, $data, 86400);
        }

        return $data;
    }

    private function getAuthorFeed(string $did, string $filter): array
    {
        $uri = 'https://public.api.bsky.app/xrpc/app.bsky.feed.getAuthorFeed?actor=' . urlencode($did) . '&filter=' . urlencode($filter) . '&limit=30';

        $response = getContents($uri);
        if ($response === '') {
            return [];
        }

        $data = json_decode($response, true);
        if (is_array($data) === false) {
            return [];
        }

        return $data;
    }

    private function getListFeedDescription(array $record): string
    {
        $avatar = isset($record['avatar']) === true ? '<img src="' . preg_replace('/\/img\/avatar\//', '/img/avatar_thumbnail/', (string)$record['avatar']) . '">' : '';
        $feedViewName = $this->e((string)($record['displayName'] ?? $record['name'] ?? ''));
        $feedViewDescription = $this->e((string)($record['description'] ?? ''));
        $authorDisplayName = $this->e((string)($record['creator']['displayName'] ?? ''));
        $authorHandle = $this->e((string)($record['creator']['handle'] ?? ''));
        $likeCount = isset($record['likeCount']) === true ? '<br>Liked by ' . $this->e((string)$record['likeCount']) . ' users' : '';

        preg_match('/\/([^\/]+)$/', (string)($record['uri'] ?? ''), $matches);
        $uriId = $matches[1] ?? '';

        if (($record['purpose'] ?? '') === 'app.bsky.graph.defs#modlist') {
            $typeURL = '/lists/';
            $typeDesc = 'moderation list';
        } elseif (($record['purpose'] ?? '') === 'app.bsky.graph.defs#curatelist') {
            $typeURL = '/lists/';
            $typeDesc = 'list';
        } else {
            $typeURL = '/feed/';
            $typeDesc = 'feed';
        }

        $uri = $this->e('https://bsky.app/profile/' . ($record['creator']['did'] ?? '') . $typeURL . $uriId);

        return <<<END
<blockquote>
<b><a href="{$uri}">{$feedViewName}</a></b><br/>
Bluesky {$typeDesc} by <b>{$authorDisplayName}</b> <i>@{$authorHandle}</i>
<figure>
{$avatar}
<figcaption>{$feedViewDescription}{$likeCount}</figcaption>
</figure>
</blockquote>
END;
    }

    private function getStarterPackDescription(array $record): string
    {
        if (isset($record['record']) === false) {
            return 'Failed to get starter pack information.';
        }
        $starterpackRecord = $record['record'];
        $starterpackName = $this->e((string)($starterpackRecord['name'] ?? ''));
        $starterpackDescription = $this->e((string)($starterpackRecord['description'] ?? ''));
        $creatorDisplayName = $this->e((string)($record['creator']['displayName'] ?? ''));
        $creatorHandle = $this->e((string)($record['creator']['handle'] ?? ''));

        preg_match('/\/([^\/]+)$/', (string)($starterpackRecord['list'] ?? ''), $matches);
        $listId = $matches[1] ?? '';
        $uri = $this->e('https://bsky.app/starter-pack/' . ($record['creator']['did'] ?? '') . '/' . $listId);

        return <<<END
<blockquote>
<b><a href="{$uri}">{$starterpackName}</a></b><br/>
Bluesky starter pack by <b>{$creatorDisplayName}</b> <i>@{$creatorHandle}</i><br/>
{$starterpackDescription}
</blockquote>
END;
    }
}
