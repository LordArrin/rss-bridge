<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

final class ZenlessZoneZeroBridge extends HoyoBase
{
    public const NAME = 'Zenless Zone Zero (ZZZ)';
    public const URI = 'https://zenless.hoyoverse.com/en-us/news';
    public const DESCRIPTION = 'Latest news from the Zenless Zone Zero website';
    public const MAINTAINER = 'LordArrin';

    protected const API_URL = 'https://sg-public-api-static.hoyoverse.com/content_v2_user/app/3e9196a4b9274bd7/getContentList?iPageSize=%u&iPage=1&iChanId=288&sLangKey=%s';
    protected const ARTICLE_URL_TEMPLATE = '/en-us/news/%u';
    protected const BANNER_KEY = 'news-banner';

    protected function getApiUrl(int $limit, string $language): string
    {
        return sprintf(self::API_URL, $limit, $language);
    }

    protected function getArticleUrl(int $infoId): string
    {
        return sprintf(self::ARTICLE_URL_TEMPLATE, $infoId);
    }

    protected function getBannerKey(): string
    {
        return self::BANNER_KEY;
    }

    protected function processContentHtml(\Dom\Element $wrapper, string $sContent): void
    {
        $this->alignTextLeft($wrapper);
    }

    protected function alignTextLeft(\Dom\Element $node): void
    {
        foreach ($node->querySelectorAll('p, div, h1, h2, h3, h4, h5, h6') as $element) {
            if ($element instanceof \Dom\Element === false) {
                continue;
            }

            $style = (string) ($element->getAttribute('style') ?? '');
            if ($style !== '') {
                $newStyle = preg_replace('/text-align\s*:\s*center\s*;?/i', '', $style);
                if (is_string($newStyle) === true && $newStyle !== $style) {
                    $newStyle = trim($newStyle);
                    if ($newStyle === '') {
                        $element->removeAttribute('style');
                    } else {
                        $element->setAttribute('style', $newStyle);
                    }
                }
            }

            $element->setAttribute('align', 'left');
        }
    }
}
