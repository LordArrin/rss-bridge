<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;
use RSSBridge\Utils\Json;

final class ByrutGameNewsBridge extends BridgeAbstract
{
    public const NAME = 'ByRutor';
    public const URI = 'https://byrutgame.org/';
    public const DESCRIPTION = 'Returns the news from widget on ByrutGame.org';
    public const MAINTAINER = 'LordArrin';
    public const CACHE_TIMEOUT = 1800;

    private const FEED_ENDPOINT = 'engine/ajax/controller.php?mod=newsfeed&action=feed';

    private const CSS_WRAPPER = 'font-size:14px; line-height:1.6; word-wrap:break-word;';
    private const CSS_QUOTE = 'margin:12px 0; padding:8px 12px; border-left:4px solid #fdda60; background:rgba(154,103,0,0.08);';

    public const PARAMETERS = [
        '' => [
            'rubric' => [
                'name' => 'Rubric',
                'type' => 'list',
                'values' => [
                    'All' => '',
                    '#news' => 'news',
                    '#denuvo_cracked' => 'denuvo_cracked',
                    '#denuvo_removed' => 'denuvo_removed',
                    '#update' => 'update',
                    '#release' => 'release',
                ],
                'defaultValue' => '',
            ],
            'limit' => [
                'name' => 'Item limit',
                'type' => 'number',
                'defaultValue' => 20,
            ],
        ],
    ];

    public function collectData(): void
    {
        $rubric = (string) $this->getInput('rubric');
        $limit = max(1, (int) ($this->getInput('limit') ?? 20));

        $body = (string) getContents(self::URI . self::FEED_ENDPOINT, ['Referer: ' . self::URI]);

        $data = Json::decode($body);
        if (
            is_array($data) === false
            || ($data['ok'] ?? false) !== true
            || isset($data['html']) === false
            || is_string($data['html']) === false
            || $data['html'] === ''
        ) {
            throwServerException('ByrutGame newsfeed returned an unexpected response');
        }

        $fragment = parseHtmlFragment($data['html']);
        if ($fragment === null) {
            throwServerException('Failed to parse ByrutGame newsfeed HTML');
        }

        $articles = $fragment->querySelectorAll('article.nf-full');
        if ($articles->length === 0) {
            throwServerException('No news articles found. The widget layout may have changed.');
        }

        foreach ($articles as $article) {
            if (count($this->items) >= $limit) {
                break;
            }

            $articleRubric = $article->getAttribute('data-nf-rubric') ?? '';
            if ($rubric !== '' && $articleRubric !== $rubric) {
                continue;
            }

            $item = $this->parseArticle($fragment, $article, $articleRubric);
            if ($item !== null) {
                $this->items[] = $item;
            }
        }

        if ($this->items === []) {
            throwServerException('No news found for the selected rubric.');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseArticle(\Dom\HTMLDocument $document, \Dom\Element $article, string $rubric): ?array
    {
        $body = $article->querySelector('.nf-full__body');
        if ($body === null) {
            return null;
        }

        $title = '';
        foreach ($body->childNodes as $child) {
            if ($child instanceof \Dom\Element === true && $child->localName === 'p') {
                $title = strip_invisible_unicode(trim($child->textContent ?? ''));
                $child->remove();
                break;
            }
        }

        if ($title === '') {
            $title = truncate(strip_invisible_unicode(trim($body->textContent ?? '')), 150);
        }
        if ($title === '') {
            return null;
        }

        foreach ($body->querySelectorAll('div.nf-quote') as $quote) {
            $blockquote = $document->createElement('blockquote');
            $blockquote->setAttribute('style', self::CSS_QUOTE);

            while ($quote->firstChild !== null) {
                $child = $quote->firstChild;
                if ($child instanceof \Dom\Element === true && $child->classList->contains('nf-quote__clip') === true) {
                    while ($child->firstChild !== null) {
                        $blockquote->appendChild($child->firstChild);
                    }
                    $quote->removeChild($child);
                    continue;
                }
                $blockquote->appendChild($child);
            }

            $quote->parentNode?->replaceChild($blockquote, $quote);
        }

        $content = sprintf('<div style="%s">%s</div>', self::CSS_WRAPPER, break_annoying_html_tags($body->innerHTML));

        $id = $article->getAttribute('data-nf-id') ?? '';
        $uid = $id !== '' ? 'byrutgame-news-' . $id : sha1($title . $content);

        $ts = $article->querySelector('.nf-date')?->getAttribute('data-ts');
        $timestamp = $ts !== null && ctype_digit($ts) === true ? (int) $ts : time();

        return [
            'title' => $title,
            'uri' => self::URI . '#newsfeed-block',
            'content' => $content,
            'timestamp' => $timestamp,
            'uid' => $uid,
            'categories' => $rubric !== '' ? [$rubric] : [],
        ];
    }
}
