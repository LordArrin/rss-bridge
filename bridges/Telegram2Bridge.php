<?php

declare(strict_types=1);

class Telegram2Bridge extends BridgeAbstract
{
    const NAME = 'Telegram2';
    const URI = 'https://t.me';
    const DESCRIPTION = 'Returns the recent publications from a public Telegram channel. Supports embedded media content, hides ads and unsupported content.';
    const MAINTAINER = 'LordArrin';
    const CACHE_TIMEOUT = 3600;

    private const PROXY_PROFILE = 'tgws';

    const PARAMETERS = [[
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

    const CONFIGURATION = [
        'embed_max_size' => [
            'required' => false,
            'defaultValue' => '10m',
        ],
    ];

    private const BG_IMG_RE = "/background-image:url\('(.*)'\)/";
    private const TG_HOSTS = '(?:[\w-]+\.)*(?:telegram\.org|t\.me|telesco\.pe)';

    private const MAX_PAGES = 100;
    private const PROXY_RETRIES = 3;
    private const PAGE_DELAY_US = 500000;
    private const RETRY_BACKOFF_US = 1000000;

    private const MAX_TITLE_LENGTH = 60;
    private const MIN_TITLE_SPACE_POS = 30;
    private const MIN_REMAINDER_LENGTH = 12;
    private const SHORT_POST_MAX_LENGTH = 100;

    private const ALLOWED_TAGS = '<div><a><p><br><hr><b><i><u><s><strong><em><code><pre><blockquote><span><img><video><source><ul><ol><li>';

    private const CSS = [
        'unsup_wrap'  => 'background:#17212b;border-radius:12px;padding:28px 16px;text-align:center',
        'unsup_label' => 'color:#708499;font-size:14px;margin-bottom:16px',
        'unsup_btn'   => <<<'CSS'
display:inline-block;background:#2b5278;color:#6ab2f2;text-decoration:none;text-transform:uppercase;font-weight:bold;font-size:13px;letter-spacing:0.03em;padding:10px 24px;border-radius:8px
CSS,
        'video'       => 'max-width:100%',
        'wrapper'     => 'font-size:14px;line-height:1.6;word-wrap:break-word',
        'quote'       => 'border-left:4px solid #4a76a8;padding-left:12px;margin:8px 0',
        'poll'        => 'background:#f9f9f9;padding:15px;margin:10px 0;border-left:4px solid #4a76a8',
        'poll_t'      => 'margin:0 0 10px 0;font-weight:bold',
        'poll_o'      => 'margin:8px 0',
        'poll_f'      => 'margin:10px 0 0 0;color:#888;font-size:0.85em',
    ];

    private string $feedName = '';
    private string $feedIcon = '';
    private array $mediaCache = [];
    
    private ?string $cachedNormalizedUsername = null;

    public string $normalizedUsername {
        get {
            if ($this->cachedNormalizedUsername === null) {
                $this->cachedNormalizedUsername = ltrim(trim((string)($this->getInput('username') ?? '')), '@');
            }
            return $this->cachedNormalizedUsername;
        }
    }

    public function collectData(): void
    {
        $url = 'https://t.me/s/' . $this->normalizedUsername;

        $limitInput = $this->getInput('limit');
        if ($limitInput === null || $limitInput === '' || $limitInput === 0) {
            $limitInput = 10;
        }
        $limit = max(1, (int) $limitInput);

        $pages = 0;
        $done = false;
        $seen = [];

        while ($pages < self::MAX_PAGES && $done === false) {
            $pages++;

            if ($pages > 1) {
                usleep(self::PAGE_DELAY_US);
            }

            $dom = $this->fetchPage($url);
            if ($dom === null) {
                break;
            }

            if ($this->feedName === '') {
                $el = $dom->querySelector('div.tgme_channel_info_header_title span');
                $this->feedName = htmlspecialchars_decode(
                    $el?->textContent ?? '',
                    ENT_QUOTES
                );
            }

            if ($this->feedIcon === '' && $this->getInput('use_proxy') === false) {
                $this->feedIcon = $this->extractChannelIcon($dom);
            }

            $messages = $dom->querySelectorAll('div.tgme_widget_message_wrap.js-widget_message_wrap');
            if ($this->feedName === '' && count($messages) === 0) {
                throwClientException('Unable to find channel. The channel is non-existing or non-public.');
            }

            foreach (array_reverse(iterator_to_array($messages)) as $message) {
                if (count($this->items) >= $limit) {
                    $done = true;
                    break;
                }

                if ($this->isAd($message) === true) {
                    continue;
                }

                $item = $this->parseMessage($message);
                $notSupported = $this->detectNotSupported($message);
                $hasContent = trim(strip_tags($item['content'])) !== ''
                    || trim($item['title']) !== '';

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

            if ($done === true) {
                break;
            }

            $more = $dom->querySelector('div.tgme_widget_message_centered.js-messages_more_wrap a');
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
            return self::URI . '/s/' . $this->normalizedUsername;
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

    public function detectParameters($url): ?array
    {
        $re = '/^https?:\/\/(?:(?:t|telegram)\.me\/(?:s\/)?([\w]+)|([\w]+)\.t\.me\/?)$/';

        if (preg_match($re, (string)$url, $m) === 1) {
            $username = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
            if ($username !== '') {
                return ['username' => $username];
            }
        }

        return null;
    }

    private function fetchPage(string $url): ?\Dom\HTMLDocument
    {
        $useProxy = (bool) $this->getInput('use_proxy');

        if ($useProxy === true) {
            for ($i = 0; $i < self::PROXY_RETRIES; $i++) {
                try {
                    $dom = getProtectedSimpleHTMLDOM($url, self::PROXY_PROFILE);
                    $html = (string)$dom;
                    return \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
                } catch (\Throwable $e) {
                    $this->logger->warning(sprintf(
                        'TgWSProxy page fetch failed (attempt %d/%d): %s Ч %s',
                        $i + 1,
                        self::PROXY_RETRIES,
                        $url,
                        $e->getMessage()
                    ));

                    if ($i < self::PROXY_RETRIES - 1) {
                        usleep(($i + 1) * self::RETRY_BACKOFF_US);
                    }
                }
            }

            $this->logger->warning(sprintf(
                'TgWSProxy exhausted for %s, falling back to direct HTTP',
                $url
            ));
        }

        return $this->fetchPageDirect($url);
    }

    private function fetchPageDirect(string $url): ?\Dom\HTMLDocument
    {
        for ($i = 0; $i < self::PROXY_RETRIES; $i++) {
            try {
                $dom = getSimpleHTMLDOM($url);
                $html = (string)$dom;
                return \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
            } catch (\Exception $e) {
                $this->logger->warning(sprintf(
                    'Direct page fetch failed (attempt %d/%d): %s Ч %s',
                    $i + 1,
                    self::PROXY_RETRIES,
                    $url,
                    $e->getMessage()
                ));

                if ($i < self::PROXY_RETRIES - 1) {
                    usleep(($i + 1) * self::RETRY_BACKOFF_US);
                }
            }
        }

        return null;
    }

    private function parseMessage(\Dom\Element $message): array
    {
        $context = new ParseContext();
        
        $uri = $this->extractUri($message);
        $contentResult = $this->processContent($message, $context);
        
        $item = [];
        $item['uri'] = $uri;
        $item['content'] = $contentResult->html;
        $item['title'] = $contentResult->title;

        if ($contentResult->author !== '' && $contentResult->author !== $this->feedName) {
            $item['author'] = $contentResult->author;
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

        if ($this->getInput('hide_hashtags') === false && $contentResult->hashtags !== []) {
            $item['categories'] = $contentResult->hashtags;
        }

        return $item;
    }

    private function extractUri(\Dom\Element $message): string
    {
        $el = $message->querySelector('a.tgme_widget_message_date');
        return $el?->getAttribute('href') ?? '';
    }

    private function extractTimestamp(\Dom\Element $message): ?string
    {
        $el = $message->querySelector('span.tgme_widget_message_meta time');
        if ($el === null) {
            return null;
        }
        $dt = $el->getAttribute('datetime');
        return $dt !== '' ? $dt : null;
    }

    private function processContent(\Dom\Element $messageDiv, ParseContext $context): ContentResult
    {
        foreach ($messageDiv->querySelectorAll('div.media_not_supported_cont') as $fake) {
            $fake->outerHTML = '';
        }

        $html = '';

        $fwd = $messageDiv->querySelector('div.tgme_widget_message_forwarded_from');
        if ($fwd !== null) {
            $context->author = $this->extractForwardedAuthor($fwd);
        }

        $reply = $messageDiv->querySelector('a.tgme_widget_message_reply');
        if ($reply !== null) {
            $html .= $this->processReply($reply);
        }

        $inner = $messageDiv->innerHTML;

        $textPieces = [];

        $textDiv = $messageDiv->querySelector('div.tgme_widget_message_text.js-message_text');
        if ($textDiv !== null) {
            $outer = $textDiv->outerHTML;
            $pos = strpos($inner, $outer);
            $textPieces[] = [$pos !== false ? $pos : PHP_INT_MAX, 'processText', $textDiv, $context];
        }

        $mediaPieces = [];

        $mediaMarkers = [
            'tgme_widget_message_sticker_wrap'  => 'processSticker',
            'tgme_widget_message_poll'          => 'processPoll',
            'tgme_widget_message_photo_wrap'    => 'processPhoto',
            'tgme_widget_message_document'      => 'processAttachment',
            'tgme_widget_message_link_preview'  => 'processLinkPreview',
            'tgme_widget_message_location_wrap' => 'processLocation',
        ];

        foreach ($mediaMarkers as $marker => $method) {
            $el = $messageDiv->querySelector('div.' . $marker);
            if ($el === null) {
                $el = $messageDiv->querySelector('a.' . $marker);
            }
            if ($el !== null) {
                $outer = $el->outerHTML;
                $pos = strpos($inner, $outer);
                $mediaPieces[] = [$pos !== false ? $pos : PHP_INT_MAX, $method, $messageDiv, $context];
            }
        }

        $videoNotSupported = $messageDiv->querySelector('a.tgme_widget_message_video_player.not_supported');
        if ($videoNotSupported === null) {
            $videoNotSupported = $messageDiv->querySelector('div.tgme_widget_message_video_player.not_supported');
        }
        if ($videoNotSupported === null && $messageDiv->querySelector('video') !== null) {
            $pos = strpos($inner, '<video');
            if ($pos !== false) {
                $mediaPieces[] = [$pos, 'processVideo', $messageDiv, $context];
            }
        }

        usort($textPieces, fn($a, $b) => $a[0] <=> $b[0]);
        usort($mediaPieces, fn($a, $b) => $a[0] <=> $b[0]);

        foreach (array_merge($textPieces, $mediaPieces) as $piece) {
            $method = $piece[1];
            $element = $piece[2];
            $ctx = $piece[3];
            
            $partHtml = $this->{$method}($element, $ctx);

            if ($partHtml === '') {
                continue;
            }

            if ($html !== '') {
                $html .= '<br /><br />';
            }
            $html .= $partHtml;
        }

        return new ContentResult(
            html: $html,
            title: $context->title,
            author: $context->author,
            hashtags: $context->hashtags,
        );
    }

    private function processText(\Dom\Element $textDiv, ParseContext $context): string
    {
        $nested = $textDiv->querySelector('div.tgme_widget_message_text.js-message_text');
        if ($nested !== null) {
            $textDiv = $nested;
        }

        $inner = $textDiv->innerHTML;

        $context->hashtags = $this->extractHashtags($inner);

        $plain = html_entity_decode(
            preg_replace('/\s+/u', ' ', strip_tags(
                preg_replace('/<br\s*\/?>/i', ' ', $inner)
            )),
            ENT_QUOTES | ENT_HTML5
        );

        if (mb_strlen($plain, 'UTF-8') <= self::MAX_TITLE_LENGTH) {
            $context->title = $plain;
            return '';
        }

        $split = $this->splitTitleAndContent($inner);
        $context->title = $split['title'];

        if ($split['html'] === '') {
            return '';
        }

        $dir = $textDiv->getAttribute('dir');
        $attr = $dir !== '' ? ' dir="' . $dir . '"' : '';

        return '<div class="tgme_widget_message_text js-message_text"' . $attr . '>'
            . $split['html'] . '</div>';
    }

    private function splitTitleAndContent(string $html): array
    {
        $html = preg_replace('/^(?:\s*<br\s*\/?>)+\s*/i', '', $html);

        if (preg_match('/<br\s*\/?>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $firstLineHtml = substr($html, 0, $m[0][1]);
            $firstLinePlain = $this->htmlToPlain($firstLineHtml);

            if ($firstLinePlain !== '' && mb_strlen($firstLinePlain) <= self::MAX_TITLE_LENGTH) {
                $restHtml = substr($html, $m[0][1] + strlen($m[0][0]));
                return ['title' => $firstLinePlain, 'html' => trim(preg_replace('/^(?:\s*<br\s*\/?>)+\s*/i', '', $restHtml))];
            }
        }

        $paragraphs = preg_split('/(?:\s*<br\s*\/?>\s*){2,}/i', $html);
        $firstPlain = $this->htmlToPlain($paragraphs[0]);

        if (mb_strlen($firstPlain) <= self::MAX_TITLE_LENGTH) {
            return ['title' => $firstPlain, 'html' => trim(implode('<br /><br />', array_slice($paragraphs, 1)))];
        }

        $prefix = $this->truncateAtWord($firstPlain, self::MAX_TITLE_LENGTH);
        
        $remainder = trim(mb_substr($firstPlain, mb_strlen($prefix)));
        if ($remainder !== '' && !preg_match('/^[\s\p{P}]/u', $remainder) && !preg_match('/[\s\p{P}]$/u', $prefix)) {
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
        return html_entity_decode(
            preg_replace('/\s+/u', ' ', strip_tags(preg_replace('/<br\s*\/?>/i', ' ', $html))),
            ENT_QUOTES | ENT_HTML5
        );
    }

    private function truncateAtWord(string $text, int $length): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }

        $cut = mb_substr($text, 0, $length);
        $sp = mb_strrpos($cut, ' ');

        return ($sp !== false && $sp > self::MIN_TITLE_SPACE_POS)
            ? rtrim(mb_substr($cut, 0, $sp))
            : rtrim($cut);
    }

    private function removeTextPrefix(string $html, string $prefix): string
    {
        $limit = mb_strlen($prefix);
        if ($limit <= 0) {
            return $html;
        }

        $tokens = preg_split('/(<[^>]*>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        $void = ['br', 'img', 'hr', 'input', 'meta', 'link', 'source'];
        
        $consumed = 0;
        $stack = [];
        $out = '';
        $cut = false;

        foreach ($tokens as $token) {
            if ($token === '' || $cut) {
                $out .= $token;
                continue;
            }

            if ($token[0] === '<') {
                if (preg_match('/^<\s*\/\s*(\w+)/u', $token, $m)) {
                    $tag = strtolower($m[1]);
                    $stack = array_values(array_filter($stack, fn($s) => $s['tag'] !== $tag));
                } elseif (preg_match('/^<\s*(\w+)/u', $token, $m)) {
                    $tag = strtolower($m[1]);
                    if (!in_array($tag, $void, true) && !str_ends_with(rtrim($token, '>'), '/')) {
                        $stack[] = ['tag' => $tag, 'html' => $token];
                    }
                }
                continue;
            }

            preg_match_all('/&[a-zA-Z]+;|&#\d+;|&#x[0-9a-fA-F]+;|./us', $token, $m);
            $units = $m[0];

            if ($consumed + count($units) <= $limit) {
                $consumed += count($units);
                continue;
            }

            $cut = true;
            $skip = $limit - $consumed;
            
            $out .= implode('', array_column($stack, 'html')) . implode('', array_slice($units, $skip));
        }

        if (!$cut) {
            return '';
        }

        return '... ' . ltrim(preg_replace('/^(?:\s*<br\s*\/?>)+\s*/i', '', $out));
    }
    
    private function processReply(\Dom\Element $reply): string
    {
        $author = htmlspecialchars(
            $this->getPlaintext($reply, 'span.tgme_widget_message_author_name'),
            ENT_QUOTES
        );
        $text = '';

        $el = $reply->querySelector('div.tgme_widget_message_metatext');
        if ($el !== null) {
            $text = $el->innerHTML;
        }

        $el = $reply->querySelector('div.tgme_widget_message_text');
        if ($el !== null) {
            $text = $el->innerHTML;
        }

        $href = htmlspecialchars($reply->getAttribute('href') ?? '', ENT_QUOTES);

        return '<blockquote>' . $author . '<br />' . $text
            . '<a href="' . $href . '">' . $href . '</a></blockquote><hr />';
    }

    private function processPhoto(\Dom\Element $messageDiv, ParseContext $context): string
    {
        if ($context->title === '') {
            $context->title = '@' . $this->normalizedUsername . ' posted a photo';
        }

        $out = '';
        foreach ($messageDiv->querySelectorAll('a.tgme_widget_message_photo_wrap') as $wrap) {
            $style = $wrap->getAttribute('style') ?? '';
            if ($style !== '' && preg_match(self::BG_IMG_RE, $style, $m) === 1) {
                $out .= '<a href="' . ($wrap->getAttribute('href') ?? '') . '"><img src="' . $m[1] . '" /></a>';
            }
        }

        return $out;
    }

    private function processVideo(\Dom\Element $messageDiv, ParseContext $context): string
    {
        if ($context->title === '') {
            $context->title = '@' . $this->normalizedUsername . ' posted a video';
        }

        $poster = '';
        $thumbs = [
            'i.tgme_widget_message_video_thumb',
            'i.link_preview_video_thumb',
            'i.tgme_widget_message_roundvideo_thumb',
        ];

        foreach ($thumbs as $sel) {
            $el = $messageDiv->querySelector($sel);
            if ($el !== null) {
                $style = $el->getAttribute('style') ?? '';
                if ($style !== '' && preg_match(self::BG_IMG_RE, $style, $m) === 1) {
                    $poster = $m[1];
                    break;
                }
            }
        }

        $player = $messageDiv->querySelector('a.tgme_widget_message_video_player');
        if ($player === null) {
            $player = $messageDiv->querySelector('div.tgme_widget_message_video_player');
        }
        $postHref = '';
        if ($player !== null) {
            $playerHref = $player->getAttribute('href') ?? '';
            if ($playerHref !== '') {
                $postHref = $playerHref;
                if (str_starts_with($postHref, 'http') === false) {
                    $postHref = self::URI . '/' . ltrim($postHref, '/');
                }
            }
        }

        $videoEl = $messageDiv->querySelector('video');
        $src = $videoEl?->getAttribute('src') ?? '';

        if ($poster === '' && $src === '' && $postHref === '') {
            return '';
        }

        $href = $postHref !== '' ? $postHref : '#';

        if ($this->feedName !== '') {
            $channel = htmlspecialchars($this->feedName, ENT_QUOTES);
        } else {
            $channel = '@' . $this->normalizedUsername;
        }

        $duration = $this->getPlaintext($messageDiv, 'time.tgme_widget_message_video_duration');
        if ($duration === '') {
            $duration = $this->getPlaintext($messageDiv, 'span.tgme_widget_message_video_duration');
        }
        if ($duration === '') {
            $duration = $this->getPlaintext($messageDiv, 'time.message_video_duration');
        }
        $duration = htmlspecialchars($duration, ENT_QUOTES);

        $resolution = '';
        if ($player !== null) {
            $playerStyle = $player->getAttribute('style') ?? '';
            if ($playerStyle !== '') {
                if (
                    preg_match('/width:\s*(\d+)px/i', $playerStyle, $mw) === 1
                    && preg_match('/height:\s*(\d+)px/i', $playerStyle, $mh) === 1
                ) {
                    $resolution = $mw[1] . '?' . $mh[1];
                }
            }
        }

        $label = 'Video: ' . $channel;
        if ($duration !== '') {
            $label .= ' (' . $duration . ')';
        }
        if ($resolution !== '') {
            $label .= ' (' . $resolution . ')';
        }

        $html = '';

        if ($poster !== '') {
            $html .= '<a href="' . $href . '"><img src="' . $poster . '" style="'
                . self::CSS['video'] . '" /></a><br />';
        }

        $html .= '<a href="' . $href . '">' . $label . '</a>';

        return $html;
    }

    private function processSticker(\Dom\Element $messageDiv, ParseContext $context): string
    {
        if ($context->title === '') {
            $context->title = '@' . $this->normalizedUsername . ' posted a sticker';
        }

        $div = $messageDiv->querySelector('div.tgme_widget_message_sticker_wrap');
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
            if ($style !== '' && preg_match(self::BG_IMG_RE, $style, $m) === 1) {
                return '<img src="' . $m[1] . '" />';
            }
        }

        return '';
    }

    private function processPoll(\Dom\Element $messageDiv, ParseContext $context): string
    {
        $poll = $messageDiv->querySelector('div.tgme_widget_message_poll');
        if ($poll === null) {
            return '';
        }

        $title = $this->getPlaintext($poll, 'div.tgme_widget_message_poll_question');
        $type = $this->getPlaintext($poll, 'div.tgme_widget_message_poll_type');

        if ($context->title === '') {
            $context->title = $title;
        }

        $html = '<div style="' . self::CSS['poll'] . '">';
        $html .= '<p style="' . self::CSS['poll_t'] . '">'
            . htmlspecialchars($title, ENT_QUOTES) . '</p>';

        foreach ($poll->querySelectorAll('div.tgme_widget_message_poll_option') as $opt) {
            $percent = $this->getPlaintext($opt, 'div.tgme_widget_message_poll_option_percent');
            $text = $this->getPlaintext($opt, 'div.tgme_widget_message_poll_option_text');

            $pct = max(0, min(100, (int) str_replace('%', '', $percent)));
            $filled = (int) round($pct / 5);
            $bar = '[' . str_repeat('#', $filled) . str_repeat('.', 20 - $filled) . ']';

            $html .= '<div style="' . self::CSS['poll_o'] . '">';
            $html .= '<b>' . $pct . '%</b> ' . htmlspecialchars($text, ENT_QUOTES) . '<br />';
            $html .= '<code>' . $bar . '</code>';
            $html .= '</div>';
        }

        $footer = [];

        $voters = htmlspecialchars(
            $this->getPlaintext($messageDiv, 'span.tgme_widget_message_voters'),
            ENT_QUOTES
        );
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
            $html .= '<p style="' . self::CSS['poll_f'] . '">'
                . implode(' &#183; ', $footer) . '</p>';
        }

        $html .= '</div>';

        return $html;
    }

    private function processLinkPreview(\Dom\Element $messageDiv, ParseContext $context): string
    {
        $preview = $messageDiv->querySelector('a.tgme_widget_message_link_preview');
        if ($preview === null || trim($preview->innerHTML) === '') {
            return '';
        }

        $img = '';
        $el = $preview->querySelector('i');
        if ($el !== null) {
            $style = $el->getAttribute('style') ?? '';
            if ($style !== '' && preg_match(self::BG_IMG_RE, $style, $m) === 1) {
                $img = '<img src="' . $m[1] . '" />';
            }
        }

        $title = htmlspecialchars($this->getPlaintext($preview, 'div.link_preview_title'), ENT_QUOTES);
        $site = htmlspecialchars($this->getPlaintext($preview, 'div.link_preview_site_name'), ENT_QUOTES);
        $desc = htmlspecialchars($this->getPlaintext($preview, 'div.link_preview_description'), ENT_QUOTES);
        $previewHref = $preview->getAttribute('href') ?? '';

        return '<blockquote><a href="' . $previewHref . '">' . $img . '</a><br /><a href="'
            . $previewHref . '">' . $title . ' - ' . $site . '</a><br />'
            . $desc . '</blockquote>';
    }

    private function processAttachment(\Dom\Element $messageDiv, ParseContext $context): string
    {
        if ($context->title === '') {
            $context->title = '@' . $this->normalizedUsername . ' posted an attachment';
        }

        $out = 'File attachments:<br />';
        foreach ($messageDiv->querySelectorAll('div.tgme_widget_message_document') as $doc) {
            $docTitle = htmlspecialchars($this->getPlaintext($doc, 'div.tgme_widget_message_document_title'), ENT_QUOTES);
            $docExtra = htmlspecialchars($this->getPlaintext($doc, 'div.tgme_widget_message_document_extra'), ENT_QUOTES);
            $out .= $docTitle . ' - ' . $docExtra . '<br />';
        }

        return $out;
    }

    private function processLocation(\Dom\Element $messageDiv, ParseContext $context): string
    {
        if ($context->title === '') {
            $context->title = '@' . $this->normalizedUsername . ' posted a location';
        }

        $el = $messageDiv->querySelector('div.tgme_widget_message_location');
        $link = $messageDiv->querySelector('a.tgme_widget_message_location_wrap');

        if ($el === null || $link === null) {
            return '';
        }

        $style = $el->getAttribute('style') ?? '';
        $m = [];
        if ($style !== '') {
            preg_match(self::BG_IMG_RE, $style, $m);
        }

        $linkHref = $link->getAttribute('href') ?? '';
        $imgSrc = $m[1] ?? '';

        return '<a href="' . $linkHref . '"><img src="' . $imgSrc . '" /></a>';
    }

    private function extractHashtags(string &$html): array
    {
        $tags = [];

        if (
            preg_match_all(
                '/<a\s[^>]*href="\?q=%23[^"]*"[^>]*>(.*?)<\/a>/is',
                $html,
                $matches,
                PREG_SET_ORDER
            ) > 0
        ) {
            foreach ($matches as $m) {
                $text = trim(strip_tags($m[1]));
                if ($text !== '' && $text[0] === '#') {
                    $tags[] = mb_substr($text, 1, null, 'UTF-8');
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
        );

        $html = preg_replace('/<b>\s*<\/b>/i', '', $html);
        $html = preg_replace('/ {2,}/', ' ', $html);
        $html = preg_replace('/^(?:\s*<br\s*\/?>)+\s*/i', '', $html);
        $html = preg_replace('/\s*(?:<br\s*\/?>)+\s*$/i', '', $html);

        return array_values(array_unique($tags));
    }

    private function detectNotSupported(\Dom\Element $message): ?array
    {
        $videoPlayer = $message->querySelector('a.tgme_widget_message_video_player.not_supported');
        if ($videoPlayer === null) {
            $videoPlayer = $message->querySelector('div.tgme_widget_message_video_player.not_supported');
        }

        if ($videoPlayer !== null) {
            return ['type' => UnsupportedType::VIDEO, 'element' => $videoPlayer];
        }

        if ($message->querySelector('div.media_supported_cont') !== null) {
            return null;
        }

        if ($message->querySelector('video') !== null) {
            return null;
        }

        if ($message->querySelector('a.tgme_widget_message_photo_wrap') !== null) {
            return null;
        }

        $notSupportedWrap = $message->querySelector('div.message_media_not_supported_wrap');
        if ($notSupportedWrap !== null) {
            return ['type' => UnsupportedType::GENERIC, 'element' => $notSupportedWrap];
        }

        return null;
    }

    private function applyNotSupportedStub(
        array &$item,
        \Dom\Element $message,
        array $info,
        bool $hasContent
    ): void {
        $type = $info['type'];
        
        // ѕункт 8: match expression вместо switch
        $stubLabel = match ($type) {
            UnsupportedType::VIDEO => $this->getUnsupportedReason($message) === UnsupportedReason::TOO_BIG
                ? 'Media is too big'
                : 'Unsupported media',
            UnsupportedType::GENERIC => 'Please open Telegram to view this post',
        };

        $title = match ($type) {
            UnsupportedType::VIDEO => 'Unsupported media',
            UnsupportedType::GENERIC => 'Unsupported content',
        };

        if ($hasContent === false) {
            $item['title'] = $title;
            $item['content'] = $this->renderUnsupported($item['uri'] ?? '#', $stubLabel);
        } else {
            $stub = $this->renderUnsupported($item['uri'] ?? '#', $stubLabel);
            if (preg_match('/(<br\s*\/?>\s*){2,}\s*<\/div>\s*$/i', $item['content']) === 1) {
                $item['content'] = preg_replace('/\s*<\/div>\s*$/', $stub . '</div>', $item['content']);
            } elseif (preg_match('/<br\s*\/?>\s*<\/div>\s*$/i', $item['content']) === 1) {
                $item['content'] = preg_replace('/\s*<\/div>\s*$/', '<br />' . $stub . '</div>', $item['content']);
            } else {
                $item['content'] = preg_replace('/\s*<\/div>\s*$/', '<br /><br />' . $stub . '</div>', $item['content']);
            }
        }
    }

    private function getUnsupportedReason(\Dom\Element $message): UnsupportedReason
    {
        $label = $message->querySelector('div.message_media_not_supported_label');
        $text = $label !== null ? trim($label->textContent) : '';

        if (str_contains($text, 'too big') === true || str_contains($text, 'too large') === true) {
            return UnsupportedReason::TOO_BIG;
        }

        return UnsupportedReason::DEFAULT_REASON;
    }

    private function renderUnsupported(
        string $uri,
        string $label = 'Please open Telegram to view this post'
    ): string {
        return '<blockquote style="' . self::CSS['unsup_wrap'] . '"><div style="'
            . self::CSS['unsup_label'] . '">' . $label . '</div><a href="'
            . $uri . '" style="' . self::CSS['unsup_btn']
            . '"><b>View in Telegram</b></a></blockquote>';
    }

    private function removeViewInTelegram(string $html): string
    {
        $html = preg_replace('/<a[^>]*>\s*<\/a>/', '', $html);
        $html = preg_replace('/(<br\s*\/?>){3,}/i', '<br /><br />', $html);

        return trim($html);
    }

    private function normalizeText(string $html): string
    {
        $html = preg_replace('/<tg-emoji[^>]*>(.*?)<\/tg-emoji>/is', '$1', $html);
        $html = preg_replace('/<i\s[^>]*class="emoji"[^>]*>(.*?)<\/i>/is', '$1', $html);

        $html = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00AD}]/u', '', $html);
        $html = preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $html);
        $html = preg_replace(
            '/[\x{00A0}\x{1680}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]/u',
            ' ',
            $html
        );
        $html = preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}]/u', '', $html);

        if (class_exists(\Normalizer::class) === true) {
            $normalized = \Normalizer::normalize($html, \Normalizer::FORM_KD);
            if ($normalized !== false) {
                $html = $normalized;
            }
        }

        $html = preg_replace_callback(
            '/href\s*=\s*["\'](https?:\/\/[^"\']+)["\']/i',
            function (array $m): string {
                $url = preg_replace(
                    '/[?&](utm_\w+|fbclid|gclid|yclid|dclid|tg_rhash)=[^&]*/',
                    '',
                    $m[1]
                );
                $url = preg_replace('/\?$/', '', $url);

                return 'href="' . $url . '"';
            },
            $html
        );

        return preg_replace('/ {2,}/', ' ', $html);
    }

    private function sanitizeContent(string $html): string
    {
        $html = preg_replace('/\s+on\w+\s*=\s*"[^"]*"/i', '', $html);
        $html = preg_replace('/\s+on\w+\s*=\s*\'[^\']*\'/i', '', $html);
        $html = preg_replace('/\s+on\w+\s*=\s*[^\s>]+/i', '', $html);

        $html = preg_replace_callback(
            '/(href|src)\s*=\s*["\']([^"\']*)["\']/i',
            function (array $m): string {
                $url = $m[2];

                if (preg_match('/^\s*(javascript|vbscript|data(?!:(?:image|video|audio)\/))/i', $url) === 1) {
                    return $m[1] . '="#"';
                }

                if (str_starts_with($url, '?') === true || str_starts_with($url, '/') === true) {
                    return $m[1] . '="' . self::URI . '/s/' . $this->normalizedUsername . $url . '"';
                }

                return $m[1] . '="' . $url . '"';
            },
            $html
        );

        $html = preg_replace('/\s+(class|id|data-[\w-]+)\s*=\s*["\'][^"\']*["\']/i', '', $html);
        $html = preg_replace('/\sexpandable(?=[\s>])/i', '', $html);

        $html = preg_replace_callback(
            '/\s+style\s*=\s*["\']([^"\']*)["\']/i',
            function (array $m): string {
                $val = $m[1];
                $val = preg_replace('/expression\s*\(/i', '', $val);
                $val = preg_replace('/javascript\s*:/i', '', $val);
                $val = preg_replace('/vbscript\s*:/i', '', $val);
                $val = preg_replace('/behavior\s*:/i', '', $val);
                $val = preg_replace('/@import\b/i', '', $val);
                $val = preg_replace('/url\s*\(\s*["\']?\s*javascript:/i', 'url(', $val);
                $val = trim($val);
                if ($val === '') {
                    return '';
                }
                return ' style="' . htmlspecialchars($val, ENT_QUOTES) . '"';
            },
            $html
        );

        $html = preg_replace('/<\/?tg-spoiler>/i', '', $html);

        $html = strip_tags($html, self::ALLOWED_TAGS);

        $html = preg_replace(
            '/<blockquote(\s[^>]*)?>/i',
            '<blockquote$1 style="' . self::CSS['quote'] . '">',
            $html
        );

        $html = preg_replace('/<a[^>]*>\s*<\/a>/', '', $html);
        $html = preg_replace('/(<br\s*\/?>){3,}/i', '<br /><br />', $html);

        return '<div style="' . self::CSS['wrapper'] . '">' . trim($html) . '</div>';
    }

    private function shouldEmbedMedia(): bool
    {
        $modeInput = $this->getInput('embed_media') ?? 'auto';
        $mode = EmbedMediaMode::tryFrom($modeInput) ?? EmbedMediaMode::AUTO;
        $useProxy = (bool) $this->getInput('use_proxy');
        
        return $mode->shouldEmbed($useProxy);
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
            return $m[1] . '="' . $this->urlToDataUri($m[2]) . '"';
        }, $html);

        return $result ?? $html;
    }

    private function urlToDataUri(string $url): string
    {
        $data = $this->fetchMediaCached($url);
        if ($data === null) {
            return $url;
        }

        $embedMaxSize = $this->getOption('embed_max_size');
        if ($embedMaxSize === null || $embedMaxSize === '') {
            $embedMaxSize = '10m';
        }
        $maxSize = $this->parseSize($embedMaxSize);

        if ($maxSize > 0 && strlen($data['body']) > $maxSize) {
            return $url;
        }

        return 'data:' . $data['type'] . ';base64,' . base64_encode($data['body']);
    }

    private function fetchMediaCached(string $url): ?array
    {
        if (array_key_exists($url, $this->mediaCache) === true) {
            return $this->mediaCache[$url];
        }

        $useProxy = (bool) $this->getInput('use_proxy');

        if ($useProxy === true) {
            try {
                $data = getProtectedBinary($url, self::PROXY_PROFILE);
                if ($data !== null) {
                    $this->mediaCache[$url] = $data;
                    return $data;
                }
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf(
                    'TgWSProxy media fetch failed for %s: %s',
                    $url,
                    $e->getMessage()
                ));
            }
        }

        return $this->fetchMediaDirect($url);
    }

    private function fetchMediaDirect(string $url): ?array
    {
        for ($i = 0; $i < self::PROXY_RETRIES; $i++) {
            try {
                $response = getContents($url, [], [], true);

                $body = $response->getBody();
                $ct = $response->getHeaders()['content-type'][0] ?? 'application/octet-stream';
                $type = trim(explode(';', $ct)[0]);

                if ($body === '' || $body === null) {
                    $this->mediaCache[$url] = null;
                    return null;
                }

                $this->mediaCache[$url] = ['body' => $body, 'type' => $type];
                return $this->mediaCache[$url];
            } catch (\Exception $e) {
                $this->logger->warning(sprintf(
                    'Direct media fetch failed (attempt %d/%d): %s Ч %s',
                    $i + 1,
                    self::PROXY_RETRIES,
                    $url,
                    $e->getMessage()
                ));

                if ($i < self::PROXY_RETRIES - 1) {
                    usleep(($i + 1) * self::RETRY_BACKOFF_US);
                }
            }
        }

        $this->mediaCache[$url] = null;
        return null;
    }

    private function parseSize(string|int|float $value): int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return 0;
        }

        if (preg_match('/^(\d+(?:\.\d+)?)\s*([kmg])?b?$/i', $value, $m) === 1) {
            $mult = ['' => 1, 'k' => 1024, 'm' => 1048576, 'g' => 1073741824];
            $unit = strtolower($m[2] ?? '');

            return (int) round((float) $m[1] * $mult[$unit]);
        }

        return (int) $value;
    }

    private function isBlocked(array $item, \Dom\Element $message): bool
    {
        if ($this->isAd($message) === true) {
            return true;
        }

        $haystack = $this->buildSearchHaystack($item);

        $exclude = trim((string)($this->getInput('exclude_keywords') ?? ''));
        if ($exclude !== '' && $this->matchesKeywordRules($haystack, $exclude) === true) {
            return true;
        }

        $include = trim((string)($this->getInput('include_keywords') ?? ''));
        if ($include !== '' && $this->matchesKeywordRules($haystack, $include) === false) {
            return true;
        }

        return false;
    }

    private function buildSearchHaystack(array $item): string
    {
        return mb_strtolower(
            trim(($item['title'] ?? '') . ' ' . strip_tags($item['content'] ?? '')),
            'UTF-8'
        );
    }

    private function matchesKeywordRules(string $haystack, string $rules): bool
    {
        if ($haystack === '' || $rules === '') {
            return false;
        }

        foreach (explode(',', $rules) as $rule) {
            $rule = trim($rule);
            if ($rule === '') {
                continue;
            }

            if (str_contains($rule, '+') === true) {
                $parts = array_filter(
                    array_map(
                        fn(string $p): string => mb_strtolower(trim($p), 'UTF-8'),
                        explode('+', $rule)
                    ),
                    fn(string $p): bool => $p !== ''
                );

                if ($parts === []) {
                    continue;
                }

                if (array_all($parts, fn($part) => str_contains($haystack, $part))) {
                    return true;
                }
            } elseif (str_contains($haystack, mb_strtolower($rule, 'UTF-8')) === true) {
                return true;
            }
        }

        return false;
    }

    private function isAd(\Dom\Element $message): bool
    {
        return array_any(
            iterator_to_array($message->querySelectorAll('[class]')),
            fn($el) => str_contains($el->getAttribute('class') ?? '', 'sponsored')
        );
    }

    private function isShortPost(array $item): bool
    {
        $plain = trim(($item['title'] ?? '') . ' ' . strip_tags($item['content'] ?? ''));
        return mb_strlen($plain, 'UTF-8') <= self::SHORT_POST_MAX_LENGTH;
    }

    private function extractChannelIcon(\Dom\HTMLDocument $dom): string
    {
        foreach ($dom->querySelectorAll('meta') as $meta) {
            if ($meta->getAttribute('property') === 'og:image') {
                $content = trim($meta->getAttribute('content') ?? '');
                if ($content !== '') {
                    return $content;
                }
            }
        }

        $el = $dom->querySelector('i.tgme_page_photo_image img');
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
        $author = $fwd->querySelector('span.tgme_widget_message_forwarded_from_author');
        if ($author !== null) {
            $text = trim($author->textContent);
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
            return trim($el->textContent);
        }
        return '';
    }
}

final class ParseContext {
    public string $title = '';
    public string $author = '';
    public array $hashtags = [];
}

final readonly class ContentResult {
    public function __construct(
        public string $html,
        public string $title,
        public string $author,
        public array $hashtags,
    ) {}
}

enum EmbedMediaMode: string {
    case AUTO = 'auto';
    case ALWAYS = 'on';
    case NEVER = 'off';
    
    public function shouldEmbed(bool $useProxy): bool {
        return match($this) {
            self::ALWAYS => true,
            self::NEVER => false,
            self::AUTO => $useProxy,
        };
    }
}

enum UnsupportedReason: string {
    case TOO_BIG = 'too_big';
    case DEFAULT_REASON = 'default';
}

enum UnsupportedType: string {
    case VIDEO = 'video';
    case GENERIC = 'generic';
}