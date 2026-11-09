<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;
use RSSBridge\Utils\Json;

final class FallGuysBridge extends BridgeAbstract
{
    public const NAME = 'Fall Guys';
    public const URI = 'https://www.fallguys.com';
    public const DESCRIPTION = 'News from the Fall Guys website';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 1800;

    public const DEFAULT_LOCALE = 'en-US';

    public const PARAMETERS = [
        [
            'locale' => [
                'name' => 'Language',
                'type' => 'list',
                'values' => [
                    'English' => 'en-US',
                    'لعربية' => 'ar',
                    'Deutsch' => 'de',
                    'Español (Spain)' => 'es-ES',
                    'Español (LA)' => 'es-MX',
                    'Français' => 'fr',
                    'Italiano' => 'it',
                    '日本語' => 'ja',
                    '한국어' => 'ko',
                    'Polski' => 'pl',
                    'Português (Brasil)' => 'pt-BR',
                    'Русский' => 'ru',
                    'Türkçe' => 'tr',
                    '简体中文' => 'zh-CN',
                ],
                'defaultValue' => self::DEFAULT_LOCALE,
            ]
        ]
    ];

    public function getURI(): string
    {
        return static::URI;
    }

    public function getIcon(): string
    {
        return static::URI . '/favicon.ico';
    }

    public function collectData(): void
    {
        $newsListUrl = $this->buildNewsUri('');
        $newsData = $this->requestJsonData($newsListUrl);

        if ($newsData === null || isset($newsData->props->pageProps->newsList) === false) {
            $this->logger->error('Failed to parse news list from Fall Guys website');
            return;
        }

        foreach ($newsData->props->pageProps->newsList as $newsItem) {
            if ($newsItem instanceof \stdClass === false) {
                continue;
            }

            $slug = $newsItem->slug ?? '';
            $newsItemUrl = $this->buildNewsUri($slug);
            $newsItemTitle = $newsItem->header->title ?? '';

            if ($newsItemTitle === '') {
                continue;
            }

            $headerDescription = $newsItem->header->description ?? '';
            $headerImage = $newsItem->newsLandingConfig->options[0]->image ?? null;
            $headerImageUrl = '';

            if (isset($headerImage->imageAsset->url) === true) {
                $headerImageUrl = $headerImage->imageAsset->url;
            } elseif (isset($headerImage->src->url) === true) {
                $headerImageUrl = $headerImage->src->url;
            }

            $content = '';
            if ($headerDescription !== '') {
                $content .= sprintf('<p>%s</p>', e($headerDescription));
            }

            if ($headerImageUrl !== '') {
                $content .= sprintf(
                    '<p><img src="%s" style="display: block; max-width: 1200px; width: 100%%; height: auto; margin: 1em 0;"></p>',
                    e($headerImageUrl)
                );
            }

            $newsItemData = null;
            try {
                $newsItemData = $this->requestJsonData($newsItemUrl);
            } catch (\Exception $e) {
                $this->logger->error(sprintf('Failed to request data for news item "%s" (%s)', $newsItemTitle, $newsItemUrl), ['e' => $e]);
            }

            if ($newsItemData === null) {
                $this->logger->error(sprintf('Failed to parse json data for news item "%s" (%s)', $newsItemTitle, $newsItemUrl));
            } else {
                $contentItems = $newsItemData->props->pageProps->pageData->content->items ?? [];

                foreach ($contentItems as $contentItem) {
                    if ($contentItem instanceof \stdClass === false) {
                        continue;
                    }

                    if (isset($contentItem->articleCopy) === true) {
                        $articleCopy = $contentItem->articleCopy;
                        $title = $articleCopy->title ?? '';
                        $text = $articleCopy->copy ?? '';

                        if ($title !== '') {
                            $content .= sprintf('<h2>%s</h2>', e($title));
                        }

                        if ($text !== '') {
                            $trimmedText = trim($text);
                            if (preg_match('/<[a-z][\s\S]*>/i', $trimmedText) === 1) {
                                $content .= raw($trimmedText);
                            } else {
                                $content .= sprintf('<p>%s</p>', e($trimmedText));
                            }
                        }
                    } elseif (isset($contentItem->articleImage) === true) {
                        $image = $contentItem->articleImage->imageSrc ?? '';

                        if ($image !== '' && $image !== $headerImageUrl) {
                            $content .= sprintf(
                                '<p><img src="%s" style="display: block; max-width: 1200px; width: 100%%; height: auto; margin: 1em 0;"></p>',
                                e($image)
                            );
                        }
                    } elseif (isset($contentItem->embeddedVideo) === true) {
                        $embeddedVideo = $contentItem->embeddedVideo;
                        $mediaOptions = $embeddedVideo->mediaOptions ?? [];
                        $mainContentOptions = $embeddedVideo->mainContentOptions ?? [];

                        if (count($mediaOptions) === count($mainContentOptions)) {
                            $len = count($mediaOptions);
                            for ($i = 0; $i < $len; $i++) {
                                $mediaOption = $mediaOptions[$i];
                                if ($mediaOption instanceof \stdClass === false) {
                                    continue;
                                }

                                if (isset($mediaOption->youtubeVideo->contentId) === true) {
                                    $videoID = $mediaOption->youtubeVideo->contentId;
                                    $videoHtml = handleYoutube($videoID);

                                    $content .= '<p>' . $videoHtml;

                                    $mainOption = $mainContentOptions[$i] ?? null;
                                    $image = '';
                                    if ($mainOption instanceof \stdClass === true && isset($mainOption->image->src) === true) {
                                        $image = $mainOption->image->src;
                                    }

                                    if ($image !== '' && $image !== $headerImageUrl) {
                                        $content .= sprintf(
                                            '<img src="%s" style="display: block; max-width: 1200px; width: 100%%; height: auto; margin: 1em 0;"><br>',
                                            e($image)
                                        );
                                    }

                                    $content .= '</p>';
                                }
                            }
                        }
                    } else {
                        $this->logger->warning(sprintf('Unsupported content item in news item "%s" (%s)', $newsItemTitle, $newsItemUrl));
                    }
                }
            }

            $this->items[] = [
                'uid' => $newsItem->id ?? uniqid('fallguys-', true),
                'uri' => $newsItemUrl,
                'title' => $newsItemTitle,
                'timestamp' => $newsItem->activeDate ?? '',
                'content' => $content,
            ];
        }
    }

    private function buildNewsUri(string $slug): string
    {
        $locale = $this->getInput('locale') ?? self::DEFAULT_LOCALE;
        $uri = static::URI . '/news';

        if ($slug !== '') {
            $uri .= '/' . $slug;
        }

        return $uri . '?lang=' . $locale;
    }

    private function requestJsonData(string $url): ?\stdClass
    {
        $cacheKey = 'fallguys_' . md5($url);
        $cachedHtml = $this->loadCacheValue($cacheKey);

        if (is_string($cachedHtml) === false || $cachedHtml === '') {
            $html = (string) getContents($url);
            $this->saveCacheValue($cacheKey, $html, static::CACHE_TIMEOUT);
            $cachedHtml = $html;
        }

        $dom = \Dom\HTMLDocument::createFromString($cachedHtml);
        $element = $dom->querySelector('#__NEXT_DATA__');

        if ($element instanceof \Dom\Element === false) {
            return null;
        }

        $json = $element->textContent;

        try {
            $decoded = Json::decode($json, false);
            return $decoded instanceof \stdClass ? $decoded : null;
        } catch (\JsonException $e) {
            $this->logger->error('Failed to decode JSON from Fall Guys website', ['url' => $url, 'e' => $e]);
            return null;
        }
    }
}
