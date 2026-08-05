<?php

declare(strict_types=1);

enum UnsupportedReason: string
{
    case TooBig = 'too_big';
    case Default = 'default';
    case Generic = 'generic';
    
    public function label(): string
    {
        return match($this) {
            self::TooBig => 'Media is too big',
            self::Default, self::Generic => 'Please open Telegram to view this post',
        };
    }
}

enum EmbedMode: string
{
    case Auto = 'auto';
    case On = 'on';
    case Off = 'off';
}

final class ChannelNotFoundException extends \RuntimeException
{
    public function __construct(string $username)
    {
        parent::__construct("Unable to find channel '{$username}'. The channel is non-existing or non-public.");
    }
}

final class PageFetchException extends \RuntimeException
{
    public function __construct(string $url, ?\Throwable $previous = null)
    {
        parent::__construct("Failed to fetch page: {$url}", 0, $previous);
    }
}

final class NormalizedUsername
{
    public function __construct(
        public string $value {
            get => ltrim(trim($this->value), '@');
        }
    ) {}
}

final class LazyMediaFetcher
{
    private ?array $data = null;
    
    public function __construct(
        private readonly string $url,
        private readonly bool $useProxy,
        private readonly string $proxyProfile,
    ) {}
    
    public function get(): ?array
    {
        return $this->data ??= $this->fetch();
    }
    
    private function fetch(): ?array
    {
        if ($this->useProxy) {
            try {
                $data = getProtectedBinary($this->url, $this->proxyProfile);
                if ($data) return $data;
            } catch (\Throwable) { }
        }
        
        for ($i = 0; $i < 3; $i++) {
            try {
                $response = getContents($this->url, [], [], true);
                $body = $response->getBody();
                if (!$body) return null;
                $ct = $response->getHeaders()['content-type'][0] ?? 'application/octet-stream';
                return ['body' => $body, 'type' => trim(explode(';', $ct)[0])];
            } catch (\Exception $e) {
                if ($i < 2) usleep(($i + 1) * 1000000);
            }
        }
        return null;
    }
}

final readonly class PostData
{
    public function __construct(
        public string $uri,
        public int $timestamp,
        public string $author,
        public string $title,
        public string $contentHtml,
        public array $categories,
        public bool $isUnsupported,
        public ?UnsupportedReason $unsupportedReason,
        public bool $hasText,
    ) {}
    
    public function isEmpty(): bool
    {
        return trim(strip_tags($this->contentHtml)) === '' && trim($this->title) === '';
    }
    
    public function searchHaystack(): string
    {
        return mb_strtolower(trim($this->title . ' ' . strip_tags($this->contentHtml)), 'UTF-8');
    }
}

class Telegram2Bridge extends BridgeAbstract
{
    const NAME = 'Telegram2 (Refactored)';
    const URI = 'https://t.me';
    const DESCRIPTION = 'Returns recent publications from a public Telegram channel. Robust parsing, embedded media, ad filtering.';
    const MAINTAINER = 'LordArrin';
    const CACHE_TIMEOUT = 3600;

    private const PROXY_PROFILE = 'tgws';
    private const MAX_PAGES = 100;
    private const PROXY_RETRIES = 3;
    private const PAGE_DELAY_US = 500000;
    private const RETRY_BACKOFF_US = 1000000;

    private const MAX_TITLE_LENGTH = 60;
    private const SHORT_POST_MAX_LENGTH = 100;
    
    private const ALLOWED_TAGS = '<div><a><p><br><hr><b><i><u><s><strong><em><code><pre><blockquote><span><img><video><source><ul><ol><li>';

    const PARAMETERS = [[
        'username' => [
            'name' => 'Channel name', 'type' => 'text', 'required' => true, 'exampleValue' => 'durov',
        ],
        'limit' => [
            'name' => 'Max posts', 'type' => 'number', 'required' => false, 'defaultValue' => 10,
        ],
        'use_proxy' => [
            'name' => 'Use proxy', 'type' => 'checkbox', 'defaultValue' => 'checked',
            'title' => 'Route requests through the TgWSProxy profile',
        ],
        'embed_media' => [
            'name' => 'Embed media', 'type' => 'list',
            'values' => ['Auto (follow proxy)' => 'auto', 'Always embed' => 'on', 'Never embed' => 'off'],
            'defaultValue' => 'auto',
        ],
        'skip_unsupported' => [
            'name' => 'Skip unsupported content', 'type' => 'checkbox', 'defaultValue' => 'checked',
        ],
        'hide_hashtags' => [
            'name' => 'Hide hashtags', 'type' => 'checkbox', 'defaultValue' => 'checked',
        ],
        'include_keywords' => ['name' => 'Include keywords', 'type' => 'text', 'required' => false],
        'exclude_keywords' => ['name' => 'Exclude keywords', 'type' => 'text', 'required' => false],
    ]];

    const CONFIGURATION = [
        'embed_max_size' => ['required' => false, 'defaultValue' => '10m'],
    ];

    private string $feedName = '';
    private string $feedIcon = '';
    private array $mediaCache = [];

    public function collectData(): void
    {
        $username = new NormalizedUsername($this->getInput('username'));
        $url = 'https://t.me/s/' . $username->value;
        $limit = max(1, (int) ($this->getInput('limit') ?: 10));
        $pages = 0;
        $seenUrls = [];
        $emptyPagesInRow = 0;
        $maxEmptyPages = 5;
        
        $startTime = time();
        $maxExecutionTime = 60;

        while ($pages < self::MAX_PAGES && count($this->items) < $limit) {
            if (time() - $startTime > $maxExecutionTime) {
                $this->logger->warning('Telegram2Bridge: Execution timeout reached');
                break;
            }
            
            $pages++;
            if ($pages > 1) usleep(self::PAGE_DELAY_US);

            try {
                $dom = $this->fetchPage($url);
            } catch (PageFetchException $e) {
                $this->logger->warning("Telegram2Bridge: {$e->getMessage()}");
                break;
            }

            $this->extractFeedMetadata($dom);

            $messages = $dom->find('div.tgme_widget_message_wrap.js-widget_message_wrap');
            if (empty($messages) && $this->feedName === '') {
                throw new ChannelNotFoundException($username->value);
            }

            $postsFoundOnThisPage = 0;
            $postsFilteredOnThisPage = 0;

            foreach (array_reverse($messages) as $messageWrap) {
                if (count($this->items) >= $limit) break;

                $message = $messageWrap->find('div.tgme_widget_message', 0);
                if (!$message) continue;

                $postData = $this->parseMessage($message);
                if ($postData === null) continue;

                $postsFoundOnThisPage++;

                if ($this->isBlocked($postData, $message)) {
                    $postsFilteredOnThisPage++;
                    continue;
                }

                $this->items[] = [
                    'uri' => $postData->uri,
                    'timestamp' => $postData->timestamp,
                    'author' => $postData->author,
                    'title' => $postData->title,
                    'content' => $postData->contentHtml,
                    'categories' => $postData->categories,
                ];
            }

            if ($postsFoundOnThisPage > 0) {
                $this->logger->debug(sprintf(
                    'Telegram2Bridge: Page %d — found %d posts, filtered %d, added %d',
                    $pages,
                    $postsFoundOnThisPage,
                    $postsFilteredOnThisPage,
                    $postsFoundOnThisPage - $postsFilteredOnThisPage
                ));
            }

            if ($postsFoundOnThisPage > 0 && $postsFilteredOnThisPage === $postsFoundOnThisPage) {
                $emptyPagesInRow++;
                if ($emptyPagesInRow >= $maxEmptyPages) {
                    $this->logger->info(sprintf(
                        'Telegram2Bridge: Stopping after %d consecutive pages with all posts filtered',
                        $maxEmptyPages
                    ));
                    break;
                }
            } else {
                $emptyPagesInRow = 0;
            }

            if ($postsFoundOnThisPage === 0) {
                break;
            }

            $url = $this->getNextPageUrl($dom);
            if (!$url || isset($seenUrls[$url])) break;
            $seenUrls[$url] = true;
        }
        
        if (empty($this->items)) {
            $this->logger->info('Telegram2Bridge: No posts matched the filter criteria');
        }
    }

    private function parseMessage(\simple_html_dom_node $message): ?PostData
    {
        $contentResult = $this->buildContent($message);
        $unsupported = $this->detectUnsupported($message);
        
        $postData = new PostData(
            uri: $this->extractUri($message),
            timestamp: $this->extractTimestamp($message),
            author: $this->extractAuthor($message),
            title: $contentResult['title'],
            contentHtml: $contentResult['html'],
            categories: $contentResult['hashtags'],
            isUnsupported: $unsupported !== null,
            unsupportedReason: $unsupported,
            hasText: $contentResult['has_text'],
        );
        
        if ($unsupported && !$postData->hasText && $this->getInput('skip_unsupported')) {
            return null;
        }
        
        if ($unsupported) {
            $postData = $this->appendUnsupportedStub($postData);
        }
        
        $finalizedContent = $this->finalizeContent($postData);
        
        $finalTitle = trim($finalizedContent->title) === '' 
            ? $this->generateFallbackTitle($message) 
            : $finalizedContent->title;
        
        $finalPostData = new PostData(
            uri: $finalizedContent->uri,
            timestamp: $finalizedContent->timestamp,
            author: $finalizedContent->author,
            title: $finalTitle,
            contentHtml: $finalizedContent->contentHtml,
            categories: $finalizedContent->categories,
            isUnsupported: $finalizedContent->isUnsupported,
            unsupportedReason: $finalizedContent->unsupportedReason,
            hasText: $finalizedContent->hasText,
        );
        
        return $finalPostData->isEmpty() ? null : $finalPostData;
    }

    private function buildContent(\simple_html_dom_node $message): array
    {
        $htmlParts = [];
        $title = '';
        $hashtags = [];
        $hasText = false;

        if ($reply = $message->find('a.tgme_widget_message_reply', 0)) {
            $htmlParts[] = $this->processReply($reply);
        }

        $textDiv = $message->find('div.tgme_widget_message_text.js-message_text', 0);
        if ($textDiv) {
            $hasText = true;
            $textResult = $this->processText($textDiv);
            $title = $textResult['title'];
            $hashtags = $textResult['hashtags'];
            if ($textResult['html'] !== '') {
                $htmlParts[] = $textResult['html'];
            }
        }

        $mediaSelectors = [
            'a.tgme_widget_message_photo_wrap' => 'processPhoto',
            'div.tgme_widget_message_sticker_wrap' => 'processSticker',
            'a.tgme_widget_message_video_player, div.tgme_widget_message_video_player' => 'processVideo',
            'div.tgme_widget_message_poll' => 'processPoll',
            'a.tgme_widget_message_link_preview' => 'processLinkPreview',
            'div.tgme_widget_message_document' => 'processAttachment',
            'a.tgme_widget_message_location_wrap' => 'processLocation',
        ];

        foreach ($mediaSelectors as $selector => $method) {
            $els = $message->find($selector);
            foreach ($els as $el) {
                if ($el->class && str_contains($el->class, 'not_supported')) continue;
                
                $partHtml = $this->$method($el);
                if ($partHtml !== '') {
                    $htmlParts[] = $partHtml;
                }
            }
        }

        return [
            'html' => implode('<br/><br/>', array_filter($htmlParts)),
            'title' => $title,
            'hashtags' => $hashtags,
            'has_text' => $hasText,
        ];
    }

    private function processText(\simple_html_dom_node $textDiv): array
    {
        $innerHtml = $textDiv->innertext;
        $hashtags = $this->extractHashtags($innerHtml);
        
        $plainText = html_entity_decode(
            preg_replace(
                pattern: '/<br\s*\/?>/i',
                replacement: ' ',
                subject: strip_tags($innerHtml)
            ),
            flags: ENT_QUOTES | ENT_HTML5
        );
        $plainText = preg_replace(pattern: '/\s+/u', replacement: ' ', subject: trim($plainText));

        $title = '';
        $remainingHtml = $innerHtml;

        if (mb_strlen($plainText, 'UTF-8') <= self::MAX_TITLE_LENGTH) {
            $title = $plainText;
            $remainingHtml = '';
        } else {
            $splitResult = $this->splitTitleAndContentSafely($innerHtml, $plainText);
            $title = $splitResult['title'];
            $remainingHtml = $splitResult['html'];
        }

        $dir = $textDiv->getAttribute('dir');
        $attr = $dir ? ' dir="' . htmlspecialchars($dir, ENT_QUOTES) . '"' : '';

        return [
            'title' => $title,
            'html' => $remainingHtml !== '' ? "<div class=\"message_text\"{$attr}>{$remainingHtml}</div>" : '',
            'hashtags' => $hashtags,
        ];
    }

    private function splitTitleAndContentSafely(string $html, string $plainText): array
    {
        if (preg_match('/^(.*?)(?:\s*<br\s*\/?>\s*){2,}/is', $html, $matches, PREG_OFFSET_CAPTURE)) {
            $firstBlockHtml = $matches[1][0];
            $firstBlockPlain = html_entity_decode(strip_tags($firstBlockHtml), ENT_QUOTES | ENT_HTML5);
            $firstBlockPlain = preg_replace(pattern: '/\s+/u', replacement: ' ', subject: trim($firstBlockPlain));

            if (mb_strlen($firstBlockPlain, 'UTF-8') <= self::MAX_TITLE_LENGTH) {
                $restHtml = substr($html, $matches[0][1] + strlen($matches[0][0]));
                return [
                    'title' => $firstBlockPlain,
                    'html' => ltrim($restHtml, " \n\r\t\0\x0B<br/>")
                ];
            }
        }

        $title = $this->mbTruncateAtWord($plainText, self::MAX_TITLE_LENGTH);
        return [
            'title' => $title . '...',
            'html' => $html
        ];
    }

    private function mbTruncateAtWord(string $text, int $length): string
    {
        if (mb_strlen($text, 'UTF-8') <= $length) return $text;
        $cut = mb_substr($text, 0, $length, 'UTF-8');
        $sp = mb_strrpos($cut, ' ', 0, 'UTF-8');
        return $sp !== false ? rtrim(mb_substr($cut, 0, $sp, 'UTF-8')) : rtrim($cut);
    }

    private function extractHashtags(string &$html): array
    {
        $tags = [];
        if (preg_match_all('/<a\s[^>]*href="\?q=%23[^"]*"[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $text = trim(strip_tags($m[1]));
                if ($text !== '' && $text[0] === '#') {
                    $tags[] = mb_substr($text, 1, null, 'UTF-8');
                }
            }
        }

        if ($this->getInput('hide_hashtags')) {
            $html = preg_replace('/<a\s[^>]*href="\?q=%23[^"]*"[^>]*>.*?<\/a>/is', '', $html);
            $html = preg_replace('/<b>\s*<\/b>/i', '', $html);
            $html = preg_replace('/\s{2,}+/', ' ', $html);
        }

        return array_values(array_unique($tags));
    }

    private function processReply(\simple_html_dom_node $reply): string
    {
        $author = htmlspecialchars($this->getPlaintext($reply, 'span.tgme_widget_message_author_name'), ENT_QUOTES);
        $text = $this->getPlaintext($reply, 'div.tgme_widget_message_text') ?: $this->getPlaintext($reply, 'div.tgme_widget_message_metatext');
        $href = htmlspecialchars($reply->href, ENT_QUOTES);

        return "<blockquote><b>{$author}</b><br/>{$text}<br/><a href=\"{$href}\">{$href}</a></blockquote><hr/>";
    }

    private function processPhoto(\simple_html_dom_node $el): string
    {
        if (preg_match("/background-image:url\('(.*)'\)/", $el->style ?? '', $m)) {
            return '<a href="' . $el->href . '"><img src="' . $m[1] . '" style="max-width:100%"/></a>';
        }
        return '';
    }

    private function processVideo(\simple_html_dom_node $el): string
    {
        $poster = '';
        $thumb = $el->find('i.tgme_widget_message_video_thumb, i.link_preview_video_thumb', 0);
        if ($thumb && preg_match("/background-image:url\('(.*)'\)/", $thumb->style ?? '', $m)) {
            $poster = $m[1];
        }

        $href = $el->href ?? '#';
        if (!str_starts_with($href, 'http')) $href = self::URI . '/' . ltrim($href, '/');

        $duration = $this->getPlaintext($el, 'time.tgme_widget_message_video_duration, span.tgme_widget_message_video_duration');
        $label = 'Video' . ($duration ? " ({$duration})" : '');

        $html = $poster ? "<a href=\"{$href}\"><img src=\"{$poster}\" style=\"max-width:100%\"/></a><br/>" : '';
        return $html . "<a href=\"{$href}\">{$label}</a>";
    }

    private function processSticker(\simple_html_dom_node $el): string
    {
        $pic = $el->find('picture', 0);
        if ($pic) {
            $pic->style = '';
            return (string)$el;
        }
        if (preg_match("/background-image:url\('(.*)'\)/", $el->style ?? '', $m)) {
            return '<img src="' . $m[1] . '" />';
        }
        return '';
    }

    private function processPoll(\simple_html_dom_node $el): string
    {
        $title = $this->getPlaintext($el, 'div.tgme_widget_message_poll_question');
        $html = "<div style='background:#f9f9f9;padding:15px;border-left:4px solid #4a76a8'><b>{$title}</b><br/>";
        foreach ($el->find('div.tgme_widget_message_poll_option') as $opt) {
            $pct = $this->getPlaintext($opt, 'div.tgme_widget_message_poll_option_percent');
            $text = $this->getPlaintext($opt, 'div.tgme_widget_message_poll_option_text');
            $html .= "<b>{$pct}</b> {$text}<br/>";
        }
        return $html . '</div>';
    }

    private function processLinkPreview(\simple_html_dom_node $el): string
    {
        $title = $this->getPlaintext($el, 'div.link_preview_title');
        $site = $this->getPlaintext($el, 'div.link_preview_site_name');
        $desc = $this->getPlaintext($el, 'div.link_preview_description');
        $href = $el->href;
        
        $img = '';
        $thumb = $el->find('i', 0);
        if ($thumb && preg_match("/background-image:url\('(.*)'\)/", $thumb->style ?? '', $m)) {
            $img = '<img src="' . $m[1] . '" style="max-width:100%"/><br/>';
        }

        return "<blockquote>{$img}<a href=\"{$href}\"><b>{$title}</b> - {$site}</a><br/>{$desc}</blockquote>";
    }

    private function processAttachment(\simple_html_dom_node $el): string
    {
        $title = $this->getPlaintext($el, 'div.tgme_widget_message_document_title');
        $extra = $this->getPlaintext($el, 'div.tgme_widget_message_document_extra');
        return "?? <b>{$title}</b> ({$extra})";
    }

    private function processLocation(\simple_html_dom_node $el): string
    {
        if (preg_match("/background-image:url\('(.*)'\)/", $el->style ?? '', $m)) {
            return '<a href="' . $el->href . '"><img src="' . $m[1] . '" style="max-width:100%"/></a>';
        }
        return '';
    }

    private function extractUri(\simple_html_dom_node $message): string
    {
        $el = $message->find('a.tgme_widget_message_date', 0);
        return $el ? $el->href : '';
    }

    private function extractTimestamp(\simple_html_dom_node $message): int
    {
        $el = $message->find('span.tgme_widget_message_meta time', 0);
        return $el && $el->datetime ? strtotime($el->datetime) : 0;
    }

    private function extractAuthor(\simple_html_dom_node $message): string
    {
        $fwd = $message->find('div.tgme_widget_message_forwarded_from', 0);
        if ($fwd) {
            $author = $fwd->find('span.tgme_widget_message_forwarded_from_author', 0);
            return $author ? trim($author->plaintext) : '';
        }
        return '';
    }

    private function extractFeedMetadata(\simple_html_dom $dom): void
    {
        if ($this->feedName === '') {
            $el = $dom->find('div.tgme_channel_info_header_title span', 0);
            $this->feedName = htmlspecialchars_decode($el?->plaintext ?? '', ENT_QUOTES);
        }
        if ($this->feedIcon === '' && !$this->getInput('use_proxy')) {
            $meta = $dom->find('meta[property="og:image"]', 0);
            $this->feedIcon = $meta ? trim($meta->content ?? '') : '';
        }
    }

    private function getNextPageUrl(\simple_html_dom $dom): ?string
    {
        $more = $dom->find('> div.tgme_widget_message_centered.js-messages_more_wrap a', 0);
        if ($more && str_contains($more->href, 'before')) {
            return 'https://t.me' . $more->href;
        }
        return null;
    }

    private function generateFallbackTitle(\simple_html_dom_node $message): string
    {
        return match(true) {
            (bool) $message->find('a.tgme_widget_message_photo_wrap', 0) => 'Photo',
            (bool) $message->find('div.tgme_widget_message_video_player', 0) => 'Video',
            (bool) $message->find('div.tgme_widget_message_sticker_wrap', 0) => 'Sticker',
            (bool) $message->find('div.tgme_widget_message_poll', 0) => 'Poll',
            default => 'Attachment',
        };
    }

    private function detectUnsupported(\simple_html_dom_node $message): ?UnsupportedReason
    {
        if ($message->find('a.tgme_widget_message_video_player.not_supported, div.tgme_widget_message_video_player.not_supported', 0)) {
            $label = $message->find('div.message_media_not_supported_label', 0);
            $text = $label ? trim($label->plaintext) : '';
            return (str_contains($text, 'too big') || str_contains($text, 'too large')) 
                ? UnsupportedReason::TooBig 
                : UnsupportedReason::Default;
        }
        if ($message->find('div.message_media_not_supported_wrap', 0)) {
            return UnsupportedReason::Generic;
        }
        return null;
    }

    private function appendUnsupportedStub(PostData $postData): PostData
    {
        $stub = "<blockquote style='background:#17212b;padding:20px;text-align:center;border-radius:12px'>
                    <div style='color:#708499;margin-bottom:10px'>{$postData->unsupportedReason->label()}</div>
                    <a href='{$postData->uri}' style='background:#2b5278;color:#6ab2f2;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:bold'>View in Telegram</a>
                 </blockquote>";
        
        $newContentHtml = $postData->contentHtml !== '' 
            ? $postData->contentHtml . '<br/><br/>' . $stub 
            : $stub;
        
        return new PostData(
            uri: $postData->uri,
            timestamp: $postData->timestamp,
            author: $postData->author,
            title: $postData->title,
            contentHtml: $newContentHtml,
            categories: $postData->categories,
            isUnsupported: $postData->isUnsupported,
            unsupportedReason: $postData->unsupportedReason,
            hasText: $postData->hasText,
        );
    }

    private function finalizeContent(PostData $postData): PostData
    {
        $contentHtml = $postData->contentHtml;
        $contentHtml = $this->removeViewInTelegram($contentHtml);
        $contentHtml = $this->normalizeText($contentHtml);
        $contentHtml = $this->embedMediaInHtml($contentHtml);
        $contentHtml = $this->sanitizeContent($contentHtml);
        
        return new PostData(
            uri: $postData->uri,
            timestamp: $postData->timestamp,
            author: $postData->author,
            title: $postData->title,
            contentHtml: $contentHtml,
            categories: $postData->categories,
            isUnsupported: $postData->isUnsupported,
            unsupportedReason: $postData->unsupportedReason,
            hasText: $postData->hasText,
        );
    }

    private function isBlocked(PostData $postData, \simple_html_dom_node $message): bool
    {
        if ($this->isAd($message)) return true;

        $haystack = $postData->searchHaystack();

        $exclude = trim($this->getInput('exclude_keywords') ?? '');
        if ($exclude !== '' && $this->matchesKeywordRules($haystack, $exclude)) return true;

        $include = trim($this->getInput('include_keywords') ?? '');
        if ($include !== '' && !$this->matchesKeywordRules($haystack, $include)) return true;

        return false;
    }

    private function matchesKeywordRules(string $haystack, string $rules): bool
    {
        foreach (explode(',', $rules) as $rule) {
            $rule = trim($rule);
            if ($rule === '') continue;

            if (str_contains($rule, '+')) {
                $parts = array_filter(
                    array_map(
                        fn(string $p) => mb_strtolower($p, 'UTF-8'),
                        array_map(trim(...), explode('+', $rule))
                    )
                );
                
                $allMatch = true;
                foreach ($parts as $part) {
                    if (!str_contains($haystack, $part)) {
                        $allMatch = false;
                        break;
                    }
                }
                if ($allMatch) return true;
            } elseif (str_contains($haystack, mb_strtolower($rule, 'UTF-8'))) {
                return true;
            }
        }
        return false;
    }

    private function isAd(\simple_html_dom_node $message): bool
    {
        foreach ($message->find('[class]') as $el) {
            if (str_contains($el->class ?? '', 'sponsored')) return true;
        }
        return false;
    }

    private function normalizeText(string $html): string
    {
        $html = preg_replace('/<tg-emoji[^>]*+>(.*?)<\/tg-emoji>/is', '$1', $html);
        $html = preg_replace('/\p{Cf}/u', '', $html);
        $html = preg_replace('/[\x{00A0}\x{1680}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]/u', ' ', $html);
        
        $html = preg_replace_callback(
            pattern: '/href\s*=\s*["\']https?:\/\/[^"\']++["\']/i',
            callback: fn(array $m) => 'href="' . $this->cleanUrl($m[0]) . '"',
            subject: $html
        );

        return preg_replace('/\s{2,}+/S', ' ', $html);
    }

    private function cleanUrl(string $url): string
    {
        $url = preg_replace('/[?&](utm_\w+|fbclid|gclid|yclid|dclid|tg_rhash)=[^&]*/', '', $url);
        return rtrim($url, '?&');
    }

    private function sanitizeContent(string $html): string
    {
        $dom = str_get_html($html);
        if (!$dom) return strip_tags($html, self::ALLOWED_TAGS);

        foreach ($dom->find('*') as $el) {
            foreach ($el->attr as $attrName => $attrVal) {
                if (str_starts_with(strtolower($attrName), 'on') || 
                    preg_match('/^\s*(javascript|vbscript|data(?!:(?:image|video|audio)\/))/i', $attrVal)) {
                    $el->removeAttribute($attrName);
                }
            }

            if ($style = $el->getAttribute('style')) {
                $style = preg_replace('/(expression|javascript|vbscript|behavior|@import)\s*[:\(]/i', '', $style);
                $el->setAttribute('style', trim($style));
                if (trim($style) === '') $el->removeAttribute('style');
            }

            foreach (['href', 'src'] as $linkAttr) {
                $val = $el->getAttribute($linkAttr);
                if ($val && (str_starts_with($val, '/') || str_starts_with($val, '?'))) {
                    $el->setAttribute($linkAttr, self::URI . '/s/' . (new NormalizedUsername($this->getInput('username')))->value . $val);
                }
            }
        }

        $cleanHtml = $dom->save();
        $dom->clear();
        
        $cleanHtml = strip_tags($cleanHtml, self::ALLOWED_TAGS);
        $cleanHtml = preg_replace('/<a[^>]*>\s*<\/a>/', '', $cleanHtml);
        
        return '<div style="font-size:14px;line-height:1.6">' . trim($cleanHtml) . '</div>';
    }

    private function removeViewInTelegram(string $html): string
    {
        return preg_replace('/(<br\s*\/?>){3,}/i', '<br/><br/>', trim($html));
    }

    private function shouldEmbedMedia(): bool
    {
        $mode = EmbedMode::tryFrom($this->getInput('embed_media') ?? 'auto') ?? EmbedMode::Auto;
        
        return match($mode) {
            EmbedMode::On => true,
            EmbedMode::Off => false,
            EmbedMode::Auto => (bool) $this->getInput('use_proxy'),
        };
    }

    private function embedMediaInHtml(string $html): string
    {
        if (!$this->shouldEmbedMedia()) return $html;

        $re = '/(src|poster)\s*=\s*["\'](https?:\/\/(?:[\w-]+\.)*(?:telegram\.org|t\.me|telesco\.pe)\/[^"\'\s>]+)["\']/i';
        return preg_replace_callback($re, function ($m) {
            return $m[1] . '="' . $this->urlToDataUri($m[2]) . '"';
        }, $html) ?? $html;
    }

    private function urlToDataUri(string $url): string
    {
        $fetcher = new LazyMediaFetcher($url, (bool) $this->getInput('use_proxy'), self::PROXY_PROFILE);
        $data = $fetcher->get();
        
        if (!$data) return $url;

        $maxSize = $this->parseSize($this->getOption('embed_max_size') ?: '10m');
        if ($maxSize > 0 && strlen($data['body']) > $maxSize) return $url;

        return 'data:' . $data['type'] . ';base64,' . base64_encode($data['body']);
    }

    private function parseSize(string|int|float $value): int
    {
        $value = trim((string) $value);
        if (preg_match('/^(\d+(?:\.\d+)?)\s*([kmg])?b?$/i', $value, $m)) {
            $mult = ['' => 1, 'k' => 1024, 'm' => 1048576, 'g' => 1073741824];
            return (int) round((float) $m[1] * $mult[strtolower($m[2] ?? '')]);
        }
        return (int) $value;
    }

    private function getPlaintext(\simple_html_dom_node $element, string $selector): string
    {
        $el = $element->find($selector, 0);
        return $el ? trim($el->plaintext) : '';
    }

    private function fetchPage(string $url): \simple_html_dom
    {
        $fetcher = $this->getInput('use_proxy') 
            ? fn(int $i) => getProtectedSimpleHTMLDOM($url, self::PROXY_PROFILE)
            : fn(int $i) => getSimpleHTMLDOM($url);
        
        for ($i = 0; $i < self::PROXY_RETRIES; $i++) {
            try {
                return $fetcher($i);
            } catch (\Throwable $e) {
                if ($i < self::PROXY_RETRIES - 1) {
                    usleep(($i + 1) * self::RETRY_BACKOFF_US);
                } else {
                    throw new PageFetchException($url, $e);
                }
            }
        }
        
        throw new PageFetchException($url);
    }

    public function getURI(): string 
    { 
        return $this->getInput('username') 
            ? self::URI . '/s/' . (new NormalizedUsername($this->getInput('username')))->value
            : parent::getURI(); 
    }
    
    public function getName(): string 
    { 
        return $this->feedName ?: parent::getName(); 
    }
    
    public function getIcon(): string 
    { 
        return $this->feedIcon ?: parent::getIcon(); 
    }

    public function detectParameters($url): ?array
    {
        if (preg_match('/^https?:\/\/(?:(?:t|telegram)\.me\/(?:s\/)?([\w]+)|([\w]+)\.t\.me\/?)$/', $url, $m)) {
            $username = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
            return $username !== '' ? ['username' => $username] : null;
        }
        return null;
    }
}