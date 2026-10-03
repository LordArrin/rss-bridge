<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

final class GenshinImpactBridge extends HoyoBase
{
    public const NAME = 'Genshin Impact';
    public const URI = 'https://genshin.hoyoverse.com/en/news';
    public const DESCRIPTION = 'Latest news from the Genshin Impact website';
    public const MAINTAINER = 'LordArrin';

    protected const API_URL = 'https://sg-public-api-static.hoyoverse.com/content_v2_user/app/a1b1f9d3315447cc/getContentList?iAppId=%u&iChanId=395&iPageSize=%u&iPage=1&sLangKey=%s';
    protected const API_APP_ID = 32;
    protected const ARTICLE_URL_TEMPLATE = '/news/detail/%u';
    protected const BANNER_KEY = 'banner';

    protected function getApiUrl(int $limit, string $language): string
    {
        return sprintf(self::API_URL, self::API_APP_ID, $limit, $language);
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
        $expYoutube = '#https://[w\.]+youtube\.com/embed/([\w]+)#m';
        if (preg_match($expYoutube, $sContent) === 1) {
            $ytEmbed = $wrapper->querySelector('div[class="ttr-video-frame"]');
            if ($ytEmbed !== null && $ytEmbed->parentNode !== null && function_exists('handleYoutube') === true) {
                $dom = $wrapper->ownerDocument;
                $ytHtml = $dom->saveHTML($ytEmbed);

                if (is_string($ytHtml) === true) {
                    $replacement = handleYoutube($ytHtml);
                    if (is_string($replacement) === true && $replacement !== '') {
                        $newNode = $dom->createDocumentFragment();
                        $newNode->appendXML($replacement);
                        $ytEmbed->parentNode->replaceChild($newNode, $ytEmbed);
                    }
                }
            }
        }
    }
}
