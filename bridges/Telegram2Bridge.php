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
    private const C = '';

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
            'title' => 'Download media and embed it as data URIs, so clients need no access to Telegram CDN',
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
            'title' => 'Show ONLY posts matching keywords. '
                . self::C
                . 'Syntax is the same as Exclude keywords: comma-separated rules, '
                . self::C
                . '"+" joins words with AND, matching is substring-based and case-insensitive. '
                . self::C
                . 'A post is kept only if it matches at least one rule. '
                . self::C
                . 'When both Include and Exclude are set, '
                . self::C
                . 'a post must first match Include, then survive Exclude.',
        ],
        'exclude_keywords' => [
            'name' => 'Exclude keywords',
            'type' => 'text',
            'required' => false,
            'title' => 'Hide posts matching keywords. '
                . self::C
                . 'Rules are comma-separated, case-insensitive, and matched as substrings '
                . self::C
                . 'against both title and body. '
                . self::C
                . 'A rule without "+" hides any post containing it '
                . self::C
                . '(e.g. "casino" also matches "casinos"). '
                . self::C
                . 'Join words with "+" to require ALL of them '
                . self::C
                . '(e.g. "casino+bonus" hides a post only if both words are present). '
                . self::C
                . 'Multiple rules act as OR: a post is hidden if it matches ANY rule. '
                . self::C
                . 'Example: "casino, bonus+promo, ads" hides posts with "casino", '
                . self::C
                . 'or with both "bonus" and "promo", or with "ads".',
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
    private const SHORT_POST_MAX_LENGTH = 100;

    private const REASON_TOO_BIG = 'too_big';
    private const REASON_DEFAULT = 'default';

    private const ALLOWED_TAGS = '<div><a><p><br><hr><b><i><u><s><strong><em><code><pre><blockquote><span><img><video><source><ul><ol><li>';

    private const CSS = [
        'unsup_wrap'  => 'background:#17212b;border-radius:12px;padding:28px 16px;text-align:center',
        'unsup_label' => 'color:#708499;font-size:14px;margin-bottom:16px',
        'unsup_btn'   => 'display:inline-block;background:#2b5278;color:#6ab2f2;text-decoration:none;'
            . self::C
            . 'text-transform:uppercase;font-weight:bold;font-size:13px;'
            . self::C
            . 'letter-spacing:0.03em;padding:10px 24px;border-radius:8px',
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
    private string $itemTitle = '';
    private string $itemAuthor = '';
    private array $hashtags = [];
    private array $mediaCache = [];

    public function collectData(): void
    {
        $url = 'https://t.me/s/' . $this->normalizeUsername();

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
                $el = $dom->find('div.tgme_channel_info_header_title span', 0);
                $this->feedName = htmlspecialchars_decode(
                    (string)($el?->plaintext ?? ''),
                    ENT_QUOTES
                );
            }

            if ($this->feedIcon === '' && $this->getInput('use_proxy') === false) {
                $this->feedIcon = $this->extractChannelIcon($dom);
            }

            $messages = $dom->find('div.tgme_widget_message_wrap.js-widget_message_wrap');
            if ($this->feedName === '' && $messages === []) {
                throwClientException('Unable to find channel. The channel is non-existing or non-public.');
            }

            foreach (array_reverse($messages) as $message) {
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

            $more = $dom->find('> div.tgme_widget_message_centered.js-messages_more_wrap a', 0);
            if ($more !== null && str_contains((string)($more->href ?? ''), 'before') === true) {
                $next = 'https://t.me' . (string)$more->href;
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
            return self::URI . '/s/' . $this->normalizeUsername();
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

    private function fetchPage(string $url): ?\simple_html_dom
    {
        $useProxy = (bool) $this->getInput('use_proxy');

        if ($useProxy === true) {
            for ($i = 0; $i < self::PROXY_RETRIES; $i++) {
                try {
                    return getProtectedSimpleHTMLDOM($url, self::PROXY_PROFILE);
                } catch (\Throwable $e) {
                    $this->logger->warning(sprintf(
                        'TgWSProxy page fetch failed (attempt %d/%d): %s — %s',
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

    private function fetchPageDirect(string $url): ?\simple_html_dom
    {
        for ($i = 0; $i < self::PROXY_RETRIES; $i++) {
            try {
                return getSimpleHTMLDOM($url);
            } catch (\Exception $e) {
                $this->logger->warning(sprintf(
                    'Direct page fetch failed (attempt %d/%d): %s — %s',
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

    private function parseMessage(\simple_html_dom_node $message): array
    {
        $this->itemTitle = '';
        $this->itemAuthor = '';
        $this->hashtags = [];

        $item = [];

        $el = $message->find('a.tgme_widget_message_date', 0);
        if ($el !== null) {
            $item['uri'] = (string)($el->href ?? '');
        }

        $item['content'] = $this->processContent($message);
        $item['title'] = $this->itemTitle;

        if ($this->itemAuthor !== '' && $this->itemAuthor !== $this->feedName) {
            $item['author'] = $this->itemAuthor;
        }

        $el = $message->find('span.tgme_widget_message_meta time', 0);
        if ($el !== null) {
            $dt = (string)($el->datetime ?? '');
            if ($dt !== '') {
                $item['timestamp'] = $dt;
            }
        }

        $item['content'] = $this->removeViewInTelegram($item['content']);
        $item['content'] = $this->normalizeText($item['content']);

        if ($this->getInput('hide_hashtags') === false && $this->hashtags !== []) {
            $item['categories'] = $this->hashtags;
        }

        $item['content'] = $this->embedMediaInHtml($item['content']);
        $item['content'] = $this->sanitizeContent($item['content']);

        return $item;
    }

    private function processContent(\simple_html_dom_node $messageDiv): string
    {
        foreach ($messageDiv->find('div.media_not_supported_cont') as $fake) {
            $fake->outertext = '';
        }

        $html = '';

        $fwd = $messageDiv->find('div.tgme_widget_message_forwarded_from', 0);
        if ($fwd !== null) {
            $this->itemAuthor = $this->extractForwardedAuthor($fwd);
        }

        $reply = $messageDiv->find('a.tgme_widget_message_reply', 0);
        if ($reply !== null) {
            $html .= $this->processReply($reply);
        }

        $inner = (string)($messageDiv->innertext ?? '');

        $textPieces = [];

        $textDiv = $messageDiv->find('div.tgme_widget_message_text.js-message_text', 0);
        if ($textDiv !== null) {
            $outer = (string)($textDiv->outertext ?? '');
            $pos = strpos($inner, $outer);
            $textPieces[] = [$pos !== false ? $pos : PHP_INT_MAX, 'processText', $textDiv];
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
            $el = $messageDiv->find('div.' . $marker, 0);
            if ($el === null) {
                $el = $messageDiv->find('a.' . $marker, 0);
            }
            if ($el !== null) {
                $outer = (string)($el->outertext ?? '');
                $pos = strpos($inner, $outer);
                $mediaPieces[] = [$pos !== false ? $pos : PHP_INT_MAX, $method, $messageDiv];
            }
        }

        $videoNotSupported = $messageDiv->find('a.tgme_widget_message_video_player.not_supported', 0);
        if ($videoNotSupported === null) {
            $videoNotSupported = $messageDiv->find('div.tgme_widget_message_video_player.not_supported', 0);
        }
        if ($videoNotSupported === null && $messageDiv->find('video', 0) !== null) {
            $pos = strpos($inner, '<video');
            if ($pos !== false) {
                $mediaPieces[] = [$pos, 'processVideo', $messageDiv];
            }
        }

        usort($textPieces, fn($a, $b) => $a[0] <=> $b[0]);
        usort($mediaPieces, fn($a, $b) => $a[0] <=> $b[0]);

        foreach (array_merge($textPieces, $mediaPieces) as $piece) {
            $partHtml = $this->{$piece[1]}($piece[2]);

            if ($partHtml === '') {
                continue;
            }

            if ($html !== '') {
                $html .= '<br /><br />';
            }
            $html .= $partHtml;
        }

        return $html;
    }

    private function processText(\simple_html_dom_node $textDiv): string
    {
        $nested = $textDiv->find('div.tgme_widget_message_text.js-message_text', 0);
        if ($nested !== null) {
            $textDiv = $nested;
        }

        $inner = (string)($textDiv->innertext ?? '');

        $this->hashtags = $this->extractHashtags($inner);

        $plain = html_entity_decode(
            preg_replace('/\s+/u', ' ', strip_tags(
                preg_replace('/<br\s*\/?>/i', ' ', $inner)
            )),
            ENT_QUOTES | ENT_HTML5
        );

        if (mb_strlen($plain, 'UTF-8') <= self::MAX_TITLE_LENGTH) {
            $this->itemTitle = $plain;
            return '';
        }

        $split = $this->splitTitleAndContent($inner);
        $this->itemTitle = $split['title'];

        if ($split['html'] === '') {
            return '';
        }

        $dir = (string)($textDiv->getAttribute('dir') ?? '');
        $attr = $dir !== '' ? ' dir="' . $dir . '"' : '';

        return '<div class="tgme_widget_message_text js-message_text"' . $attr . '>'
            . $split['html'] . '</div>';
    }

    private function splitTitleAndContent(string $html): array
    {
        $plain = html_entity_decode(
            preg_replace('/\s+/u', ' ', strip_tags(
                preg_replace('/<br\s*\/?>/i', ' ', $html)
            )),
            ENT_QUOTES | ENT_HTML5
        );

        $title = '';
        $contentHtml = $html;

        if (mb_strlen($plain, 'UTF-8') <= self::MAX_TITLE_LENGTH) {
            $title = $plain;
            $contentHtml = '';
        } else {
            $cut = mb_substr($plain, 0, self::MAX_TITLE_LENGTH, 'UTF-8');
            $sp = mb_strrpos($cut, ' ', 0, 'UTF-8');
            if ($sp !== false && $sp > self::MIN_TITLE_SPACE_POS) {
                $cut = mb_substr($cut, 0, $sp, 'UTF-8');
            }
            $title = rtrim($cut) . '...';
        }

        return ['title' => $title, 'html' => $contentHtml];
    }

    private function processReply(\simple_html_dom_node $reply): string
    {
        $author = htmlspecialchars(
            $this->getPlaintext($reply, 'span.tgme_widget_message_author_name'),
            ENT_QUOTES
        );
        $text = '';

        $el = $reply->find('div.tgme_widget_message_metatext', 0);
        if ($el !== null) {
            $text = (string)($el->innertext ?? '');
        }

        $el = $reply->find('div.tgme_widget_message_text', 0);
        if ($el !== null) {
            $text = (string)($el->innertext ?? '');
        }

        $href = htmlspecialchars((string)($reply->href ?? ''), ENT_QUOTES);

        return '<blockquote>' . $author . '<br />' . $text
            . '<a href="' . $href . '">' . $href . '</a></blockquote><hr />';
    }

    private function processPhoto(\simple_html_dom_node $messageDiv): string
    {
        if ($this->itemTitle === '') {
            $this->itemTitle = '@' . $this->normalizeUsername() . ' posted a photo';
        }

        $out = '';
        foreach ($messageDiv->find('a.tgme_widget_message_photo_wrap') as $wrap) {
            $style = (string)($wrap->style ?? '');
            if ($style !== '' && preg_match(self::BG_IMG_RE, $style, $m) === 1) {
                $out .= '<a href="' . (string)($wrap->href ?? '') . '"><img src="' . $m[1] . '" /></a>';
            }
        }

        return $out;
    }

    private function processVideo(\simple_html_dom_node $messageDiv): string
    {
        if ($this->itemTitle === '') {
            $this->itemTitle = '@' . $this->normalizeUsername() . ' posted a video';
        }

        $poster = '';
        $thumbs = [
            'i.tgme_widget_message_video_thumb',
            'i.link_preview_video_thumb',
            'i.tgme_widget_message_roundvideo_thumb',
        ];

        foreach ($thumbs as $sel) {
            $el = $messageDiv->find($sel, 0);
            if ($el !== null) {
                $style = (string)($el->style ?? '');
                if ($style !== '' && preg_match(self::BG_IMG_RE, $style, $m) === 1) {
                    $poster = $m[1];
                    break;
                }
            }
        }

        $player = $messageDiv->find('a.tgme_widget_message_video_player', 0);
        if ($player === null) {
            $player = $messageDiv->find('div.tgme_widget_message_video_player', 0);
        }
        $postHref = '';
        if ($player !== null) {
            $playerHref = (string)($player->href ?? '');
            if ($playerHref !== '') {
                $postHref = $playerHref;
                if (str_starts_with($postHref, 'http') === false) {
                    $postHref = self::URI . '/' . ltrim($postHref, '/');
                }
            }
        }

        $videoEl = $messageDiv->find('video', 0);
        $src = (string)($videoEl?->src ?? '');

        if ($poster === '' && $src === '' && $postHref === '') {
            return '';
        }

        $href = $postHref !== '' ? $postHref : '#';

        if ($this->feedName !== '') {
            $channel = htmlspecialchars($this->feedName, ENT_QUOTES);
        } else {
            $channel = '@' . $this->normalizeUsername();
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
            $playerStyle = (string)($player->style ?? '');
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

    private function processSticker(\simple_html_dom_node $messageDiv): string
    {
        if ($this->itemTitle === '') {
            $this->itemTitle = '@' . $this->normalizeUsername() . ' posted a sticker';
        }

        $div = $messageDiv->find('div.tgme_widget_message_sticker_wrap', 0);
        if ($div === null) {
            return '';
        }

        $pic = $div->find('picture', 0);
        if ($pic !== null) {
            $innerDiv = $pic->find('div', 0);
            if ($innerDiv !== null) {
                $innerDiv->style = '';
            }
            $pic->style = '';
            return (string)$div;
        }

        $el = $div->find('i', 0);
        if ($el !== null) {
            $style = (string)($el->style ?? '');
            if ($style !== '' && preg_match(self::BG_IMG_RE, $style, $m) === 1) {
                return '<img src="' . $m[1] . '" />';
            }
        }

        return '';
    }

    private function processPoll(\simple_html_dom_node $messageDiv): string
    {
        $poll = $messageDiv->find('div.tgme_widget_message_poll', 0);
        if ($poll === null) {
            return '';
        }

        $title = $this->getPlaintext($poll, 'div.tgme_widget_message_poll_question');
        $type = $this->getPlaintext($poll, 'div.tgme_widget_message_poll_type');

        if ($this->itemTitle === '') {
            $this->itemTitle = $title;
        }

        $html = '<div style="' . self::CSS['poll'] . '">';
        $html .= '<p style="' . self::CSS['poll_t'] . '">'
            . htmlspecialchars($title, ENT_QUOTES) . '</p>';

        foreach ($poll->find('div.tgme_widget_message_poll_option') as $opt) {
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

    private function processLinkPreview(\simple_html_dom_node $messageDiv): string
    {
        $preview = $messageDiv->find('a.tgme_widget_message_link_preview', 0);
        if ($preview === null || trim((string)($preview->innertext ?? '')) === '') {
            return '';
        }

        $img = '';
        $el = $preview->find('i', 0);
        if ($el !== null) {
            $style = (string)($el->style ?? '');
            if ($style !== '' && preg_match(self::BG_IMG_RE, $style, $m) === 1) {
                $img = '<img src="' . $m[1] . '" />';
            }
        }

        $title = htmlspecialchars($this->getPlaintext($preview, 'div.link_preview_title'), ENT_QUOTES);
        $site = htmlspecialchars($this->getPlaintext($preview, 'div.link_preview_site_name'), ENT_QUOTES);
        $desc = htmlspecialchars($this->getPlaintext($preview, 'div.link_preview_description'), ENT_QUOTES);
        $previewHref = (string)($preview->href ?? '');

        return '<blockquote><a href="' . $previewHref . '">' . $img . '</a><br /><a href="'
            . $previewHref . '">' . $title . ' - ' . $site . '</a><br />'
            . $desc . '</blockquote>';
    }

    private function processAttachment(\simple_html_dom_node $messageDiv): string
    {
        if ($this->itemTitle === '') {
            $this->itemTitle = '@' . $this->normalizeUsername() . ' posted an attachment';
        }

        $out = 'File attachments:<br />';
        foreach ($messageDiv->find('div.tgme_widget_message_document') as $doc) {
            $docTitle = htmlspecialchars($this->getPlaintext($doc, 'div.tgme_widget_message_document_title'), ENT_QUOTES);
            $docExtra = htmlspecialchars($this->getPlaintext($doc, 'div.tgme_widget_message_document_extra'), ENT_QUOTES);
            $out .= $docTitle . ' - ' . $docExtra . '<br />';
        }

        return $out;
    }

    private function processLocation(\simple_html_dom_node $messageDiv): string
    {
        if ($this->itemTitle === '') {
            $this->itemTitle = '@' . $this->normalizeUsername() . ' posted a location';
        }

        $el = $messageDiv->find('div.tgme_widget_message_location', 0);
        $link = $messageDiv->find('a.tgme_widget_message_location_wrap', 0);

        if ($el === null || $link === null) {
            return '';
        }

        $style = (string)($el->style ?? '');
        $m = [];
        if ($style !== '') {
            preg_match(self::BG_IMG_RE, $style, $m);
        }

        $linkHref = (string)($link->href ?? '');
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

    private function detectNotSupported(\simple_html_dom_node $message): ?array
    {
        $videoPlayer = $message->find('a.tgme_widget_message_video_player.not_supported', 0);
        if ($videoPlayer === null) {
            $videoPlayer = $message->find('div.tgme_widget_message_video_player.not_supported', 0);
        }

        if ($videoPlayer !== null) {
            return ['type' => 'video', 'element' => $videoPlayer];
        }

        if ($message->find('div.media_supported_cont', 0) !== null) {
            return null;
        }

        if ($message->find('video', 0) !== null) {
            return null;
        }

        if ($message->find('a.tgme_widget_message_photo_wrap', 0) !== null) {
            return null;
        }

        $notSupportedWrap = $message->find('div.message_media_not_supported_wrap', 0);
        if ($notSupportedWrap !== null) {
            return ['type' => 'generic', 'element' => $notSupportedWrap];
        }

        return null;
    }

    private function applyNotSupportedStub(
        array &$item,
        \simple_html_dom_node $message,
        array $info,
        bool $hasContent
    ): void {
        $stubLabel = '';
        $title = '';

        switch ($info['type']) {
            case 'video':
                $reason = $this->getUnsupportedReason($message);
                if ($reason === self::REASON_TOO_BIG) {
                    $stubLabel = 'Media is too big';
                } else {
                    $stubLabel = 'Unsupported media';
                }
                $title = 'Unsupported media';
                break;

            case 'generic':
            default:
                $stubLabel = 'Please open Telegram to view this post';
                $title = 'Unsupported content';
                break;
        }

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

    private function getUnsupportedReason(\simple_html_dom_node $message): string
    {
        $label = $message->find('div.message_media_not_supported_label', 0);
        $text = $label !== null ? trim((string)($label->plaintext ?? '')) : '';

        if (str_contains($text, 'too big') === true || str_contains($text, 'too large') === true) {
            return self::REASON_TOO_BIG;
        }

        return self::REASON_DEFAULT;
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
                    return $m[1] . '="' . self::URI . '/s/' . $this->normalizeUsername() . $url . '"';
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
        $mode = $this->getInput('embed_media') ?? 'auto';

        if ($mode === 'on') {
            return true;
        }

        if ($mode === 'off') {
            return false;
        }

        return (bool) $this->getInput('use_proxy');
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
                    'Direct media fetch failed (attempt %d/%d): %s — %s',
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

    private function isBlocked(array $item, \simple_html_dom_node $message): bool
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

                $all = true;
                foreach ($parts as $part) {
                    if (str_contains($haystack, $part) === false) {
                        $all = false;
                        break;
                    }
                }

                if ($all === true) {
                    return true;
                }
            } elseif (str_contains($haystack, mb_strtolower($rule, 'UTF-8')) === true) {
                return true;
            }
        }

        return false;
    }

    private function isAd(\simple_html_dom_node $message): bool
    {
        foreach ($message->find('[class]') as $el) {
            $class = (string)($el->class ?? '');
            if (str_contains($class, 'sponsored') === true) {
                return true;
            }
        }

        return false;
    }

    private function isShortPost(array $item): bool
    {
        $plain = trim(($item['title'] ?? '') . ' ' . strip_tags($item['content'] ?? ''));
        return mb_strlen($plain, 'UTF-8') <= self::SHORT_POST_MAX_LENGTH;
    }

    private function normalizeUsername(): string
    {
        return ltrim(trim((string)($this->getInput('username') ?? '')), '@');
    }

    private function extractChannelIcon(\simple_html_dom $dom): string
    {
        foreach ($dom->find('meta') as $meta) {
            if ($meta->getAttribute('property') === 'og:image') {
                $content = trim((string)($meta->content ?? ''));
                if ($content !== '') {
                    return $content;
                }
            }
        }

        $el = $dom->find('i.tgme_page_photo_image img', 0);
        if ($el !== null) {
            $src = trim((string)($el->src ?? ''));
            if ($src !== '') {
                return $src;
            }
        }

        return '';
    }

    private function extractForwardedAuthor(\simple_html_dom_node $fwd): string
    {
        $author = $fwd->find('span.tgme_widget_message_forwarded_from_author', 0);
        if ($author !== null) {
            $text = trim((string)($author->plaintext ?? ''));
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private function getPlaintext(\simple_html_dom_node $element, string $selector): string
    {
        $el = $element->find($selector, 0);
        if ($el !== null) {
            return trim((string)($el->plaintext ?? ''));
        }
        return '';
    }
}
