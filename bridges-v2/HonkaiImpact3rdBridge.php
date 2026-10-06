<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

final class HonkaiImpact3rdBridge extends HoyoBase
{
    public const NAME = 'Honkai Impact 3rd';
    public const URI = 'https://honkaiimpact3.hoyoverse.com/global/en-us/home';
    public const DESCRIPTION = 'Latest news from the Honkai Impact 3rd website';
    public const MAINTAINER = 'LordArrin';

    protected const API_URL_TEMPLATE = 'https://sg-public-api-static.hoyoverse.com/content_v2_user/app/5fcd2aa439ca4aea/getContentList?iPageSize=%u&iPage=1&sLangKey=%s&iChanId=514&isPreview=0';
    protected const ARTICLE_URL_TEMPLATE = '/global/en-us/news/%u';

    protected const LANGUAGE_VALUES = [
        'English' => 'en-us',
        'German' => 'de-de',
        'French' => 'fr-fr',
    ];

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
        return sprintf(self::API_URL_TEMPLATE, $limit, $language);
    }

    protected function getArticleUrl(int $infoId): string
    {
        return sprintf(self::ARTICLE_URL_TEMPLATE, $infoId);
    }

    protected function getBannerKey(): string
    {
        return '';
    }

    protected function getBannerUrl(array $jsonExt): string
    {
        foreach ($jsonExt as $key => $value) {
            if (is_array($value) === true && isset($value[0]['url']) === true && $value[0]['url'] !== '') {
                return (string) $value[0]['url'];
            }
        }
        return '';
    }

    protected function processContentHtml(\Dom\Element $wrapper, string $sContent): void
    {
        $this->alignTextLeft($wrapper);
        $this->processYoutubeEmbeds($wrapper);
    }
}
