<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class SubstackProfileBridge extends BridgeAbstract
{
    public const NAME = 'Substack Profile';
    public const URI = 'https://substack.com/';
    public const DESCRIPTION = 'Returns posts from profiles on Substack';
    public const MAINTAINER = 'No maintainer';

    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        '' => [
            'profile' => [
                'name' => 'Profile name to use',
                'exampleValue' => 'taliabhatt',
                'required' => true,
            ],
            'hide_tags' => [
                'name' => 'Hide tags',
                'type' => 'checkbox',
                'defaultValue' => false,
            ],
        ],
    ];

    private ?string $name = null;
    private ?string $icon = null;

    public function collectData(): void
    {
        $url = $this->getURI();
        $html = (string) getContents($url);

        if (preg_match('/<script [^>]*id="preloads-json"[^>]*>(.+?)<\/script>/', $html, $preg) !== 1) {
            throwServerException('Site structure has changed, please review');
        }

        $jsonString = $preg[1];
        $data = json_decode($jsonString, true);
        if (is_array($data) === false || isset($data['profile']) === false) {
            throwServerException('Failed to parse profile data');
        }
        $profile = $data['profile'];

        $this->name = (string) ($profile['name'] ?? '');
        $this->icon = (string) ($profile['photo_url'] ?? '');

        $id = (string) ($profile['id'] ?? '');
        if ($id === '') {
            throwServerException('Profile ID not found');
        }

        $feedUrl = self::URI . "api/v1/reader/feed/profile/$id";
        $feedJsonString = (string) getContents($feedUrl);
        $feedData = json_decode($feedJsonString, true);

        if (is_array($feedData) === false || isset($feedData['items']) === false || is_array($feedData['items']) === false) {
            throwServerException('Failed to parse feed data');
        }

        foreach ($feedData['items'] as $element) {
            if (is_array($element) === true) {
                $this->items[] = $this->processAttachment($element);
            }
        }
    }

    private function processAttachment(array $element): array
    {
        $item = [];
        $type = (string) ($element['type'] ?? '');
        $hideTags = (bool) $this->getInput('hide_tags');

        switch ($type) {
            case 'comment':
                $comment = $element['comment'] ?? [];
                if (is_array($comment) === false) {
                    $comment = [];
                }
                $item['author'] = (string) ($comment['name'] ?? $comment['user']['name'] ?? 'Anonymous');
                $item['content'] = '';
                if (isset($comment['body_json']) === true && is_array($comment['body_json']) === true) {
                    $item['content'] = $this->processBodyJson($comment['body_json']);
                }
                $timestampRaw = (string) ($comment['date'] ?? 'now');
                $timestamp = strtotime($timestampRaw);
                $item['timestamp'] = ($timestamp !== false) ? $timestamp : time();
                $item['title'] = 'Comment by ' . $item['author'];
                $itemId = (string) ($comment['id'] ?? '');
                $item['uri'] = $this->getURI() . '/note/c-' . $itemId;
                $element = $comment;
                break;
            case 'post':
                $item['content'] = (string) ($element['postSelection']['text'] ?? '');
                $post = $element['post'] ?? [];
                if (is_array($post) === false) {
                    $post = [];
                }
                $bylines = $post['publishedBylines'] ?? [];
                $item['author'] = (is_array($bylines) === true && isset($bylines[0]['name']) === true) ? (string) $bylines[0]['name'] : 'Unknown';

                $postId = (string) ($post['id'] ?? '');
                if ($postId !== '') {
                    $item['content'] .= $this->fetchPost($postId);
                }

                $postDateRaw = (string) ($post['post_date'] ?? 'now');
                $postTimestamp = strtotime($postDateRaw);
                $item['timestamp'] = ($postTimestamp !== false) ? $postTimestamp : time();
                $item['title'] = (string) ($post['title'] ?? 'Untitled');
                $item['uri'] = self::URI . 'home/post/p-' . $postId;
                $element = $post;
                break;
            case 'link':
                $linkMeta = $element['linkMetadata'] ?? [];
                if (is_array($linkMeta) === false) {
                    $linkMeta = [];
                }
                $item['author'] = (string) ($linkMeta['host'] ?? '');
                $item['content'] = (string) ($linkMeta['description'] ?? '');
                $item['title'] = (string) ($linkMeta['title'] ?? 'Link');
                $item['uri'] = (string) ($linkMeta['url'] ?? '');
                $element = $linkMeta;
                break;
            case 'image':
                $item['uri'] = (string) ($element['imageUrl'] ?? '');
                $item['title'] = 'Image';
                break;
            default:
                throwServerException('Invalid Substack entry type: ' . $type);
        }

        // Add media elements (audio, video, images) directly into the post content
        $item['content'] .= $this->buildMediaHtml($element);

        $item['categories'] = [];
        if ($hideTags === false) {
            $tags = $element['postTags'] ?? [];
            if (is_array($tags) === true) {
                $rawCategories = array_map(fn($tag) => (string) ($tag['name'] ?? ''), $tags);
                // Strict callback to satisfy PHPStan arrayFilter.strict rule
                $filteredCategories = array_filter($rawCategories, fn($v) => is_string($v) === true && $v !== '');
                $item['categories'] = array_values($filteredCategories);
            }
        }

        $item['comments'] = $item['uri'] . '/restacks/notes';

        if (isset($element['attachments']) === true && is_array($element['attachments']) === true) {
            foreach ($element['attachments'] as $attachment) {
                if (is_array($attachment) === true) {
                    $processedAttachment = $this->processAttachment($attachment);
                    if ($hideTags === false) {
                        $item['categories'] = array_merge($item['categories'], $processedAttachment['categories'] ?? []);
                    }
                    if (isset($processedAttachment['title']) === true) {
                        $item['content'] .= $this->quoteAttachment($processedAttachment);
                    }
                }
            }
        }

        return $item;
    }

    private function buildMediaHtml(array $element): string
    {
        $html = '';

        $audioItems = $element['audio_items'] ?? [];
        if (is_array($audioItems) === true) {
            foreach ($audioItems as $audio) {
                if (is_array($audio) === true && isset($audio['audio_url']) === true) {
                    $url = e((string) $audio['audio_url']);
                    $html .= '<audio controls src="' . $url . '"></audio><br>';
                }
            }
        }

        $videoUrl = $element['video_url'] ?? $element['video'] ?? null;
        if (is_string($videoUrl) === true && $videoUrl !== '') {
            $url = e($videoUrl);
            $html .= '<video controls src="' . $url . '" style="max-width:1200px; max-height:800px; width:auto; height:auto;"></video><br>';
        }

        if (isset($element['cover_image']) === true) {
            $url = e((string) $element['cover_image']);
            $html .= '<img src="' . $url . '" style="max-width:800px; max-height:800px; width:auto; height:auto;"><br>';
        }

        if (isset($element['image']) === true) {
            $url = e((string) $element['image']);
            $html .= '<img src="' . $url . '" style="max-width:800px; max-height:800px; width:auto; height:auto;"><br>';
        }

        if (isset($element['imageUrl']) === true) {
            $url = e((string) $element['imageUrl']);
            $html .= '<img src="' . $url . '" style="max-width:800px; max-height:800px; width:auto; height:auto;"><br>';
        }

        return $html;
    }

    private function fetchPost(string $id): string
    {
        $url = self::URI . "api/v1/posts/by-id/$id";
        $jsonString = (string) getContents($url);
        $data = json_decode($jsonString, true);

        if (is_array($data) === false || isset($data['post']['body_html']) === false) {
            return '';
        }

        $bodyHtml = (string) $data['post']['body_html'];

        // Use native DOM to ensure valid HTML structure and auto-close open tags from the API
        try {
            $dom = \Dom\HTMLDocument::createFromString('<html><body><div id="content-wrapper">' . $bodyHtml . '</div></body></html>');
            $node = $dom->getElementById('content-wrapper');
            $cleanHtml = ($node instanceof \Dom\Element === true) ? $node->innerHTML : $bodyHtml;
        } catch (\Throwable $e) {
            $cleanHtml = $bodyHtml;
        }

        return '<hr><h4>Full text:</h4><div>' . $cleanHtml . '</div>';
    }

    private function quoteAttachment(array $attachment): string
    {
        $uri = e((string) ($attachment['uri'] ?? '#'));
        $title = e((string) ($attachment['title'] ?? 'Untitled'));

        $html = '<hr>';
        $html .= '<a href="' . $uri . '"><h3>' . $title . '</h3></a>';

        $content = (string) ($attachment['content'] ?? '');
        if ($content !== '') {
            $author = e((string) ($attachment['author'] ?? 'Unknown'));
            $html .= '<h4>Quoting ' . $author . ':</h4>';
            $html .= '<blockquote>' . $content . '</blockquote>';
        }

        return $html;
    }

    private function processBodyJson(array $json): string
    {
        $html = '';
        $contentBlocks = $json['content'] ?? [];
        if (is_array($contentBlocks) === false) {
            return '';
        }

        foreach ($contentBlocks as $block) {
            if (is_array($block) === false) {
                continue;
            }

            $content = '';
            if (isset($block['content']) === true && is_array($block['content']) === true) {
                $content = $this->processBodyJson($block);
            }

            $type = (string) ($block['type'] ?? '');

            switch ($type) {
                case 'blockquote':
                    $html .= '<blockquote>' . $content . '</blockquote>';
                    break;
                case 'paragraph':
                    $html .= '<p>' . $content . '</p>';
                    break;
                case 'text':
                    $text = e((string) ($block['text'] ?? ''));
                    if (isset($block['marks']) === true && is_array($block['marks']) === true) {
                        foreach ($block['marks'] as $mark) {
                            if (is_array($mark) === false) {
                                continue;
                            }
                            $markType = (string) ($mark['type'] ?? '');
                            switch ($markType) {
                                case 'bold':
                                    $text = '<strong>' . $text . '</strong>';
                                    break;
                                case 'italic':
                                    $text = '<em>' . $text . '</em>';
                                    break;
                                case 'link':
                                    $href = e((string) ($mark['attrs']['href'] ?? '#'));
                                    $text = '<a href="' . $href . '">' . $text . '</a>';
                                    break;
                                default:
                                    break;
                            }
                        }
                    }
                    $html .= $text;
                    break;
                case 'substack_mention':
                    $label = e((string) ($block['attrs']['label'] ?? ''));
                    $id = e((string) ($block['attrs']['id'] ?? ''));
                    $html .= '<a href="' . self::URI . 'profile/' . $id . '">' . $label . '</a>';
                    break;
                default:
                    break;
            }
        }
        return $html;
    }

    public function getName(): string
    {
        $name = parent::getName();
        if ($this->name !== null && $this->name !== '') {
            $name .= ' - ' . $this->name;
        }
        return $name;
    }

    public function getIcon(): string
    {
        if ($this->icon !== null && $this->icon !== '') {
            return $this->icon;
        }
        return parent::getIcon();
    }

    public function getURI(): string
    {
        $profile = (string) $this->getInput('profile');
        if ($profile !== '') {
            return self::URI . '@' . $profile;
        }
        return self::URI;
    }
}
