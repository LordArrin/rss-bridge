<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

final class GenshinImpactBridge extends HoyoBase
{
    public const NAME = 'Genshin Impact';
    public const URI = 'https://genshin.hoyoverse.com/en/news';
    public const DESCRIPTION = 'Latest news from the Genshin Impact website';
    public const MAINTAINER = 'LordArrin';

    protected const API_URL_TEMPLATE = 'https://sg-public-api-static.hoyoverse.com/content_v2_user/app/a1b1f9d3315447cc/getContentList?iAppId=%u&iChanId=395&iPageSize=%u&iPage=1&sLangKey=%s';
    protected const API_APP_ID = 32;
    protected const ARTICLE_URL_TEMPLATE = '/news/detail/%u';
    protected const BANNER_KEY = 'banner';

    public const PARAMETERS = [
        '' => [
            'limit' => [
                'name' => 'Limit',
                'type' => 'number',
                'defaultValue' => self::LIMIT_DEFAULT,
            ],
            'language' => [
                'name' => 'Language',
                'type' => 'list',
                'values' => self::LANGUAGE_VALUES,
                'defaultValue' => self::LANGUAGE_DEFAULT,
            ],
        ],
    ];

    protected function getApiUrl(int $limit, string $language): string
    {
        return sprintf(self::API_URL_TEMPLATE, self::API_APP_ID, $limit, $language);
    }

    protected function getArticleUrl(int $infoId): string
    {
        return sprintf(self::ARTICLE_URL_TEMPLATE, $infoId);
    }

    protected function getBannerKey(): string
    {
        return self::BANNER_KEY;
    }
}
