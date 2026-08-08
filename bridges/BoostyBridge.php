<?php

declare(strict_types=1);

class BoostyBridge extends BridgeAbstract
{
    const NAME = 'Boosty';
    const URI = 'https://boosty.to';
    const DESCRIPTION = 'Parser for Boosty (free posts and paid announcements). No auth required';
    const MAINTAINER = 'LordArrin';
    const CACHE_TIMEOUT = 3600;

    const PARAMETERS = [[
        'blog'     => ['name' => 'Blog', 'type' => 'text', 'required' => true, 'title' => 'Channel name, for example, rebel_jack from https://boosty.to/rebel_jack'],
        'limit'    => ['name' => 'Posts limit', 'type' => 'number', 'defaultValue' => 10],
        'hideTags' => ['name' => 'Hide tags', 'type' => 'checkbox', 'title' => 'Check this box to completely hide the tags list from the post content'],
    ]];

    private string $blogName = '';
    private string $blogDisplayName = '';
    private string $blogAvatar = '';

    private const MEDIA_TYPES = ['image' => true, 'audio_file' => true, 'file' => true, 'ok_video' => true];

    private const CSS = [
        'paywall'  => 'padding:15px;margin:10px 0;border-left:4px solid #e8a33d',
        'pt'       => 'margin:0 0 10px 0;font-weight:bold',
        'pp'       => 'margin:5px 0',
        'poll'     => 'padding:15px;margin:10px 0;border-left:4px solid #4a90d9',
        'poll_t'   => 'margin:0 0 10px 0;font-weight:bold',
        'poll_o'   => 'margin:8px 0',
        'poll_m'   => 'color:#666;font-size:0.9em',
        'poll_f'   => 'margin:10px 0 0 0;color:#888;font-size:0.85em',
        'img'      => 'max-width:100%',
        'ul'       => 'margin:10px 0;padding-left:24px;list-style-type:disc',
        'ol'       => 'margin:10px 0;padding-left:24px;list-style-type:decimal',
        'li'       => 'margin:4px 0',
    ];

    public function collectData(): void
    {
        $blog = $this->getInput('blog');
        $this->blogName = $blog !== null ? (string) $blog : '';
        foreach ($this->fetchPosts() as $p) {
            $item = $this->buildItem($p);
            if ($item !== null) {
                $this->items[] = $item;
            }
        }
    }

    private function fetchPosts(): array
    {
        $limitInput = (int) $this->getInput('limit');
        $limit = min($limitInput !== 0 ? $limitInput : 20, 100);
        $url = 'https://api.boosty.to/v1/blog/' . urlencode($this->blogName) . '/post/?limit=' . $limit;
        $data = Json::decode(getContents($url));

        if (isset($data['data']) === false || is_array($data['data']) === false) {
            throw new Exception('Failed to fetch data from Boosty API');
        }
        if (empty($data['data'][0]['user']['name']) === false) {
            $this->blogDisplayName = (string) $data['data'][0]['user']['name'];
        }
        if (empty($data['data'][0]['user']['avatarUrl']) === false) {
            $this->blogAvatar = (string) $data['data'][0]['user']['avatarUrl'];
        }
        return $data['data'];
    }

    private function buildItem(array $p): ?array
    {
        if (($p['isPublished'] ?? false) === false) {
            return null;
        }
        $paid = $this->isPaid($p);
        $title = $p['title'] ?? '';

        if ($title === '') {
            $blocks = $paid === true ? ($p['teaser'] ?? []) : ($p['data'] ?? []);
            $extractedTitle = $this->extractFirstSentence($blocks);
            if ($extractedTitle !== null) {
                $title = $extractedTitle;
                if ($paid === true) {
                    $p['teaser'] = $this->removeFirstSentenceFromBlocks($p['teaser'] ?? []);
                } else {
                    $p['data'] = $this->removeFirstSentenceFromBlocks($p['data'] ?? []);
                }
            } else {
                $title = 'Untitled';
            }
        }

        $title = ($paid === true ? '[Paid] ' : '') . $title;
        $content = $paid === true ? $this->renderPaywall($p) : $this->renderFree($p);

        return $this->meta($p, $title, $content);
    }

    private function extractFirstSentence(array $blocks): ?string
    {
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') === 'text') {
                $html = $this->draft($block['content'] ?? '');
                $text = trim(strip_tags($html));
                if ($text !== '') {
                    $sentenceEnd = $this->findSentenceEnd($text);
                    if ($sentenceEnd !== false) {
                        return trim(mb_substr($text, 0, $sentenceEnd, 'UTF-8'));
                    }
                    return $text;
                }
            }
        }
        return null;
    }

    private function findSentenceEnd(string $text): int|false
    {
        $punctuation = ['.', '!', '?', '…'];
        $firstEnd = false;

        foreach ($punctuation as $punct) {
            $pos = mb_strpos($text, $punct, 0, 'UTF-8');
            if ($pos !== false) {
                $endPos = $pos + mb_strlen($punct, 'UTF-8');
                if ($firstEnd === false || $endPos < $firstEnd) {
                    $firstEnd = $endPos;
                }
            }
        }

        return $firstEnd;
    }

    private function removeFirstSentenceFromBlocks(array $blocks): array
    {
        foreach ($blocks as $index => $block) {
            if (($block['type'] ?? '') !== 'text') {
                continue;
            }

            $content = $block['content'] ?? '';
            if ($content === '') {
                continue;
            }

            $d = json_decode($content, true);
            if (is_array($d) === false || isset($d[0]) === false) {
                continue;
            }

            $text = $d[0];
            if (is_string($text) === false) {
                $text = is_array($text) === true ? implode('', $text) : (string) $text;
            }
            if ($text === '') {
                continue;
            }

            $sentenceEnd = $this->findSentenceEnd($text);

            if ($sentenceEnd === false) {
                unset($blocks[$index]);
                return array_values($blocks);
            }

            $newText = mb_substr($text, $sentenceEnd, null, 'UTF-8');
            $newText = ltrim($newText);

            if ($newText === '') {
                unset($blocks[$index]);
                return array_values($blocks);
            }

            $d[0] = $newText;

            if (isset($d[2]) === true && is_array($d[2]) === true) {
                $removedText = mb_substr($text, 0, $sentenceEnd, 'UTF-8');
                $removedUtf16 = mb_convert_encoding($removedText, 'UTF-16LE', 'UTF-8');
                $removedCodeUnits = mb_strlen($removedUtf16, 'UTF-16LE');

                $styles = [];
                foreach ($d[2] as $style) {
                    if (count($style) >= 3) {
                        $start = (int) ($style[1] ?? 0);
                        $length = (int) ($style[2] ?? 0);

                        if ($start >= $removedCodeUnits) {
                            $newStart = $start - $removedCodeUnits;
                            $styles[] = [$style[0], $newStart, $length];
                        } elseif ($start + $length > $removedCodeUnits) {
                            $newLength = ($start + $length) - $removedCodeUnits;
                            $styles[] = [$style[0], 0, $newLength];
                        }
                    }
                }
                $d[2] = $styles;
            }

            $block['content'] = json_encode($d, JSON_UNESCAPED_UNICODE);
            $blocks[$index] = $block;
            break;
        }

        return array_values($blocks);
    }

    private function isPaid(array $p): bool
    {
        return ($p['subscriptionLevel'] ?? null) !== null
            || ($p['price'] ?? 0) > 0;
    }

    private function isMediaType(string $type): bool
    {
        return isset(self::MEDIA_TYPES[$type]);
    }

    private function getHtmlTag(int $type): ?string
    {
        return match ($type) {
            0 => 'strong',
            1 => 'u',
            2 => 'em',
            3 => 's',
            default => null,
        };
    }

    private function getPostUrl(string $postId): string
    {
        return sprintf(
            'https://boosty.to/%s/posts/%s',
            urlencode($this->blogName),
            urlencode($postId)
        );
    }

    private function renderPaywall(array $p): string
    {
        $h = '<div' . $this->style('paywall') . '><p' . $this->style('pt') . '>This post requires payment</p>';

        $teaser = $this->renderTeaser($p);
        if ($teaser !== '') {
            $h = $teaser . $h;
        }

        if (isset($p['subscriptionLevel']) === true) {
            $lv = $p['subscriptionLevel'];
            $h .= '<p' . $this->style('pp') . '><strong>Subscription:</strong> ' . $this->esc($lv['name'] ?? 'Unknown') . '</p>';
            $pr = $this->price($lv['currencyPrices'] ?? []);
            if ($pr !== null) {
                $h .= '<p' . $this->style('pp') . '><strong>Price:</strong> ' . $this->esc($pr) . '/month</p>';
            }
        } elseif (isset($p['price']) === true && $p['price'] > 0) {
            $pr = $this->price($p['currencyPrices'] ?? [], $p['price']);
            if ($pr !== null) {
                $h .= '<p' . $this->style('pp') . '><strong>Price:</strong> ' . $this->esc($pr) . '</p>';
            }
        }

        $h .= '<p' . $this->style('pp') . '><a href="' . $this->esc($this->getPostUrl($p['id'] ?? '')) . '">View original post</a></p>';

        return $h . '</div>';
    }

    private function renderTeaser(array $p): string
    {
        $out = '';
        foreach ($p['teaser'] ?? [] as $b) {
            $type = $b['type'] ?? '';
            $rendition = $b['rendition'] ?? '';

            if ($type === 'image' && $rendition === 'teaser_auto_background') {
                continue;
            }

            if ($this->isMediaType($type) === true) {
                $out .= $this->renderMedia($b);
            } elseif ($type === 'text') {
                $r = $this->draft($b['content'] ?? '');
                if ($r !== '') {
                    $out .= '<p>' . $r . '</p>';
                }
            } elseif ($type === 'link') {
                $r = $this->renderLink($b);
                if ($r !== '') {
                    $out .= '<p>' . $r . '</p>';
                }
            } elseif ($type === 'list') {
                $out .= $this->renderList($b);
            }
        }
        return $out;
    }

    private function meta(array $p, string $title, string $content): array
    {
        $item = [
            'uri'       => $this->getPostUrl($p['id'] ?? ''),
            'title'     => $title,
            'content'   => $content,
            'timestamp' => $p['publishTime'] ?? time(),
            'author'    => $p['user']['name'] ?? $this->blogName,
            'uid'       => $p['id'] ?? uniqid(),
        ];
        if (isset($p['tags']) === true && is_array($p['tags']) === true && $this->getInput('hideTags') === false) {
            $item['categories'] = array_map(fn(array $t): string => $t['title'] ?? '', $p['tags']);
        }
        return $item;
    }

    private function renderFree(array $p): string
    {
        $out = '';
        $buf = [];
        foreach ($p['data'] ?? [] as $b) {
            $type = $b['type'] ?? '';
            $mod = $b['modificator'] ?? '';
            if ($type === 'text' && $mod === 'BLOCK_END') {
                if ($buf !== []) {
                    $out .= '<p>' . implode('', $buf) . '</p>';
                    $buf = [];
                }
                continue;
            }
            if ($type === 'text') {
                $r = $this->draft($b['content'] ?? '');
                if ($r !== '') {
                    $buf[] = $r;
                }
            } elseif ($type === 'link') {
                $r = $this->renderLink($b);
                if ($r !== '') {
                    $buf[] = $r;
                }
            } elseif ($type === 'list') {
                if ($buf !== []) {
                    $out .= '<p>' . implode('', $buf) . '</p>';
                    $buf = [];
                }
                $out .= $this->renderList($b);
            } else {
                if ($buf !== []) {
                    $out .= '<p>' . implode('', $buf) . '</p>';
                    $buf = [];
                }
                if ($this->isMediaType($type) === true) {
                    $out .= $this->renderMedia($b);
                }
            }
        }
        if ($buf !== []) {
            $out .= '<p>' . implode('', $buf) . '</p>';
        }
        if (empty($p['poll']) === false && is_array($p['poll']) === true) {
            $out .= $this->renderPoll($p['poll']);
        }
        return $out;
    }

    private function renderList(array $b): string
    {
        $style = $b['style'] ?? 'unordered';
        $tag = ($style === 'ordered') ? 'ol' : 'ul';
        $cssKey = ($style === 'ordered') ? 'ol' : 'ul';
        $items = $b['items'] ?? [];

        if ($items === []) {
            return '';
        }

        $inner = $this->renderListItems($items, $tag, $cssKey);
        if ($inner === '') {
            return '';
        }

        return '<' . $tag . $this->style($cssKey) . '>' . $inner . '</' . $tag . '>';
    }

    private function renderListItems(array $items, string $tag, string $cssKey): string
    {
        $h = '';
        foreach ($items as $item) {
            $content = $this->listItemContent($item);
            $nested = '';
            if (empty($item['items']) === false && is_array($item['items']) === true) {
                $nested = $this->renderListItems($item['items'], $tag, $cssKey);
            }
            if ($content === '' && $nested === '') {
                continue;
            }
            $h .= '<li' . $this->style('li') . '>' . $content . $nested . '</li>';
        }
        return $h;
    }

    private function listItemContent(array $item): string
    {
        $h = '';
        foreach ($item['data'] ?? [] as $block) {
            $bType = $block['type'] ?? '';
            if ($bType === 'text') {
                $r = $this->draft($block['content'] ?? '');
                if ($r !== '') {
                    $h .= $r;
                }
            } elseif ($bType === 'link') {
                $r = $this->renderLink($block);
                if ($r !== '') {
                    $h .= $r;
                }
            } elseif ($this->isMediaType($bType) === true) {
                $h .= $this->renderMedia($block);
            }
        }
        return $h;
    }

    private function renderLink(array $b): string
    {
        $url = $b['url'] ?? '';
        if ($url === '') {
            return '';
        }
        $title = $this->draft($b['content'] ?? '');
        if ($title === '') {
            $title = $this->esc($url);
        }
        return '<a href="' . $this->esc($url) . '">' . $title . '</a>';
    }

    private function renderMedia(array $b): string
    {
        $type = $b['type'] ?? '';
        $url = $this->esc($b['url'] ?? ($b['preview'] ?? ($b['defaultPreview'] ?? '')));
        if ($url === '') {
            return '';
        }

        if ($type === 'image' || $type === 'ok_video') {
            return '<p><img src="' . $url . '"' . $this->style('img') . ' alt=""></p>';
        }

        $title = $this->esc($b['title'] ?? ($b['track'] ?? 'File'));
        if ($type === 'audio_file' && empty($b['artist']) === false) {
            $title = $this->esc($b['artist']) . ' - ' . $title;
        }
        return '<p><a href="' . $url . '">' . $title . '</a></p>';
    }

    private function renderPoll(array $poll): string
    {
        $h = '<div' . $this->style('poll') . '>';
        $title = $poll['title'] ?? '';
        if (is_array($title) === true) {
            $title = implode(' ', $title);
        }
        if ($title !== '') {
            $h .= '<p' . $this->style('poll_t') . '>' . $this->esc($title) . '</p>';
        }

        $total = (int) ($poll['counter'] ?? 0);
        if ($total === 0) {
            foreach ($poll['options'] ?? [] as $o) {
                $total += (int) ($o['counter'] ?? 0);
            }
        }

        foreach ($poll['options'] ?? [] as $o) {
            $text = $this->esc($o['text'] ?? '');
            $c = (int) ($o['counter'] ?? 0);
            $f = $this->calculateFraction($o, $total);
            $pct = max(0, min(100, (int) round($f)));
            $filled = (int) round($pct / 5);
            $bar = '[' . str_repeat('#', $filled) . str_repeat('.', 20 - $filled) . ']';

            $h .= '<div' . $this->style('poll_o') . '>';
            $h .= '<b>' . $pct . '%</b> ' . $text . '<br />';
            $h .= '<code>' . $bar . '</code>';
            $h .= '</div>';
        }

        $footer = [];
        if ($total > 0) {
            $footer[] = $total . ' voters';
        }
        if (empty($poll['isMultiple']) === false) {
            $footer[] = 'Multiple choice';
        }
        if (empty($poll['isFinished']) === false) {
            $footer[] = 'Finished';
        }

        if ($footer !== []) {
            $h .= '<p' . $this->style('poll_f') . '>' . implode(' &#183; ', $footer) . '</p>';
        }

        return $h . '</div>';
    }

    private function calculateFraction(array $option, int $total): float
    {
        if (isset($option['fraction']) === true) {
            return (float) $option['fraction'];
        }
        $counter = (int) ($option['counter'] ?? 0);
        return $total > 0 ? ($counter / $total) * 100.0 : 0.0;
    }

    private function pollVisible(array $poll, int $total): bool
    {
        if (empty($poll['isFinished']) === false || empty($poll['showResults']) === false || empty($poll['isResultVisible']) === false) {
            return true;
        }
        foreach ($poll['options'] ?? [] as $o) {
            if (array_key_exists('fraction', $o) === true || (isset($o['counter']) === true && $o['counter'] > 0)) {
                return true;
            }
        }
        return false;
    }

    private function draft(string $content): string
    {
        if ($content === '') {
            return '';
        }
        try {
            $d = json_decode($content, true);
            if (is_array($d) === false || isset($d[0]) === false) {
                return '';
            }
            $text = $d[0];
            if (is_string($text) === false) {
                $text = is_array($text) === true ? implode('', $text) : (string) $text;
            }
            if ($text === '') {
                return '';
            }
            $utf16 = mb_convert_encoding(
                string: $text,
                to_encoding: 'UTF-16LE',
                from_encoding: 'UTF-8'
            );
            $units = str_split($utf16, 2);
            $styles = (isset($d[2]) === true && is_array($d[2]) === true) ? $d[2] : [];
            return str_replace("\n", '<br>', $this->applyStyles($units, $styles));
        } catch (\Throwable $e) {
            return $this->esc($content);
        }
    }

    private function applyStyles(array $units, array $styles): string
    {
        $n = count($units);
        $tags = array_fill(0, $n + 1, '');
        $ev = [];
        foreach ($styles as $s) {
            if (count($s) < 3) {
                continue;
            }
            $tag = $this->getHtmlTag((int) ($s[0] ?? -1));
            if ($tag === null) {
                continue;
            }
            $a = (int) ($s[1] ?? 0);
            $b = $a + (int) ($s[2] ?? 0);
            if ($a < 0 || $b > $n || $a >= $b) {
                continue;
            }
            $d = count($ev) / 2 + 1;
            $ev[] = ['p' => $a, 't' => "<{$tag}>", 'k' => 0, 'd' => $d];
            $ev[] = ['p' => $b, 't' => "</{$tag}>", 'k' => 1, 'd' => $d];
        }
        if ($ev !== []) {
            usort($ev, $this->compareEvents(...));
            foreach ($ev as $e) {
                $tags[$e['p']] .= $e['t'];
            }
        }
        $out = '';
        for ($i = 0; $i <= $n; $i++) {
            $out .= $tags[$i];
            if ($i < $n) {
                $hi = $i < $n - 1 ? unpack('v', $units[$i])[1] : 0;
                if ($hi >= 0xD800 && $hi <= 0xDBFF) {
                    $converted = mb_convert_encoding(
                        string: $units[$i] . $units[$i + 1],
                        to_encoding: 'UTF-8',
                        from_encoding: 'UTF-16LE'
                    );
                    $out .= $this->esc($converted !== false ? $converted : '');
                    $out .= $tags[++$i] ?? '';
                } else {
                    $converted = mb_convert_encoding(
                        string: $units[$i],
                        to_encoding: 'UTF-8',
                        from_encoding: 'UTF-16LE'
                    );
                    $out .= $this->esc($converted !== false ? $converted : '');
                }
            }
        }
        return $out;
    }

    private function compareEvents(array $x, array $y): int
    {
        if ($x['p'] !== $y['p']) {
            return $x['p'] <=> $y['p'];
        }
        if ($x['k'] !== $y['k']) {
            return $x['k'] === 1 ? -1 : 1;
        }
        return $x['k'] === 1 ? ($y['d'] <=> $x['d']) : ($x['d'] <=> $y['d']);
    }

    private function price(array $cp, $fb = null): ?string
    {
        return match (true) {
            isset($cp['RUB']) === true => $cp['RUB'] . ' RUB',
            isset($cp['USD']) === true => $cp['USD'] . ' USD',
            default => $fb !== null ? (string) $fb : null,
        };
    }

    private function esc($s): string
    {
        return htmlspecialchars(
            string: (string) $s,
            flags: ENT_QUOTES | ENT_HTML5,
            encoding: 'UTF-8'
        );
    }

    private function style(string $key, string $extra = ''): string
    {
        $css = self::CSS[$key] ?? '';
        if ($extra !== '') {
            $css = ($css !== '' ? $css . ';' : '') . $extra;
        }
        return $css !== '' ? ' style="' . $css . '"' : '';
    }

    public function getName(): string
    {
        if ($this->blogDisplayName !== '') {
            return 'Boosty: ' . $this->blogDisplayName;
        }
        $blog = $this->getInput('blog');
        return $blog !== null && $blog !== '' ? 'Boosty: ' . $blog : self::NAME;
    }

    public function getURI(): string
    {
        $blog = $this->getInput('blog');
        return $blog !== null && $blog !== '' ? 'https://boosty.to/' . urlencode($blog) : self::URI;
    }

    public function getIcon(): string
    {
        if (empty($this->blogAvatar) === true) {
            return parent::getIcon();
        }
        return $this->blogAvatar . '#.png';
    }
}
