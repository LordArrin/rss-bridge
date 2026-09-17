<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;
use RSSBridge\Http\HttpException;

final class WashingtonPostBridge extends BridgeAbstract
{
    public const NAME = 'Washington Post';
    public const URI = 'https://www.washingtonpost.com/';
    public const DESCRIPTION = 'Returns full articles from The Washington Post';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 1800;

    public const PARAMETERS = [
        [
            'category' => [
                'name' => 'Category',
                'type' => 'text',
                'required' => true,
                'exampleValue' => 'national/investigations',
                'title' => 'Category from the path of the URL. For example: national/investigations',
            ],
            'limit' => [
                'name' => 'Limit',
                'type' => 'number',
                'required' => true,
                'defaultValue' => 5,
                'exampleValue' => 5,
                'title' => 'Number of articles to fetch. Full text is downloaded for each article',
            ],
        ],
    ];

    public function getName(): string
    {
        $category = (string)$this->getInput('category');
        if ($category !== '') {
            $category = trim($category, '/');
            return 'The Washington Post - ' . $category;
        }
        return self::NAME;
    }

    public function getURI(): string
    {
        $category = (string)$this->getInput('category');
        if ($category !== '') {
            $category = trim($category, '/');
            return 'https://www.washingtonpost.com/' . $category . '/';
        }
        return self::URI;
    }

    public function collectData(): void
    {
        $category = trim((string)$this->getInput('category'), '/');
        $url = 'https://jsonapp1.washingtonpost.com/fusion_prod/v2/' . $category;

        $headers = [
            'User-Agent: Classic/6.70.0',
            'Accept: */*',
            'Connection: keep-alive',
        ];

        try {
            $response = getContents($url, $headers);
        } catch (\Exception $e) {
            throw new \Exception('Failed to fetch category feed: ' . $e->getMessage());
        }

        $listJson = json_decode($response, true);
        if ($listJson === null || isset($listJson['regions'][0]['items']) === false) {
            throw new \Exception('Invalid JSON response from Washington Post API');
        }

        $mains = array_filter($listJson['regions'][0]['items'], fn($item) => isset($item['items']) === true);
        $list = [];

        foreach ($mains as $main) {
            if (isset($main['items'][0]['items']) === false) {
                continue;
            }
            foreach ($main['items'][0]['items'] as $item) {
                if (isset($item['is_from_feed']) === true && $item['is_from_feed'] === true) {
                    $list[] = [
                        'id' => $item['id'] ?? uniqid(),
                        'title' => $item['headline']['text'] ?? 'Untitled',
                        'uri' => $item['link']['url'] ?? '',
                        'timestamp' => isset($item['link']['display_date']) === true ? strtotime((string)$item['link']['display_date']) : time(),
                        'content' => $item['blurbs']['items'][0]['text'] ?? '',
                        'uid' => $item['id'] ?? uniqid(),
                    ];
                }
            }
        }

        // Remove duplicates by ID
        $uniqueList = [];
        $seenIds = [];
        foreach ($list as $item) {
            if (in_array($item['id'], $seenIds, true) === false) {
                $uniqueList[] = $item;
                $seenIds[] = $item['id'];
            }
        }

        // Apply user-defined limit to avoid excessive requests to rainbow API
        $limit = (int)$this->getInput('limit');
        if ($limit > 0 && count($uniqueList) > $limit) {
            $uniqueList = array_slice($uniqueList, 0, $limit);
        }

        foreach ($uniqueList as $item) {
            if (($item['uri'] ?? '') === '') {
                $this->items[] = $item;
                continue;
            }

            $contentUrl = 'https://rainbowapi-a.wpdigital.net/rainbow-data-service/rainbow/content-by-url.json?followLinks=false&url=' . urlencode($item['uri']);

            try {
                $contentResponse = getContents($contentUrl, $headers);
                $contentJson = json_decode($contentResponse, true);

                if ($contentJson !== null) {
                    $item['title'] = $contentJson['title'] ?? $item['title'];

                    // Extract authors
                    $authors = [];
                    if (isset($contentJson['items']) === true && is_array($contentJson['items']) === true) {
                        foreach ($contentJson['items'] as $entry) {
                            if (($entry['type'] ?? '') === 'byline' && isset($entry['authors']) === true) {
                                foreach ($entry['authors'] as $author) {
                                    if (isset($author['name']) === true) {
                                        $authors[] = $author['name'];
                                    }
                                }
                            }
                        }
                    }
                    if (count($authors) > 0) {
                        $item['author'] = implode(', ', $authors);
                    }

                    // Render full HTML content
                    if (isset($contentJson['items']) === true) {
                        $item['content'] = $this->renderDescription($contentJson['items']);
                    }
                }
            } catch (\Exception $e) {
                // Keep original blurb if content fetch fails
                // (e.g., HTTP 415 Unsupported Media Type for podcasts/interactive)
                if (($e instanceof HttpException) === true && $e->getCode() === 415) {
                    // Do nothing, keep $item['content'] as short blurb
                }
            }

            $this->items[] = $item;
        }
    }

    /**
     * Renders article JSON structure into HTML
     */
    private function renderDescription(array $content): string
    {
        $html = '';

        foreach ($content as $entry) {
            if ($entry === null) {
                continue;
            }

            $type = $entry['type'] ?? '';
            $subtype = $entry['subtype'] ?? '';
            $mime = $entry['mime'] ?? '';
            $contentStr = $entry['content'] ?? '';
            $isHtml = ($mime === 'text/html');

            // Escape content unless API explicitly marks it as safe HTML
            $safeContent = $isHtml === true ? $contentStr : htmlspecialchars((string)$contentStr);

            if ($type === 'title' && $subtype !== 'h1') {
                $tag = $subtype !== '' ? $subtype : 'h2';
                $html .= '<' . $tag . '>' . $safeContent . '</' . $tag . '>';
            } elseif ($type === 'sanitized_html') {
                if ($subtype === 'paragraph') {
                    $oembed = '';
                    if (isset($entry['oembed']) === true) {
                        $oembed = $isHtml === true ? $entry['oembed'] : htmlspecialchars((string)$entry['oembed']);
                    }
                    $html .= '<p>' . $safeContent . $oembed . '</p>';
                } elseif ($subtype === 'subhead') {
                    $level = (int)($entry['subhead_level'] ?? 4);
                    $html .= '<h' . $level . '>' . $safeContent . '</h' . $level . '>';
                }
            } elseif ($type === 'deck') {
                $html .= '<blockquote><p>' . $safeContent . '</p></blockquote>';
            } elseif ($type === 'image') {
                $imgUrl = htmlspecialchars((string)($entry['imageURL'] ?? ''));
                $alt = htmlspecialchars((string)($entry['blurb'] ?? ''));
                $caption = htmlspecialchars((string)($entry['fullcaption'] ?? ''));
                $html .= '<figure><img src="' . $imgUrl . '" alt="' . $alt . '" />';
                if ($caption !== '') {
                    $html .= '<figcaption>' . $caption . '</figcaption>';
                }
                $html .= '</figure>';
            } elseif ($type === 'video') {
                if (isset($entry['content']['html']) === true) {
                    // Trust API embed code
                    $html .= $entry['content']['html'];
                } elseif (isset($entry['mediaURL']) === true) {
                    $mediaUrl = htmlspecialchars((string)$entry['mediaURL']);
                    $poster = htmlspecialchars((string)($entry['imageURL'] ?? ''));
                    $caption = htmlspecialchars((string)($entry['fullcaption'] ?? ''));
                    $html .= '<figure><video controls poster="' . $poster . '"><source src="' . $mediaUrl . '" /></video>';
                    if ($caption !== '') {
                        $html .= '<figcaption>' . $caption . '</figcaption>';
                    }
                    $html .= '</figure>';
                }
            } elseif ($type === 'list') {
                $tag = ($subtype === 'ordered') ? 'ol' : 'ul';
                $html .= '<' . $tag . '>';
                foreach ($entry['content'] ?? [] as $listItem) {
                    $safeListItem = ($entry['mime'] ?? '') === 'text/html' ? $listItem : htmlspecialchars((string)$listItem);
                    $html .= '<li>' . $safeListItem . '</li>';
                }
                $html .= '</' . $tag . '>';
            } elseif ($type === 'divider') {
                $html .= '<br><hr><br>';
            } elseif ($type === 'byline' && ($subtype === 'live-update' || $subtype === 'live-reporter-insight')) {
                $html .= '<p><i>' . $safeContent . '</i></p>';
            } elseif ($type === 'date' && $subtype === 'live-update') {
                if (($entry['content'] ?? '') !== '') {
                    try {
                        $dt = new \DateTime((string)$entry['content'], new \DateTimeZone('America/New_York'));
                        $formatted = $dt->format('l, F j, Y g:i A T');
                        $html .= '<span>' . $formatted . '</span>';
                    } catch (\Exception $e) {
                        $html .= '<span>' . htmlspecialchars((string)$entry['content']) . '</span>';
                    }
                }
            }
        }

        return $html;
    }
}
