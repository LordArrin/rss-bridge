<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

final class HonkaiStarRailBridge extends HoyoBase
{
    public const NAME = 'Honkai: Star Rail';
    public const URI = 'https://hsr.hoyoverse.com/en-us/news';
    public const DESCRIPTION = 'Latest news from the Honkai: Star Rail website';
    public const MAINTAINER = 'LordArrin';

    protected const API_URL_TEMPLATE = 'https://sg-public-api-static.hoyoverse.com/content_v2_user/app/113fe6d3b4514cdd/getContentList?iPage=1&iPageSize=%u&sLangKey=%s&isPreview=0&iChanId=248';
    protected const ARTICLE_URL_TEMPLATE = '/en-us/news/%u';
    protected const BANNER_KEY = 'news-poster';

    protected function getApiUrl(int $limit, string $language): string
    {
        return sprintf(self::API_URL_TEMPLATE, $limit, $language);
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
}
