<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class WirecutterDealsBridge extends BridgeAbstract
{
    public const NAME = 'Wirecutter Deals';
    public const URI = 'https://www.nytimes.com/wirecutter/deals/';
    public const DESCRIPTION = 'Deals from The Wirecutter';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 900;

    private const IMAGE_BASE_URL = 'https://cdn.thewirecutter.com/';
    private const IMAGE_PARAMS = '?width=314&quality=75&crop=3:2&auto=webp';
    private const REVIEW_BASE_URL = 'https://www.nytimes.com/wirecutter';

    private const CSS = [
        'image' => 'display: block; max-width: 500px; height: auto; margin: 10px 0;',
    ];

    public function collectData()
    {
        $html = getContents($this->getURI());

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $nextData = $dom->querySelector('#__NEXT_DATA__');
        if ($nextData === null) {
            throwServerException('Could not find __NEXT_DATA__ element');
        }

        $jsonText = $nextData->textContent ?? '';
        if ($jsonText === '') {
            throwServerException('Empty __NEXT_DATA__ content');
        }

        $data = json_decode($jsonText, false);
        if (is_object($data) === false) {
            throwServerException('Failed to parse __NEXT_DATA__ JSON');
        }

        if (isset($data->props->pageProps->specialEvent->eventDeals) === false) {
            throwServerException('Invalid data structure: missing eventDeals');
        }

        $eventDeals = $data->props->pageProps->specialEvent->eventDeals;
        if (is_array($eventDeals) === false) {
            throwServerException('eventDeals is not an array');
        }

        foreach ($eventDeals as $deal) {
            if (is_object($deal) === false) {
                continue;
            }

            $dealId = $deal->id ?? null;
            $dealTitle = (string)($deal->title ?? '');
            $dealDate = $deal->date ?? null;

            if ($dealId === null || $dealTitle === '') {
                continue;
            }

            $item = [];
            $item['uri'] = self::URI . '#deal-' . rawurlencode((string)$dealId);
            $item['title'] = $dealTitle;
            $item['uid'] = (string)$dealId;
            $item['content'] = $this->generateContent($deal);

            if (isset($deal->categories) === true && is_array($deal->categories) === true) {
                $item['categories'] = array_map('strval', $deal->categories);
            }

            if (is_numeric($dealDate) === true) {
                $item['timestamp'] = (int)$dealDate;
            } elseif (is_string($dealDate) === true && $dealDate !== '') {
                $item['timestamp'] = $dealDate;
            }

            $this->items[] = $item;
        }
    }

    private function jsonToHtml(array $node): string
    {
        $type = $node['type'] ?? '';

        if ($type === 'text') {
            $data = $node['data'] ?? '';
            return e((string)$data);
        }

        if ($type === 'tag') {
            $name = $node['name'] ?? '';
            if ($name === '') {
                return '';
            }

            $html = '<' . e((string)$name);

            if (isset($node['attribs']) === true && is_array($node['attribs']) === true) {
                foreach ($node['attribs'] as $key => $value) {
                    $html .= sprintf(
                        ' %s="%s"',
                        e((string)$key),
                        e((string)$value)
                    );
                }
            }

            $html .= '>';

            if (isset($node['children']) === true && is_array($node['children']) === true) {
                foreach ($node['children'] as $child) {
                    if (is_array($child) === true) {
                        $html .= $this->jsonToHtml($child);
                    }
                }
            }

            $html .= '</' . e((string)$name) . '>';

            return $html;
        }

        return '';
    }

    private function generateContent(object $deal): string
    {
        $content = '';

        $imgLink = $deal->image->source ?? null;
        if ($imgLink !== null && $imgLink !== '') {
            $imgUrl = self::IMAGE_BASE_URL . (string)$imgLink . self::IMAGE_PARAMS;
            $content .= '<p><img src="' . e($imgUrl) . '" style="' . self::CSS['image'] . '" /></p>';
        }

        $price = $deal->price ?? null;
        $streetPrice = $deal->streetPrice ?? null;
        if ($price !== null) {
            $content .= '<p><strong>$' . e((string)$price) . '</strong>';
            if ($streetPrice !== null) {
                $content .= ' <del>$' . e((string)$streetPrice) . '</del>';
            }
            $content .= '</p>';
        }

        if (isset($deal->buyButtons) === true && is_array($deal->buyButtons) === true) {
            foreach ($deal->buyButtons as $buy) {
                if (is_object($buy) === false) {
                    continue;
                }

                $buyUrl = (string)($buy->url ?? '');
                $merchant = (string)($buy->merchant ?? '');

                if ($buyUrl === '' || $merchant === '') {
                    continue;
                }

                $content .= '<p>Buy from <a href="' . e($buyUrl) . '">' . e($merchant) . '</a>';

                $promoEffect = $buy->promo->effect ?? null;
                if ($promoEffect !== null && $promoEffect !== '') {
                    $content .= ' ' . e((string)$promoEffect);
                }

                $promoCode = $buy->promo->code ?? null;
                if ($promoCode !== null && $promoCode !== '') {
                    $content .= ' (Use promo code ' . e((string)$promoCode) . ')';
                }

                $content .= '</p>';
            }
        }

        $structuredContentJson = $deal->structuredContent ?? null;
        if ($structuredContentJson !== null && $structuredContentJson !== '') {
            $structuredContent = json_decode((string)$structuredContentJson, true);
            if (is_array($structuredContent) === true) {
                $content .= '<p>&nbsp;</p>';
                foreach ($structuredContent as $node) {
                    if (is_array($node) === true) {
                        $content .= $this->jsonToHtml($node);
                    }
                }
            }
        }

        $relatedArticle = $deal->relatedArticle ?? null;
        if ($relatedArticle !== null && is_object($relatedArticle) === true) {
            $reviewLink = (string)($relatedArticle->link ?? '');
            $reviewTitle = (string)($relatedArticle->title ?? '');

            if ($reviewLink !== '' && $reviewTitle !== '') {
                $content .= '<p>&nbsp;</p>';
                $content .= '<p>Read the review: <a href="' . e(self::REVIEW_BASE_URL . $reviewLink) . '">' . e($reviewTitle) . '</a></p>';
            }
        }

        return $content;
    }
}
