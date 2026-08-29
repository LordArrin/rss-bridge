<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class GoComicsBridge extends BridgeAbstract
{
    public const NAME = 'GoComics Unofficial RSS';
    public const URI = 'https://www.gocomics.com/';
    public const DESCRIPTION = 'The Unofficial GoComics RSS';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 21600;

    public const PARAMETERS = [[
        'comicname' => [
            'name' => 'comicname',
            'type' => 'text',
            'exampleValue' => 'heartofthecity',
            'required' => true,
        ],
        'date-in-title' => [
            'name' => 'Add date and full name to each day\'s title',
            'type' => 'checkbox',
            'title' => 'Adds the date and the full name into the title of each day\'s comic',
        ],
        'limit' => [
            'name' => 'Limit',
            'type' => 'number',
            'title' => 'The number of recent comics to get',
            'defaultValue' => 2,
        ],
    ]];

    private const COMIC_DATE_REGEX = '/(\d{4}\/\d{2}\/\d{2})/';
    private const CONTAINER_DATE_REGEX_TEMPLATE = '/^%s-(\d{4})-(\d{2})-(\d{2})$/';
    private const AUTHOR_REGEX = '/by (.*?) for/';

    private const CSS = [
        'image' => 'display: block; max-width: 500px; height: auto; margin: 10px 0;',
    ];

    public function collectData()
    {
        $comicName = (string)$this->getInput('comicname');
        $limit = (int)($this->getInput('limit') ?? 2);

        $link = $this->getURI();
        $landingPage = $this->fetchPage($link);

        $link = $this->findComicLink($landingPage, $link, $comicName);

        for ($i = 0; $i < $limit; $i++) {
            $html = $this->fetchPage($link);

            $ogImage = $html->querySelector('meta[property="og:image"]');
            if ($ogImage === null) {
                break;
            }

            $imagelink = $ogImage->getAttribute('content') ?? '';
            if ($imagelink === '') {
                break;
            }

            $ogTitle = $html->querySelector('meta[property="og:title"]');
            $ogTitleContent = '';
            if ($ogTitle !== null) {
                $ogTitleContent = $ogTitle->getAttribute('content') ?? '';
            }

            $author = 'GoComics';
            if (preg_match(self::AUTHOR_REGEX, $ogTitleContent, $authorMatches) === 1) {
                if (isset($authorMatches[1]) === true && $authorMatches[1] !== '') {
                    $author = $authorMatches[1];
                }
            }

            $itemTitle = 'GoComics ' . $comicName;
            if ($this->getInput('date-in-title') === true && $ogTitleContent !== '') {
                $itemTitle = $ogTitleContent;
            }

            $item = [];
            $item['uid'] = $imagelink;
            $item['uri'] = $link;
            $item['author'] = $author;
            $item['title'] = $itemTitle;
            $item['content'] = '<img src="' . e($imagelink) . '" style="' . self::CSS['image'] . '" />';

            $timestamp = $this->extractDateFromUrl($link);
            if ($timestamp !== null) {
                $item['timestamp'] = $timestamp;
            }

            $this->items[] = $item;

            $previousButton = $html->querySelector('a[class*="__controls__button_previous"]');
            if ($previousButton === null) {
                break;
            }

            $previousHref = $previousButton->getAttribute('href') ?? '';
            if ($previousHref === '') {
                break;
            }

            $link = rtrim(self::URI, '/') . $previousHref;
        }
    }

    public function getURI()
    {
        $comicName = $this->getInput('comicname');
        if ($comicName !== null && $comicName !== '') {
            return self::URI . rawurlencode((string)$comicName);
        }

        return parent::getURI();
    }

    public function getName()
    {
        $comicName = $this->getInput('comicname');
        if ($comicName !== null && $comicName !== '') {
            return (string)$comicName . ' - GoComics';
        }

        return parent::getName();
    }

    private function fetchPage(string $url): \Dom\HTMLDocument
    {
        try {
            $html = getContents($url);
        } catch (\HttpException $e) {
            if ($e->getCode() === 403) {
                $message = '403 Forbidden. GoComics uses Bunny Shield to block this bridge. Try reducing feed update frequency or hosting from a different IP.';
                throw new \Exception($message, 403);
            }
            throw $e;
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        return $dom;
    }

    private function findComicLink(\Dom\HTMLDocument $dom, string $baseUrl, string $comicName): string
    {
        $element = $dom->querySelector('div[data-post-url]');
        if ($element !== null) {
            $postUrl = $element->getAttribute('data-post-url') ?? '';
            if ($postUrl !== '') {
                return $postUrl;
            }
        }

        $conversationNode = $dom->querySelector('vf-conversations');
        if ($conversationNode !== null) {
            $conversationId = $conversationNode->getAttribute('vf-container-id') ?? '';
            if ($conversationId !== '') {
                $pattern = sprintf(self::CONTAINER_DATE_REGEX_TEMPLATE, preg_quote($comicName, '/'));
                if (preg_match($pattern, $conversationId, $matches) === 1) {
                    return sprintf('%s/%s/%s/%s', $baseUrl, $matches[1], $matches[2], $matches[3]);
                }
            }
        }

        $prevButton = $dom->querySelector('a[class*="ComicNavigation-module-scss-module__"]');
        if ($prevButton === null) {
            throw new \Exception('Could not find the previous comic URL. Please create a new GitHub issue.');
        }

        $prevHref = $prevButton->getAttribute('href') ?? '';
        if ($prevHref === '') {
            throw new \Exception('Could not find the previous comic URL. Please create a new GitHub issue.');
        }

        if (preg_match(self::COMIC_DATE_REGEX, $prevHref, $matches) !== 1) {
            throw new \Exception('Could not parse the previous comic URL. Please create a new GitHub issue.');
        }

        $nextDate = new \DateTime($matches[1]);
        $nextDate->modify('+1 day');

        return $baseUrl . '/' . $nextDate->format('Y/m/d');
    }

    private function extractDateFromUrl(string $url): ?int
    {
        $parts = explode('/', $url);
        $dateParts = array_slice($parts, -3);

        if (count($dateParts) !== 3) {
            return null;
        }

        $dateString = implode('/', $dateParts);
        $date = \DateTime::createFromFormat('Y/m/d', $dateString);

        if ($date === false) {
            return null;
        }

        $date->setTime(0, 0, 0);
        return $date->getTimestamp();
    }
}
