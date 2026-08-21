<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class SponsrBridge extends BridgeAbstract
{
    public const NAME = 'Sponsr';
    public const URI = 'https://sponsr.ru/';
    public const DESCRIPTION = 'Returns posts from Sponsr (free posts and paid announcements). No auth required';
    public const MAINTAINER = 'LordArrin';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        [
            'author_url' => [
                'name' => 'Author URL or ID',
                'type' => 'text',
                'required' => true,
                'title' => 'Channel name, for example, rebel_jack from https://sponsr.ru/rebel_jack, or full URL',
                'exampleValue' => 'rebel_jack'
            ]
        ]
    ];

    private string $feedTitle = '';
    private string $feedIcon = '';
    private string $authorSlug = '';

    public function collectData(): void
    {
        $input = $this->getInput('author_url');

        if ($input === null || $input === '') {
            throwClientException('Author URL or ID is required');
        }

        $authorSlug = $this->normalizeAuthorSlug((string)$input);
        if ($authorSlug === '') {
            throwClientException('Invalid author URL or ID format');
        }

        $this->authorSlug = $authorSlug;
        $authorUrl = 'https://sponsr.ru/' . $authorSlug . '/';

        $html = $this->fetchHtml($authorUrl);

        $nextDataScript = $html->getElementById('__NEXT_DATA__');

        if ($nextDataScript === null) {
            throwServerException('Could not find __NEXT_DATA__ script');
        }

        $jsonData = json_decode($nextDataScript->textContent, true);

        if ($jsonData === null || isset($jsonData['props']['pageProps']['posts']['list']) === false) {
            throwServerException('Could not parse post data');
        }

        $posts = $jsonData['props']['pageProps']['posts']['list'];
        $project = $jsonData['props']['pageProps']['project'];

        $this->feedTitle = $project['project_title'] ?? 'Sponsr.ru';

        $avatarImg = $html->querySelector('.ant-avatar-image img');
        if ($avatarImg !== null) {
            $this->feedIcon = $avatarImg->getAttribute('src');
        }

        $listItems = $html->querySelectorAll('li[class*="index_listItem"]');

        foreach ($posts as $post) {
            $postId = $post['id'];
            $postTitle = $post['title'];
            $postDate = $post['date'];
            $postImage = $post['image'] ?? null;
            $postDuration = $post['duration_video'] ?? 0;
            $isAvailable = $post['available'] ?? false;

            $postUrl = $authorUrl . $postId . '/';
            $timestamp = strtotime($postDate);

            $content = '';

            if ($postImage !== null) {
                $imageUrl = 'https://media.sponsr.ru' . $postImage;
                $content .= '<p><img src="' . htmlspecialchars($imageUrl) . '" alt="' . htmlspecialchars($postTitle) . '" /></p>';
            }

            if ($postDuration > 0) {
                $hours = floor($postDuration / 3600);
                $minutes = floor(($postDuration % 3600) / 60);
                $durationText = '';
                if ($hours > 0) {
                    $durationText .= $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ';
                }
                $durationText .= $minutes . ' minute' . ($minutes !== 1 ? 's' : '');
                $content .= '<p><strong>Duration:</strong> ' . htmlspecialchars($durationText) . '</p>';
            }

            if ($isAvailable === false) {
                $levelName = '';
                $levelPrice = '';

                foreach ($listItems as $listItem) {
                    $article = $listItem->querySelector('article');
                    if ($article !== null && $article->getAttribute('id') === 'feed-post-' . $postId) {
                        $levelChip = $listItem->querySelector('[class*="index_levelChipWrapper"]');
                        if ($levelChip !== null) {
                            $levelName = trim($levelChip->textContent);
                        }

                        $paywallBtn = $listItem->querySelector('[class*="postPaywall_subscribeBtn"]');
                        if ($paywallBtn !== null) {
                            $btnText = $paywallBtn->textContent;
                            if (preg_match('/(\d+)\s*?/', $btnText, $matches) === 1) {
                                $levelPrice = $matches[1];
                            }
                        }
                        break;
                    }
                }

                if ($levelName !== '' || $levelPrice !== '') {
                    $content .= '<p><strong>This post requires payment</strong></p>';
                    if ($levelName !== '') {
                        $content .= '<p>Subscription: ' . htmlspecialchars($levelName) . '</p>';
                    }
                    if ($levelPrice !== '') {
                        $content .= '<p>Price: ' . htmlspecialchars($levelPrice) . ' RUB/month</p>';
                    }
                }
            }

            $this->items[] = [
                'title' => $postTitle,
                'uri' => $postUrl,
                'content' => $content,
                'timestamp' => $timestamp,
                'uid' => 'sponsr-' . $postId
            ];
        }
    }

    private function normalizeAuthorSlug(string $input): string
    {
        $input = trim($input);

        if (preg_match('#^[a-zA-Z0-9_]+$#', $input) === 1) {
            return $input;
        }

        if (preg_match('#^https?://sponsr\.ru/([a-zA-Z0-9_]+)(?:/.*)?$#', $input, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }

    public function getName(): string
    {
        if ($this->feedTitle !== '') {
            return $this->feedTitle;
        }
        return self::NAME;
    }

    public function getURI(): string
    {
        if ($this->authorSlug !== '') {
            return 'https://sponsr.ru/' . $this->authorSlug . '/';
        }
        return self::URI;
    }

    public function getIcon(): string
    {
        if ($this->feedIcon !== '') {
            return $this->feedIcon;
        }
        return parent::getIcon();
    }

    private function fetchHtml(string $url): \Dom\HTMLDocument
    {
        $html = getContents($url);
        if ($html === '') {
            throwServerException("Failed to fetch {$url}");
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        return $dom;
    }
}
