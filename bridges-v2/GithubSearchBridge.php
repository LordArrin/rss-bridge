<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class GithubSearchBridge extends BridgeAbstract
{
    public const NAME = 'Github Repositories Search';
    public const URI = self::BASE_URI . '/search';
    public const BASE_URI = 'https://github.com';
    public const DESCRIPTION = 'Returns a specified repositories search (sorted by recently updated)';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 600;

    public const PARAMETERS = [
        [
            's' => [
                'type' => 'text',
                'required' => true,
                'exampleValue' => 'rss-bridge',
                'name' => 'Search query'
            ]
        ]
    ];

    public function collectData(): void
    {
        $searchValue = $this->getInput('s');
        if (is_string($searchValue) === false || $searchValue === '') {
            \throwClientException('Search query is required');
        }

        $html = getContents($this->getURI());

        if (is_string($html) === false || $html === '') {
            \throwServerException('Empty response from GitHub search');
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $resultElement = $dom->querySelector('[data-testid="results-list"]');
        if ($resultElement === null) {
            return;
        }

        $children = $resultElement->querySelectorAll('*');

        foreach ($children as $element) {
            if ($element instanceof \Dom\Element === false) {
                continue;
            }

            $titleElement = $element->querySelector('.search-title');
            if ($titleElement === null) {
                continue;
            }

            $descriptionElement = $element->querySelector('div > .search-match');
            $topicElements = $element->querySelectorAll('a[href^="/topic"]');
            $languageElement = $element->querySelector('li [aria-label$="language"]');
            $dateElement = $element->querySelector('li [title*=" "]');

            $titleLink = $titleElement->querySelector('a');
            if ($titleLink === null) {
                continue;
            }

            $href = (string) ($titleLink->getAttribute('href') ?? '');
            if ($href === '') {
                continue;
            }

            $item = [];
            $item['uri'] = self::BASE_URI . $href;
            $item['title'] = trim((string) $titleElement->textContent);

            $timestamp = time();
            if ($dateElement !== null) {
                $dateTitle = (string) ($dateElement->getAttribute('title') ?? '');
                if ($dateTitle !== '') {
                    $ts = strtotime($dateTitle);
                    if ($ts !== false) {
                        $timestamp = $ts;
                    }
                }
            }
            $item['timestamp'] = $timestamp;

            $categories = [];

            $content = '<p>';
            if ($descriptionElement !== null) {
                $content .= htmlspecialchars(trim((string) $descriptionElement->textContent));
            } else {
                $content .= 'No description';
            }
            $content .= '</p>';

            if ($topicElements->length > 0) {
                $content .= '<p>';
                $content .= 'Topics: ';
                foreach ($topicElements as $topicElement) {
                    if ($topicElement instanceof \Dom\Element === false) {
                        continue;
                    }
                    $topicHref = (string) ($topicElement->getAttribute('href') ?? '');
                    $topicTitle = trim((string) $topicElement->textContent);

                    if ($topicHref !== '') {
                        $topicLink = self::BASE_URI . $topicHref;
                        $content .= '<a href="' . htmlspecialchars($topicLink) . '">' . htmlspecialchars($topicTitle) . '</a> ';
                        $categories[] = $topicTitle;
                    }
                }
                $content .= '</p>';
            }

            if ($languageElement !== null) {
                $content .= '<p>';
                $content .= 'Language: ';
                $content .= htmlspecialchars(trim((string) $languageElement->textContent));
                $content .= '</p>';
            }

            $item['content'] = $content;
            $item['categories'] = $categories;
            $item['uid'] = $item['uri'];

            $this->items[] = $item;
        }
    }

    public function getURI(): string
    {
        $searchValue = $this->getInput('s');
        if (is_string($searchValue) === true && $searchValue !== '') {
            $params = [
                'q' => $searchValue,
                'type' => 'repositories',
                's' => 'updated',
                'o' => 'desc',
            ];
            return self::URI . '?' . http_build_query($params);
        }
        return self::URI;
    }
}
