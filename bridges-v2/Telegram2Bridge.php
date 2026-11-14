<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class Telegram2Bridge extends BridgeAbstract
{
    public const NAME = 'Telegram';
    public const URI = 'https://t.me';
    public const DESCRIPTION = 'Returns the recent publications from a public Telegram channel. Supports embedded media content, hides ads and unsupported content.';
    public const MAINTAINER = 'LordArrin';
    public const CACHE_TIMEOUT = 1200;

    private const PROXY_PROFILE = 'tgws';

    public const PARAMETERS = [[
        'username' => [
            'name' => 'Channel name',
            'type' => 'text',
            'required' => true,
            'exampleValue' => 'durov',
        ],
        'limit' => [
            'name' => 'Max posts',
            'type' => 'number',
            'required' => false,
            'defaultValue' => 10,
        ],
        'use_proxy' => [
            'name' => 'Use proxy',
            'type' => 'checkbox',
            'defaultValue' => 'checked',
            'title' => 'Route requests through the TgWSProxy profile configured on the server',
        ],
        'embed_media' => [
            'name' => 'Embed media',
            'type' => 'list',
            'values' => [
                'Auto (follow proxy)' => 'auto',
                'Always embed' => 'on',
                'Never embed' => 'off',
            ],
            'defaultValue' => 'auto',
            'title' => <<<'TXT'
Download media and embed it as data URIs, so clients need no access to Telegram CDN
TXT,
        ],
        'skip_unsupported' => [
            'name' => 'Skip unsupported content',
            'type' => 'checkbox',
            'defaultValue' => 'checked',
            'title' => 'Hide unsupported content, skip posts that contain only unsupported media',
        ],
        'hide_hashtags' => [
            'name' => 'Hide hashtags',
            'type' => 'checkbox',
            'defaultValue' => 'checked',
            'title' => 'Remove hashtags from post text and assign them as feed item categories',
        ],
        'hide_external_links' => [
            'name' => 'Hide external Telegram links',
            'type' => 'checkbox',
            'title' => 'Hide posts containing links or mentions of other Telegram channels/users',
        ],
        'include_keywords' => [
            'name' => 'Include keywords',
            'type' => 'text',
            'required' => false,
            'title' => <<<'TXT'
Show ONLY posts matching keywords.
Syntax is the same as Exclude keywords: comma-separated rules,
"+" joins words with AND, matching is substring-based and case-insensitive.
A post is kept only if it matches at least one rule.
When both Include and Exclude are set,
a post must first match Include, then survive Exclude.
TXT,
        ],
        'exclude_keywords' => [
            'name' => 'Exclude keywords',
            'type' => 'text',
            'required' => false,
            'title' => <<<'TXT'
Hide posts matching keywords.
Rules are comma-separated, case-insensitive, and matched as substrings
against both title and body.
A rule without "+" hides any post containing it
(e.g. "casino" also matches "casinos").
Join words with "+" to require ALL of them
(e.g. "casino+bonus" hides a post only if both words are present).
Multiple rules act as OR: a post is hidden if it matches ANY rule.
Example: "casino, bonus+promo, ads" hides posts with "casino",
or with both "bonus" and "promo", or with "ads".
TXT,
        ],
    ]];

    public const CONFIGURATION = [
        'embed_max_size' => [
            'required' => false,
            'defaultValue' => '32m',
        ],
    ];

    private const TG_HOSTS = '(?:[\w-]+\.)*(?:telegram\.org|t\.me|telesco\.pe)';

    private const MAX_PAGES = 100;
    private const PROXY_RETRIES = 4;
    private const PAGE_DELAY_US = 1000000;
    private const RETRY_BUDGET_SECONDS = 30;

    private const MAX_TITLE_LENGTH = 60;
    private const MIN_TITLE_SPACE_POS = 30;
    private const SHORT_POST_MAX_LENGTH = 100;

    private const ALLOWED_TAGS = '<div><a><p><br><hr><b><i><u><s><strong><em><code><pre><blockquote><span><img><video><source><ul><ol><li>';

    private const TELEGRAM_SPECIAL_PAGES = [
        'addstickers', 'addtheme', 'boost', 'confirmphone', 'donate',
        'giftcode', 'invoice', 'joinchat', 'login', 'proxy',
        'socks', 'setlanguage', 'share', 'addemoji', 'addlist',
    ];

    private const FATAL_ERROR_PATTERNS = [
        'port restricted',
        'only supports port 443',
        'authentication failed',
        '403 forbidden',
        '401 unauthorized',
    ];

    private const CSS = [
        'unsup_wrap'  => 'background:#17212b;border-radius:12px;padding:28px 16px;text-align:center',
        'unsup_label' => 'color:#708499;font-size:14px;margin-bottom:16px',
        'unsup_btn' => <<<'CSS'
display:inline-block;background:#2b5278;color:#6ab2f2;
text-decoration:none;text-transform:uppercase;font-weight:bold;
font-size:13px;letter-spacing:0.03em;padding:10px 24px;border-radius:8px
CSS,
        'video'       => 'max-width:100%',
        'wrapper'     => 'font-size:14px;line-height:1.6;word-wrap:break-word',
        'quote'       => 'border-left:4px solid #4a76a8;padding-left:12px;margin:8px 0',
        'reply_compact' => 'border-left:4px solid #27a7e7;padding:8px 12px;margin-bottom:12px;font-size:13px;line-height:1.4',
        'reply_link'  => 'font-weight:500',
        'poll'        => 'background:padding:15px;margin:10px 0;border-left:4px solid #4a76a8',
        'poll_t'      => 'margin:0 0 10px 0;font-weight:bold',
        'poll_o'      => 'margin:8px 0',
        'poll_f'      => 'margin:10px 0 0 0;color:#888;font-size:0.85em',
    ];

    private const SELECTORS = [
        'CHANNEL_TITLE'          => 'div.tgme_channel_info_header_title span',
        'MESSAGE_WRAP'           => 'div.tgme_widget_message_wrap.js-widget_message_wrap',
        'LOAD_MORE_LINK'         => 'div.tgme_widget_message_centered.js-messages_more_wrap a',
        'MESSAGE_DATE_LINK'      => 'a.tgme_widget_message_date',
        'MESSAGE_TIMESTAMP'      => 'span.tgme_widget_message_meta time',

        'UNSUPPORTED_CONT'       => 'div.media_not_supported_cont',
        'FORWARDED_FROM'         => 'div.tgme_widget_message_forwarded_from',
        'FORWARDED_AUTHOR'       => 'span.tgme_widget_message_forwarded_from_author',
        'REPLY_LINK'             => 'a.tgme_widget_message_reply',
        'REPLY_AUTHOR'           => 'span.tgme_widget_message_author_name',
        'REPLY_METATEXT'         => 'div.tgme_widget_message_metatext',
        'MESSAGE_TEXT'           => 'div.tgme_widget_message_text.js-message_text',
        'MESSAGE_TEXT_SIMPLE'    => 'div.tgme_widget_message_text',
        'REPLY_TEXT'             => 'div.tgme_widget_message_text.js-message_reply_text',

        'PHOTO_LINK'             => 'a.tgme_widget_message_photo_wrap',

        'VIDEO_PLAYER_LINK'      => 'a.tgme_widget_message_video_player',
        'VIDEO_PLAYER_DIV'       => 'div.tgme_widget_message_video_player',
        'VIDEO_PLAYER_LINK_NS'   => 'a.tgme_widget_message_video_player.not_supported',
        'VIDEO_PLAYER_DIV_NS'    => 'div.tgme_widget_message_video_player.not_supported',
        'VIDEO_THUMB'            => 'i.tgme_widget_message_video_thumb',
        'VIDEO_PREVIEW_THUMB'    => 'i.link_preview_video_thumb',
        'ROUNDVIDEO_THUMB'       => 'i.tgme_widget_message_roundvideo_thumb',
        'VIDEO_DURATION_TIME'    => 'time.tgme_widget_message_video_duration',
        'VIDEO_DURATION_SPAN'    => 'span.tgme_widget_message_video_duration',
        'VIDEO_DURATION_FALLBACK' => 'time.message_video_duration',

        'STICKER_WRAP'           => 'div.tgme_widget_message_sticker_wrap',

        'POLL'                   => 'div.tgme_widget_message_poll',
        'POLL_QUESTION'          => 'div.tgme_widget_message_poll_question',
        'POLL_TYPE'              => 'div.tgme_widget_message_poll_type',
        'POLL_OPTION'            => 'div.tgme_widget_message_poll_option',
        'POLL_PERCENT'           => 'div.tgme_widget_message_poll_option_percent',
        'POLL_TEXT'              => 'div.tgme_widget_message_poll_option_text',
        'VOTERS'                 => 'span.tgme_widget_message_voters',

        'DOCUMENT'               => 'div.tgme_widget_message_document',
        'DOCUMENT_TITLE'         => 'div.tgme_widget_message_document_title',
        'DOCUMENT_EXTRA'         => 'div.tgme_widget_message_document_extra',

        'LINK_PREVIEW'           => 'a.tgme_widget_message_link_preview',
        'LINK_PREVIEW_TITLE'     => 'div.link_preview_title',
        'LINK_PREVIEW_SITE'      => 'div.link_preview_site_name',
        'LINK_PREVIEW_DESC'      => 'div.link_preview_description',

        'LOCATION'               => 'div.tgme_widget_message_location',
        'LOCATION_WRAP'          => 'a.tgme_widget_message_location_wrap',

        'UNSUPPORTED_WRAP'       => 'div.message_media_not_supported_wrap',
        'UNSUPPORTED_LABEL'      => 'div.message_media_not_supported_label',
        'SUPPORTED_CONT'         => 'div.media_supported_cont',

        'PAGE_PHOTO_IMG'         => 'i.tgme_page_photo_image img',
        'ANY_WITH_CLASS'         => '[class]',
        'META_TAGS'              => 'meta',
    ];

    private const MEDIA_TYPE_STICKER = 'sticker';
    private const MEDIA_TYPE_POLL = 'poll';
    private const MEDIA_TYPE_PHOTO = 'photo';
    private const MEDIA_TYPE_ATTACHMENT = 'attachment';
    private const MEDIA_TYPE_LINK_PREVIEW = 'link_preview';
    private const MEDIA_TYPE_LOCATION = 'location';

    private const EMBED_MODE_AUTO = 'auto';
    private const EMBED_MODE_ALWAYS = 'on';
    private const EMBED_MODE_NEVER = 'off';

    private const UNSUPPORTED_REASON_TOO_BIG = 'too_big';
    private const UNSUPPORTED_REASON_DEFAULT = 'default';

    private const UNSUPPORTED_TYPE_VIDEO = 'video';
    private const UNSUPPORTED_TYPE_GENERIC = 'generic';

    private const MEDIA_HANDLERS = [
        self::MEDIA_TYPE_STICKER => ['class' => 'tgme_widget_message_sticker_wrap', 'method' => 'processSticker'],
        self::MEDIA_TYPE_POLL => ['class' => 'tgme_widget_message_poll', 'method' => 'processPoll'],
        self::MEDIA_TYPE_PHOTO => ['class' => 'tgme_widget_message_photo_wrap', 'method' => 'processPhoto'],
        self::MEDIA_TYPE_ATTACHMENT => ['class' => 'tgme_widget_message_document', 'method' => 'processAttachment'],
        self::MEDIA_TYPE_LINK_PREVIEW => ['class' => 'tgme_widget_message_link_preview', 'method' => 'processLinkPreview'],
        self::MEDIA_TYPE_LOCATION => ['class' => 'tgme_widget_message_location_wrap', 'method' => 'processLocation'],
    ];

    private string $feedName = '';
    private string $feedIcon = '';
    private ?string $cachedNormalizedUsername = null;

    private function getNormalizedUsername(): string
    {
        if ($this->cachedNormalizedUsername === null) {
            $this->cachedNormalizedUsername = ltrim(trim($this->getInput('username') ?? ''), '@');
        }
        return $this->cachedNormalizedUsername;
    }

    public function collectData(): void
    {
        $this->validateUsername();

        $url = sprintf('%s/s/%s', self::URI, $this->getNormalizedUsername());

        $limitInput = $this->getInput('limit');
        if ($limitInput === null || $limitInput === '' || $limitInput === 0) {
            $limitInput = 10;
        }
        $limit = max(1, (int) $limitInput);

        $pages = 0;
        $seen = [];

        while ($pages < self::MAX_PAGES) {
            $pages++;

            if ($pages > 1) {
                usleep(self::PAGE_DELAY_US);
            }

            $dom = $this->fetchPage($url);
            if ($dom === null) {
                break;
            }

            if ($this->feedName === '') {
                $el = $dom->querySelector(self::SELECTORS['CHANNEL_TITLE']);
                $this->feedName = htmlspecialchars_decode(
                    $el !== null ? ($el->textContent ?? '') : '',
                    flags: ENT_QUOTES
                );
            }

            if ($this->feedIcon === '' && $this->getInput('use_proxy') === false) {
                $this->feedIcon = $this->extractChannelIcon($dom);
            }

            $messages = $dom->querySelectorAll(self::SELECTORS['MESSAGE_WRAP']);
            if ($this->feedName === '' && count($messages) === 0) {
                throwClientException('Unable to find channel. The channel is non-existing or non-public.');
            }

            foreach (array_reverse(iterator_to_array($messages)) as $message) {
                if (count($this->items) >= $limit) {
                    break 2;
                }

                if ($this->isAd($message) === true) {
                    continue;
                }

                $item = $this->parseMessage($message);
                $notSupported = $this->detectNotSupported($message);
                $hasContent = $this->hasContent($item);

                if ($hasContent === false && $notSupported === null) {
                    continue;
                }

                if ($hasContent === false && $this->getInput('skip_unsupported') === true) {
                    continue;
                }

                if ($notSupported !== null && $hasContent === true && $this->isShortPost($item) === true) {
                    continue;
                }

                if ($notSupported !== null && $this->getInput('skip_unsupported') === false) {
                    $this->applyNotSupportedStub($item, $message, $notSupported, $hasContent);
                }

                if ($this->isBlocked($item, $message) === true) {
                    continue;
                }

                $this->items[] = $item;
            }

            $more = $dom->querySelector(self::SELECTORS['LOAD_MORE_LINK']);
            if ($more !== null && str_contains($more->getAttribute('href') ?? '', 'before') === true) {
                $next = 'https://t.me' . $more->getAttribute('href');
                if (isset($seen[$next]) === true) {
                    break;
                }
                $seen[$next] = true;
                $url = $next;
            } else {
                break;
            }
        }
    }

    public function getURI(): string
    {
        if ($this->getInput('username') !== null) {
            return sprintf('%s/s/%s', self::URI, $this->getNormalizedUsername());
        }

        return parent::getURI();
    }

    public function getName(): string
    {
        if ($this->feedName !== '') {
            return $this->feedName;
        }

        return parent::getName();
    }

    public function getIcon(): string
    {
        if ($this->feedIcon !== '') {
            return $this->feedIcon;
        }

        return parent::getIcon();
    }

    /**
     * @param mixed $url
     * @return array<string, string>|null
     */
    public function detectParameters(mixed $url): ?array
    {
        $re = '/^https?:\/\/(?:(?:t|telegram)\.me\/(?:s\/)?([\w]+)|([\w]+)\.t\.me\/?)$/';

        if (preg_match($re, (string) $url, $m) === 1) {
            $username = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
            if ($username !== '') {
                return ['username' => $username];
            }
        }

        return null;
    }

    private function withRetry(\Closure $fn, string $context, string $url = ''): mixed
    {
        $deadline = microtime(true) + self::RETRY_BUDGET_SECONDS;
        $lastException = null;

        for ($i = 0; $i < self::PROXY_RETRIES; $i++) {
            try {
                return $fn();
            } catch (\Throwable $e) {
                $lastException = $e;
                $errorMsg = $e->getMessage();

                if ($this->isFatalError($errorMsg) === true) {
                    break;
                }

                $remaining = $deadline - microtime(true);
                if ($remaining <= 1.0 || $i >= self::PROXY_RETRIES - 1) {
                    if ($remaining <= 1.0 && $this->logger !== null) {
                        $this->logger->warning(sprintf(
                            '%s retry budget (%ds) exhausted after %d attempt(s)%s: %s',
                            $context,
                            self::RETRY_BUDGET_SECONDS,
                            $i + 1,
                            $url !== '' ? " for {$url}" : '',
                            $errorMsg
                        ));
                    }
                    break;
                }

                if ($this->logger !== null) {
                    $this->logger->warning(sprintf(
                        '%s failed (attempt %d/%d)%s: %s',
                        $context,
                        $i + 1,
                        self::PROXY_RETRIES,
                        $url !== '' ? " for {$url}" : '',
                        $errorMsg
                    ));
                }

                $delayUs = min(($i + 1) * 500000, 2000000);
                $delayUs = min($delayUs, (int) (($remaining - 1.0) * 1000000));
                usleep(max(100000, $delayUs));
            }
        }

        if ($lastException !== null) {
            throw $lastException;
        }
        throw new \RuntimeException(sprintf('%s failed completely without a specific exception', $context));
    }

    private function isFatalError(string $errorMsg): bool
    {
        $errorMsgLower = strtolower($errorMsg);

        foreach (self::FATAL_ERROR_PATTERNS as $pattern) {
            if (str_contains($errorMsgLower, $pattern) === true) {
                return true;
            }
        }

        return false;
    }

    private function validateUsername(): void
    {
        $username = $this->getNormalizedUsername();
        if (preg_match('/^[a-zA-Z0-9_]{5,32}$/', $username) !== 1) {
            throwClientException(sprintf(
                'Invalid Telegram username "%s". Expected 5-32 alphanumeric characters or underscores.',
                $username
            ));
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function hasContent(array $item): bool
    {
        return trim(strip_tags($item['content'] ?? '')) !== ''
            || trim($item['title'] ?? '') !== '';
    }

    private function fetchPage(string $url): ?\Dom\HTMLDocument
    {
        $useProxy = (bool) $this->getInput('use_proxy');

        if ($useProxy === true) {
            try {
                return $this->withRetry(
                    function () use ($url): \Dom\HTMLDocument {
                        $html = getProtectedSimpleHTMLDOM($url, self::PROXY_PROFILE);
                        $htmlString = $html->saveHtml();

                        if ($htmlString === '') {
                            throw new \RuntimeException('saveHtml returned empty result');
                        }

                        return \Dom\HTMLDocument::createFromString(
                            source: $htmlString,
                            options: LIBXML_NOERROR
                        );
                    },
                    'TgWSProxy page fetch',
                    $url
                );
            } catch (\Throwable $e) {
                if ($this->logger !== null) {
                    $this->logger->warning(sprintf(
                        'TgWSProxy failed for %s: %s, falling back to direct',
                        $url,
                        $e->getMessage()
                    ));
                }
            }
        }

        return $this->fetchPageDirect($url);
    }

    private function fetchPageDirect(string $url): ?\Dom\HTMLDocument
    {
        try {
            return $this->withRetry(
                function () use ($url): \Dom\HTMLDocument {
                    $html = getSimpleHTMLDOM($url);
                    $htmlString = $html->saveHtml();

                    if ($htmlString === '') {
                        throw new \RuntimeException('saveHtml returned empty result');
                    }

                    return \Dom\HTMLDocument::createFromString(
                        source: $htmlString,
                        options: LIBXML_NOERROR
                    );
                },
                'Direct page fetch',
                $url
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parseMessage(\Dom\Element $message): array
    {
        /** @var array<string, mixed> $context */
        $context = ['title' => '', 'author' => '', 'hashtags' => []];

        $uri = $this->extractUri($message);
        $contentResult = $this->processContent($message, $context);

        $item = [];
        $item['uri'] = $uri;
        $item['content'] = $contentResult['html'];
        $item['title'] = $contentResult['title'];

        if ($contentResult['author'] !== '' && $contentResult['author'] !== $this->feedName) {
            $item['author'] = $contentResult['author'];
        }

        $timestamp = $this->extractTimestamp($message);
        if ($timestamp !== null) {
            $item['timestamp'] = $timestamp;
        }

        $item['content'] = $item['content']
            |> $this->removeViewInTelegram(...)
            |> $this->normalizeText(...)
            |> $this->embedMediaInHtml(...)
            |> $this->sanitizeContent(...);

        if ($this->getInput('hide_hashtags') === false && $context['hashtags'] !== []) {
            $item['categories'] = $context['hashtags'];
        }

        return $item;
    }

    private function extractUri(\Dom\Element $message): string
    {
        $el = $message->querySelector(self::SELECTORS['MESSAGE_DATE_LINK']);
        return $el !== null ? ($el->getAttribute('href') ?? '') : '';
    }

    private function extractTimestamp(\Dom\Element $message): ?string
    {
        $el = $message->querySelector(self::SELECTORS['MESSAGE_TIMESTAMP']);
        if ($el === null) {
            return null;
        }
        $dt = $el->getAttribute('datetime');
        return ($dt !== null && $dt !== '') ? $dt : null;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function processContent(\Dom\Element $messageDiv, array &$context): array
    {
        foreach ($messageDiv->querySelectorAll(self::SELECTORS['UNSUPPORTED_CONT']) as $fake) {
            $fake->outerHTML = '';
        }

        $html = '';

        $fwd = $messageDiv->querySelector(self::SELECTORS['FORWARDED_FROM']);
        if ($fwd !== null) {
            $context['author'] = $this->extractForwardedAuthor($fwd);
        }

        $reply = $messageDiv->querySelector(self::SELECTORS['REPLY_LINK']);
        if ($reply !== null) {
            $html .= $this->processReply($reply);
        }

        $inner = $messageDiv->innerHTML;

        $textPieces = [];

        $textDiv = $messageDiv->querySelector(self::SELECTORS['MESSAGE_TEXT']);
        if ($textDiv !== null) {
            $outer = $textDiv->outerHTML;
            $pos = strpos($inner, $outer);
            $textPieces[] = [$pos !== false ? $pos : PHP_INT_MAX, 'processText', $textDiv];
        }

        $mediaPieces = [];

        foreach (self::MEDIA_HANDLERS as $type => $handler) {
            $className = $handler['class'];
            $el = $messageDiv->querySelector('div.' . $className);
            if ($el === null) {
                $el = $messageDiv->querySelector('a.' . $className);
            }
            if ($el !== null) {
                $outer = $el->outerHTML;
                $pos = strpos($inner, $outer);
                $mediaPieces[] = [$pos !== false ? $pos : PHP_INT_MAX, $handler['method'], $messageDiv];
            }
        }

        $videoNotSupported = $messageDiv->querySelector(self::SELECTORS['VIDEO_PLAYER_LINK_NS']);
        if ($videoNotSupported === null) {
            $videoNotSupported = $messageDiv->querySelector(self::SELECTORS['VIDEO_PLAYER_DIV_NS']);
        }
        if ($videoNotSupported === null && $messageDiv->querySelector('video') !== null) {
            $pos = strpos($inner, '<video');
            if ($pos !== false) {
                $mediaPieces[] = [$pos, 'processVideo', $messageDiv];
            }
        }

        usort($textPieces, fn($a, $b) => $a[0] <=> $b[0]);
        usort($mediaPieces, fn($a, $b) => $a[0] <=> $b[0]);

        foreach (array_merge($textPieces, $mediaPieces) as $piece) {
            $method = $piece[1];
            $element = $piece[2];

            $partHtml = match ($method) {
                'processText'        => $this->processText($element, $context),
                'processVideo'       => $this->processVideo($element, $context),
                'processPhoto'       => $this->processPhoto($element, $context),
                'processPoll'        => $this->processPoll($element, $context),
                'processAttachment'  => $this->processAttachment($element, $context),
                'processLinkPreview' => $this->processLinkPreview($element, $context),
                'processLocation'    => $this->processLocation($element, $context),
                'processSticker'     => $this->processSticker($element, $context),
                default              => '',
            };

            if ($partHtml === '') {
                continue;
            }

            if ($html !== '') {
                $html .= '<br /><br />';
            }
            $html .= $partHtml;
        }

        return [
            'html' => $html,
            'title' => $context['title'],
            'author' => $context['author'],
            'hashtags' => $context['hashtags'],
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function processText(\Dom\Element $textDiv, array &$context): string
    {
        $nested = $textDiv->querySelector(self::SELECTORS['MESSAGE_TEXT']);
        if ($nested !== null) {
            $textDiv = $nested;
        }

        $inner = $textDiv->innerHTML;

        $extracted = $this->extractHashtags($inner);
        $inner = $extracted['html'];
        $context['hashtags'] = $extracted['tags'];

        $plain = $this->htmlToPlain($inner);

        if (mb_strlen($plain, 'UTF-8') <= self::MAX_TITLE_LENGTH) {
            $context['title'] = $plain;
            return '';
        }

        $split = $this->splitTitleAndContent($inner);
        $context['title'] = $split['title'];

        if ($split['html'] === '') {
            return '';
        }

        $dir = $textDiv->getAttribute('dir') ?? '';
        $attr = $dir !== '' ? ' dir="' . $dir . '"' : '';

        return sprintf(
            '<div class="tgme_widget_message_text js-message_text"%s>%s</div>',
            $attr,
            $split['html']
        );
    }

    /**
     * @return array{title: string, html: string}
     */
    private function splitTitleAndContent(string $html): array
    {
        $html = preg_replace('/^(?:\s*<br\s*\/?>)+\s*/i', '', $html) ?? $html;

        if (preg_match('/<br\s*\/?>/i', $html, $m, PREG_OFFSET_CAPTURE) === 1) {
            $firstLineHtml = substr($html, 0, $m[0][1]);
            $firstLinePlain = $this->htmlToPlain($firstLineHtml);

            if ($firstLinePlain !== '' && mb_strlen($firstLinePlain) <= self::MAX_TITLE_LENGTH) {
                $restHtml = substr($html, $m[0][1] + strlen($m[0][0]));
                return [
                    'title' => $firstLinePlain,
                    'html' => trim(preg_replace('/^(?:\s*<br\s*\/?>)+\s*/i', '', $restHtml) ?? '')
                ];
            }
        }

        $paragraphs = preg_split('/(?:\s*<br\s*\/?>\s*){2,}/i', $html);
        if ($paragraphs === false) {
            $paragraphs = [$html];
        }
        $firstPlain = $this->htmlToPlain($paragraphs[0]);

        if (mb_strlen($firstPlain) <= self::MAX_TITLE_LENGTH) {
            return [
                'title' => $firstPlain,
                'html' => trim(implode('<br /><br />', array_slice($paragraphs, 1)))
            ];
        }

        $prefix = $this->truncateAtWord($firstPlain, self::MAX_TITLE_LENGTH);

        $remainder = trim(mb_substr($firstPlain, mb_strlen($prefix)));
        if ($remainder !== '' && preg_match('/^[\s\p{P}]/u', $remainder) === 0 && preg_match('/[\s\p{P}]$/u', $prefix) === 0) {
            $sp = mb_strrpos($prefix, ' ');
            if ($sp !== false && $sp > self::MIN_TITLE_SPACE_POS) {
                $prefix = rtrim(mb_substr($prefix, 0, $sp));
            }
        }

        $firstHtml = $this->removeTextPrefix($paragraphs[0], $prefix);
        $restHtml = implode('<br /><br />', array_slice($paragraphs, 1));
        $contentHtml = trim($firstHtml . ($restHtml !== '' ? '<br /><br />' . $restHtml : ''));

        return ['title' => $prefix . '...', 'html' => $contentHtml];
    }

    private function htmlToPlain(string $html): string
    {
        $stripped = strip_tags(preg_replace('/<br\s*\/?>/i', ' ', $html) ?? $html);
        return html_entity_decode(
            preg_replace('/\s+/u', ' ', $stripped) ?? $stripped,
            flags: ENT_QUOTES | ENT_HTML5
        );
    }

    private function truncateAtWord(string $text, int $length): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }

        $cut = mb_substr($text, 0, $length);
        $sp = mb_strrpos($cut, ' ');

        if ($sp !== false && $sp > self::MIN_TITLE_SPACE_POS) {
            return rtrim(mb_substr($cut, 0, $sp));
        }

        return rtrim($cut);
    }

    private function removeTextPrefix(string $html, string $prefix): string
    {
        $limit = mb_strlen($prefix);
        if ($limit <= 0) {
            return $html;
        }

        $tokens = preg_split('/(<[^>]*>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($tokens === false) {
            return $html;
        }
        $void = ['br', 'img', 'hr', 'input', 'meta', 'link', 'source'];

        $consumed = 0;
        $stack = [];
        $out = '';
        $cut = false;

        foreach ($tokens as $token) {
            if ($token === '' || $cut === true) {
                $out .= $token;
                continue;
            }

            if ($token[0] === '<') {
                if (preg_match('/^<\s*\/\s*(\w+)/u', $token, $m) === 1) {
                    $tag = strtolower($m[1]);
                    $stack = array_values(array_filter(
                        $stack,
                        fn($s) => $s['tag'] !== $tag
                    ));
                } elseif (preg_match('/^<\s*(\w+)/u', $token, $m) === 1) {
                    $tag = strtolower($m[1]);
                    if (in_array($tag, $void, true) === false && str_ends_with(rtrim($token, '>'), '/') === false) {
                        $stack[] = ['tag' => $tag, 'html' => $token];
                    }
                }
                continue;
            }

            preg_match_all('/&[a-zA-Z]+;|&#\d+;|&#x[0-9a-fA-F]+;|./us', $token, $m);
            $units = $m[0] ?? [];

            if ($consumed + count($units) <= $limit) {
                $consumed += count($units);
                continue;
            }

            $cut = true;
            $skip = $limit - $consumed;

            $out .= implode('', array_column($stack, 'html'))
                . implode('', array_slice($units, $skip));
        }

        if ($cut === false) {
            return '';
        }

        return '... ' . ltrim(preg_replace('/^(?:\s*<br\s*\/?>)+\s*/i', '', $out) ?? '');
    }

    private function processReply(\Dom\Element $reply): string
    {
        $replyText = $this->getPlaintext($reply, self::SELECTORS['REPLY_TEXT']);
        $author = $this->getPlaintext($reply, self::SELECTORS['REPLY_AUTHOR']);
        $href = htmlspecialchars($reply->getAttribute('href') ?? '', ENT_QUOTES);

        if ($replyText !== '') {
            $displayText = htmlspecialchars(
                $this->truncateAtWord($replyText, self::MAX_TITLE_LENGTH),
                ENT_QUOTES
            );
        } else {
            $displayText = htmlspecialchars($author, ENT_QUOTES);
        }

        return sprintf(
            '<div style="%s"><a href="%s" style="%s">%s</a></div>',
            self::CSS['reply_compact'],
            $href,
            self::CSS['reply_link'],
            $displayText
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function processPhoto(\Dom\Element $messageDiv, array &$context): string
    {
        if ($context['title'] === '') {
            $context['title'] = '@' . $this->getNormalizedUsername() . ' posted a photo';
        }

        $out = '';
        foreach ($messageDiv->querySelectorAll(self::SELECTORS['PHOTO_LINK']) as $wrap) {
            $style = $wrap->getAttribute('style') ?? '';
            $href = $wrap->getAttribute('href') ?? '';
            $bg = extract_css_background_image($style);
            if ($bg !== null) {
                $out .= sprintf('<a href="%s"><img src="%s" /></a>', $href, $bg);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function processVideo(\Dom\Element $messageDiv, array &$context): string
    {
        if ($context['title'] === '') {
            $context['title'] = '@' . $this->getNormalizedUsername() . ' posted a video';
        }

        $poster = '';
        $thumbs = [
            self::SELECTORS['VIDEO_THUMB'],
            self::SELECTORS['VIDEO_PREVIEW_THUMB'],
            self::SELECTORS['ROUNDVIDEO_THUMB'],
        ];

        foreach ($thumbs as $sel) {
            $el = $messageDiv->querySelector($sel);
            if ($el !== null) {
                $style = $el->getAttribute('style') ?? '';
                $bg = extract_css_background_image($style);
                if ($bg !== null) {
                    $poster = $bg;
                    break;
                }
            }
        }

        $player = $messageDiv->querySelector(self::SELECTORS['VIDEO_PLAYER_LINK']);
        if ($player === null) {
            $player = $messageDiv->querySelector(self::SELECTORS['VIDEO_PLAYER_DIV']);
        }

        $postHref = '';
        if ($player !== null) {
            $playerHref = $player->getAttribute('href') ?? '';

            if ($playerHref !== '') {
                $postHref = $playerHref;
                if (str_starts_with($postHref, 'http') === false) {
                    $postHref = sprintf('%s/%s', self::URI, ltrim($postHref, '/'));
                }
            }
        }

        $videoEl = $messageDiv->querySelector('video');
        $src = $videoEl !== null ? ($videoEl->getAttribute('src') ?? '') : '';

        if ($poster === '' && $src === '' && $postHref === '') {
            return '';
        }

        $href = $postHref !== '' ? $postHref : '#';
        $channel = $this->feedName !== '' ? htmlspecialchars($this->feedName, ENT_QUOTES) : '@' . $this->getNormalizedUsername();

        $duration = $this->getPlaintext($messageDiv, self::SELECTORS['VIDEO_DURATION_TIME']);
        if ($duration === '') {
            $duration = $this->getPlaintext($messageDiv, self::SELECTORS['VIDEO_DURATION_SPAN']);
        }
        if ($duration === '') {
            $duration = $this->getPlaintext($messageDiv, self::SELECTORS['VIDEO_DURATION_FALLBACK']);
        }
        $duration = htmlspecialchars($duration, ENT_QUOTES);

        $html = '';

        if ($poster !== '') {
            $html .= sprintf('<a href="%s"><img src="%s" style="%s" /></a><br />', $href, $poster, self::CSS['video']);
        }

        $html .= sprintf('<a href="%s">Video: %s%s</a>', $href, $channel, $duration !== '' ? ' (' . $duration . ')' : '');

        return $html;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function processSticker(\Dom\Element $messageDiv, array &$context): string
    {
        if ($context['title'] === '') {
            $context['title'] = '@' . $this->getNormalizedUsername() . ' posted a sticker';
        }

        $div = $messageDiv->querySelector(self::SELECTORS['STICKER_WRAP']);
        if ($div === null) {
            return '';
        }

        $pic = $div->querySelector('picture');
        if ($pic !== null) {
            $innerDiv = $pic->querySelector('div');
            if ($innerDiv !== null) {
                $innerDiv->setAttribute('style', '');
            }
            $pic->setAttribute('style', '');
            return $div->outerHTML;
        }

        $el = $div->querySelector('i');
        if ($el !== null) {
            $style = $el->getAttribute('style') ?? '';
            $bg = extract_css_background_image($style);
            if ($bg !== null) {
                return sprintf('<img src="%s" />', $bg);
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function processPoll(\Dom\Element $messageDiv, array &$context): string
    {
        $poll = $messageDiv->querySelector(self::SELECTORS['POLL']);
        if ($poll === null) {
            return '';
        }

        $title = $this->getPlaintext($poll, self::SELECTORS['POLL_QUESTION']);
        $type = $this->getPlaintext($poll, self::SELECTORS['POLL_TYPE']);

        if ($context['title'] === '') {
            $context['title'] = $title;
        }

        $html = sprintf('<div style="%s">', self::CSS['poll']);
        $html .= sprintf('<p style="%s">%s</p>', self::CSS['poll_t'], htmlspecialchars($title, ENT_QUOTES));

        foreach ($poll->querySelectorAll(self::SELECTORS['POLL_OPTION']) as $opt) {
            $percent = $this->getPlaintext($opt, self::SELECTORS['POLL_PERCENT']);
            $text = $this->getPlaintext($opt, self::SELECTORS['POLL_TEXT']);

            $pct = max(0, min(100, (int) str_replace('%', '', $percent)));
            $filled = (int) round($pct / 5);
            $bar = '[' . str_repeat('#', $filled) . str_repeat('.', 20 - $filled) . ']';

            $html .= sprintf('<div style="%s">', self::CSS['poll_o']);
            $html .= sprintf('<b>%d%%</b> %s<br />', $pct, htmlspecialchars($text, ENT_QUOTES));
            $html .= sprintf('<code>%s</code>', $bar);
            $html .= '</div>';
        }

        $footer = [];

        $voters = htmlspecialchars($this->getPlaintext($messageDiv, self::SELECTORS['VOTERS']), ENT_QUOTES);
        if ($voters !== '') {
            $footer[] = $voters . ' voters';
        }

        if (str_contains($type, 'anonymous') === true) {
            $footer[] = 'Anonymous';
        }
        if (str_contains($type, 'quiz') === true) {
            $footer[] = 'Quiz';
        }
        if (str_contains($type, 'multiple') === true) {
            $footer[] = 'Multiple choice';
        }

        if ($footer !== []) {
            $html .= sprintf('<p style="%s">%s</p>', self::CSS['poll_f'], implode(' &#183; ', $footer));
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function processLinkPreview(\Dom\Element $messageDiv, array &$context): string
    {
        $preview = $messageDiv->querySelector(self::SELECTORS['LINK_PREVIEW']);
        if ($preview === null || trim($preview->innerHTML) === '') {
            return '';
        }

        $img = '';
        $el = $preview->querySelector('i');
        if ($el !== null) {
            $style = $el->getAttribute('style') ?? '';
            $bg = extract_css_background_image($style);
            if ($bg !== null) {
                $img = sprintf('<img src="%s" />', $bg);
            }
        }

        $title = htmlspecialchars($this->getPlaintext($preview, self::SELECTORS['LINK_PREVIEW_TITLE']), ENT_QUOTES);
        $site = htmlspecialchars($this->getPlaintext($preview, self::SELECTORS['LINK_PREVIEW_SITE']), ENT_QUOTES);
        $desc = htmlspecialchars($this->getPlaintext($preview, self::SELECTORS['LINK_PREVIEW_DESC']), ENT_QUOTES);
        $previewHref = $preview->getAttribute('href') ?? '';

        return sprintf(
            '<blockquote><a href="%s">%s</a><br /><a href="%s">%s - %s</a><br />%s</blockquote>',
            $previewHref,
            $img,
            $previewHref,
            $title,
            $site,
            $desc
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function processAttachment(\Dom\Element $messageDiv, array &$context): string
    {
        if ($context['title'] === '') {
            $context['title'] = '@' . $this->getNormalizedUsername() . ' posted an attachment';
        }

        $out = 'File attachments:<br />';
        foreach ($messageDiv->querySelectorAll(self::SELECTORS['DOCUMENT']) as $doc) {
            $docTitle = htmlspecialchars($this->getPlaintext($doc, self::SELECTORS['DOCUMENT_TITLE']), ENT_QUOTES);
            $docExtra = htmlspecialchars($this->getPlaintext($doc, self::SELECTORS['DOCUMENT_EXTRA']), ENT_QUOTES);
            $out .= sprintf('%s - %s<br />', $docTitle, $docExtra);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function processLocation(\Dom\Element $messageDiv, array &$context): string
    {
        if ($context['title'] === '') {
            $context['title'] = '@' . $this->getNormalizedUsername() . ' posted a location';
        }

        $el = $messageDiv->querySelector(self::SELECTORS['LOCATION']);
        $link = $messageDiv->querySelector(self::SELECTORS['LOCATION_WRAP']);

        if ($el === null || $link === null) {
            return '';
        }

        $style = $el->getAttribute('style') ?? '';
        $linkHref = $link->getAttribute('href') ?? '';
        $imgSrc = extract_css_background_image($style) ?? '';

        return sprintf('<a href="%s"><img src="%s" /></a>', $linkHref, $imgSrc);
    }

    /**
     * @return array{html: string, tags: list<string>}
     */
    private function extractHashtags(string $html): array
    {
        $tags = [];

        if (preg_match_all('/<a\s[^>]*href="\?q=%23[^"]*"[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $m) {
                $text = trim(strip_tags($m[1]));
                if ($text !== '' && $text[0] === '#') {
                    $tags[] = mb_substr($text, 1, encoding: 'UTF-8');
                }
            }
        }

        $html = preg_replace_callback(
            '/<a\s[^>]*href="\?q=%23[^"]*"[^>]*>(.*?)<\/a>/is',
            function (array $m): string {
                $text = trim(strip_tags($m[1]));
                if ($text !== '' && $text[0] === '#') {
                    return '';
                }
                return $m[0];
            },
            $html
        ) ?? $html;

        $html = preg_replace('/<b>\s*<\/b>/i', '', $html) ?? $html;
        $html = preg_replace('/ {2,}/', ' ', $html) ?? $html;
        $html = preg_replace('/^(?:\s*<br\s*\/?>)+\s*/i', '', $html) ?? $html;
        $html = preg_replace('/\s*(?:<br\s*\/?>)+\s*$/i', '', $html) ?? $html;

        return [
            'html' => $html,
            'tags' => array_values(array_unique($tags))
        ];
    }

    /**
     * @return array{type: string, element: \Dom\Element}|null
     */
    private function detectNotSupported(\Dom\Element $message): ?array
    {
        $videoPlayer = $message->querySelector(self::SELECTORS['VIDEO_PLAYER_LINK_NS']);
        if ($videoPlayer === null) {
            $videoPlayer = $message->querySelector(self::SELECTORS['VIDEO_PLAYER_DIV_NS']);
        }

        if ($videoPlayer !== null) {
            return ['type' => self::UNSUPPORTED_TYPE_VIDEO, 'element' => $videoPlayer];
        }

        if ($message->querySelector(self::SELECTORS['SUPPORTED_CONT']) !== null) {
            return null;
        }

        if ($message->querySelector('video') !== null) {
            return null;
        }

        if ($message->querySelector(self::SELECTORS['PHOTO_LINK']) !== null) {
            return null;
        }

        $notSupportedWrap = $message->querySelector(self::SELECTORS['UNSUPPORTED_WRAP']);
        if ($notSupportedWrap !== null) {
            return ['type' => self::UNSUPPORTED_TYPE_GENERIC, 'element' => $notSupportedWrap];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $item
     * @param array{type: string, element: \Dom\Element} $info
     */
    private function applyNotSupportedStub(
        array &$item,
        \Dom\Element $message,
        array $info,
        bool $hasContent
    ): void {
        $type = $info['type'];

        $isTooBig = $this->getUnsupportedReason($message) === self::UNSUPPORTED_REASON_TOO_BIG;
        $mediaLabel = $isTooBig === true ? 'Media is too big' : 'Unsupported media';

        $stubLabel = match ($type) {
            self::UNSUPPORTED_TYPE_VIDEO => $mediaLabel,
            self::UNSUPPORTED_TYPE_GENERIC => 'Please open Telegram to view this post',
            default => 'Unsupported content',
        };

        $title = match ($type) {
            self::UNSUPPORTED_TYPE_VIDEO => 'Unsupported media',
            self::UNSUPPORTED_TYPE_GENERIC => 'Unsupported content',
            default => 'Unsupported content',
        };

        if ($hasContent === false) {
            $item['title'] = $title;
            $item['content'] = $this->renderUnsupported($item['uri'] ?? '#', $stubLabel);
        } else {
            $stub = $this->renderUnsupported($item['uri'] ?? '#', $stubLabel);
            if (preg_match('/(<br\s*\/?>\s*){2,}\s*<\/div>\s*$/i', $item['content']) === 1) {
                $item['content'] = preg_replace('/\s*<\/div>\s*$/', $stub . '</div>', $item['content']) ?? $item['content'];
            } elseif (preg_match('/<br\s*\/?>\s*<\/div>\s*$/i', $item['content']) === 1) {
                $item['content'] = preg_replace('/\s*<\/div>\s*$/', '<br />' . $stub . '</div>', $item['content']) ?? $item['content'];
            } else {
                $item['content'] = preg_replace('/\s*<\/div>\s*$/', '<br /><br />' . $stub . '</div>', $item['content']) ?? $item['content'];
            }
        }
    }

    private function getUnsupportedReason(\Dom\Element $message): string
    {
        $label = $message->querySelector(self::SELECTORS['UNSUPPORTED_LABEL']);
        $text = $label !== null ? trim($label->textContent ?? '') : '';

        if (str_contains($text, 'too big') === true || str_contains($text, 'too large') === true) {
            return self::UNSUPPORTED_REASON_TOO_BIG;
        }

        return self::UNSUPPORTED_REASON_DEFAULT;
    }

    private function renderUnsupported(
        string $uri,
        string $label = 'Please open Telegram to view this post'
    ): string {
        return sprintf(
            '<blockquote style="%s"><div style="%s">%s</div><a href="%s" style="%s"><b>View in Telegram</b></a></blockquote>',
            self::CSS['unsup_wrap'],
            self::CSS['unsup_label'],
            $label,
            $uri,
            self::CSS['unsup_btn']
        );
    }

    private function removeViewInTelegram(string $html): string
    {
        $html = preg_replace('/<a[^>]*>\s*<\/a>/', '', $html) ?? $html;
        $html = preg_replace('/(<br\s*\/?>){3,}/i', '<br /><br />', $html) ?? $html;

        return trim($html);
    }

    private function normalizeText(string $html): string
    {
        $html = preg_replace('/<tg-emoji[^>]*>(.*?)<\/tg-emoji>/is', '$1', $html) ?? $html;
        $html = preg_replace('/<i\s[^>]*class="emoji"[^>]*>(.*?)<\/i>/is', '$1', $html) ?? $html;

        $html = preg_replace('/[\x{00A0}\x{1680}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]/u', ' ', $html) ?? $html;
        $html = strip_invisible_unicode($html);

        if (class_exists(\Normalizer::class) === true) {
            $normalized = \Normalizer::normalize($html, \Normalizer::FORM_KD);
            if ($normalized !== false) {
                $html = $normalized;
            }
        }

        $html = preg_replace_callback(
            '/href\s*=\s*["\'](https?:\/\/[^"\']+)["\']/i',
            function (array $m): string {
                $url = preg_replace('/[?&](utm_\w+|fbclid|gclid|yclid|dclid|tg_rhash)=[^&]*/', '', $m[1]) ?? $m[1];
                $url = preg_replace('/\?$/', '', $url) ?? $url;

                return sprintf('href="%s"', $url);
            },
            $html
        ) ?? $html;

        return preg_replace('/ {2,}/', ' ', $html) ?? $html;
    }

    private function sanitizeContent(string $html): string
    {
        $html = preg_replace('/\s+on\w+\s*=\s*"[^"]*"/i', '', $html) ?? $html;
        $html = preg_replace('/\s+on\w+\s*=\s*\'[^\']*\'/i', '', $html) ?? $html;
        $html = preg_replace('/\s+on\w+\s*=\s*[^\s>]+/i', '', $html) ?? $html;

        $html = preg_replace_callback(
            '/(href|src)\s*=\s*["\']([^"\']*)["\']/i',
            function (array $m): string {
                $url = $m[2];

                if (preg_match('/^\s*(javascript|vbscript|data(?!:(?:image|video|audio)\/))/i', $url) === 1) {
                    return sprintf('%s="#"', $m[1]);
                }

                if (str_starts_with($url, '?') === true || str_starts_with($url, '/') === true) {
                    return sprintf('%s="%s/s/%s%s"', $m[1], self::URI, $this->getNormalizedUsername(), $url);
                }

                return sprintf('%s="%s"', $m[1], $url);
            },
            $html
        ) ?? $html;

        $html = preg_replace('/\s+(class|id|data-[\w-]+)\s*=\s*["\'][^"\']*["\']/i', '', $html) ?? $html;
        $html = preg_replace('/\sexpandable(?=[\s>])/i', '', $html) ?? $html;

        $html = preg_replace_callback(
            '/\s+style\s*=\s*["\']([^"\']*)["\']/i',
            function (array $m): string {
                $val = preg_replace('/expression\s*\(/i', '', $m[1]) ?? $m[1];
                $val = preg_replace('/javascript\s*:/i', '', $val) ?? $val;
                $val = preg_replace('/vbscript\s*:/i', '', $val) ?? $val;
                $val = preg_replace('/behavior\s*:/i', '', $val) ?? $val;
                $val = preg_replace('/@import\b/i', '', $val) ?? $val;
                $val = preg_replace('/url\s*\(\s*["\']?\s*javascript:/i', 'url(', $val) ?? $val;
                $val = trim($val);
                if ($val === '') {
                    return '';
                }
                return sprintf(' style="%s"', htmlspecialchars($val, ENT_QUOTES));
            },
            $html
        ) ?? $html;

        $html = preg_replace('/<\/?tg-spoiler>/i', '', $html) ?? $html;
        $html = strip_tags($html, self::ALLOWED_TAGS);

        $html = preg_replace(
            '/<blockquote(\s[^>]*)?>/i',
            sprintf('<blockquote$1 style="%s">', self::CSS['quote']),
            $html
        ) ?? $html;

        $html = preg_replace('/<a[^>]*>\s*<\/a>/', '', $html) ?? $html;
        $html = preg_replace('/(<br\s*\/?>){3,}/i', '<br /><br />', $html) ?? $html;

        return sprintf('<div style="%s">%s</div>', self::CSS['wrapper'], trim($html));
    }

    private function shouldEmbedMedia(): bool
    {
        $modeInput = $this->getInput('embed_media') ?? self::EMBED_MODE_AUTO;
        $useProxy = (bool) $this->getInput('use_proxy');

        if ($modeInput === self::EMBED_MODE_ALWAYS) {
            return true;
        }
        if ($modeInput === self::EMBED_MODE_NEVER) {
            return false;
        }
        return $useProxy;
    }

    private function embedMediaInHtml(string $html): string
    {
        if ($this->shouldEmbedMedia() === false) {
            return $html;
        }

        $re = '/(src|poster)\s*=\s*["\'](https?:\/\/'
            . self::TG_HOSTS
            . '\/[^"\'\s>]+)["\']/i';

        $result = preg_replace_callback($re, function (array $m): string {
            return sprintf('%s="%s"', $m[1], $this->urlToDataUri($m[2]));
        }, $html);

        return $result ?? $html;
    }

    private function urlToDataUri(string $url): string
    {
        $embedMaxSize = $this->getOption('embed_max_size');
        if ($embedMaxSize === null || $embedMaxSize === '') {
            $embedMaxSize = '10m';
        }
        $maxSize = \media_embed_parse_size($embedMaxSize);

        $useProxy = (bool) $this->getInput('use_proxy');

        return \media_embed_url_to_data_uri(
            url: $url,
            cache: $this->cache,
            cacheTtl: $this->getCacheTimeout(),
            maxSize: $maxSize,
            proxyProfile: $useProxy === true ? self::PROXY_PROFILE : null,
            logger: $this->logger,
            retries: 1
        );
    }

    /**
     * @param array<string, mixed> $item
     */
    private function isBlocked(array $item, \Dom\Element $message): bool
    {
        if ($this->isAd($message) === true) {
            return true;
        }

        if (
            $this->getInput('hide_external_links') === true
            && $this->hasExternalTelegramLinks($item) === true
        ) {
            return true;
        }

        $haystack = $this->buildSearchHaystack($item);

        $exclude = trim($this->getInput('exclude_keywords') ?? '');
        if ($exclude !== '' && matches_keyword_rules($haystack, $exclude) === true) {
            return true;
        }

        $include = trim($this->getInput('include_keywords') ?? '');
        if ($include !== '' && matches_keyword_rules($haystack, $include) === false) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function buildSearchHaystack(array $item): string
    {
        return mb_strtolower(
            trim(($item['title'] ?? '') . ' ' . strip_tags($item['content'] ?? '')),
            'UTF-8'
        );
    }

    private function isAd(\Dom\Element $message): bool
    {
        return array_any(
            iterator_to_array($message->querySelectorAll(self::SELECTORS['ANY_WITH_CLASS'])),
            fn($el) => str_contains($el->getAttribute('class') ?? '', 'sponsored')
        );
    }

    /**
     * @param array<string, mixed> $item
     */
    private function isShortPost(array $item): bool
    {
        $plain = trim(($item['title'] ?? '') . ' ' . strip_tags($item['content'] ?? ''));
        return mb_strlen($plain, 'UTF-8') <= self::SHORT_POST_MAX_LENGTH;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function hasExternalTelegramLinks(array $item): bool
    {
        $currentUsername = strtolower($this->getNormalizedUsername());
        $content = ($item['content'] ?? '') . ' ' . ($item['title'] ?? '');

        $urlRe = '/(?:https?:\/\/)?(?:t\.me|telegram\.me)\/(?:s\/)?([a-zA-Z0-9_]{5,32})(?:\/\d+)?/i';

        if (preg_match_all($urlRe, $content, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $m) {
                $username = strtolower($m[1]);
                if (
                    $username !== $currentUsername
                    && in_array($username, self::TELEGRAM_SPECIAL_PAGES, true) === false
                ) {
                    return true;
                }
            }
        }

        $mentionRe = '/@([a-zA-Z0-9_]{5,32})\b/';

        if (preg_match_all($mentionRe, $content, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $m) {
                if (strtolower($m[1]) !== $currentUsername) {
                    return true;
                }
            }
        }

        return false;
    }

    private function extractChannelIcon(\Dom\HTMLDocument $dom): string
    {
        $meta = array_find(
            iterator_to_array($dom->querySelectorAll(self::SELECTORS['META_TAGS'])),
            fn($m) => $m->getAttribute('property') === 'og:image'
        );
        if ($meta !== null) {
            $content = trim($meta->getAttribute('content') ?? '');
            if ($content !== '') {
                return $content;
            }
        }

        $el = $dom->querySelector(self::SELECTORS['PAGE_PHOTO_IMG']);
        if ($el !== null) {
            $src = trim($el->getAttribute('src') ?? '');
            if ($src !== '') {
                return $src;
            }
        }

        return '';
    }

    private function extractForwardedAuthor(\Dom\Element $fwd): string
    {
        $author = $fwd->querySelector(self::SELECTORS['FORWARDED_AUTHOR']);
        if ($author !== null) {
            $text = trim($author->textContent ?? '');
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private function getPlaintext(\Dom\Element $element, string $selector): string
    {
        $el = $element->querySelector($selector);
        if ($el !== null) {
            return trim($el->textContent ?? '');
        }
        return '';
    }
}
