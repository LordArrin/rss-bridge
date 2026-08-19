<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

/**
 * Example PSR-4 bridge with modern structure.
 * 
 * Key differences from legacy bridges:
 * - Uses namespace RSSBridge\Bridges
 * - declare(strict_types=1) at the top
 * - Type hints for all parameters and return types
 * - Can use modern PHP 8.5 features
 */
final class ExampleBridge extends \BridgeAbstract
{
    const NAME = 'Example Site';
    const URI = 'https://example.com';
    const DESCRIPTION = 'Returns posts from Example Site';
    const MAINTAINER = 'YourName';
    const CACHE_TIMEOUT = 3600;

    public function getParameters(): array
    {
        return [
            'User Posts' => [
                'username' => [
                    'name' => 'Username',
                    'type' => 'text',
                    'required' => true,
                    'exampleValue' => 'exampleuser',
                    'title' => 'The username to fetch posts from',
                ],
                'limit' => [
                    'name' => 'Limit',
                    'type' => 'number',
                    'defaultValue' => 10,
                    'title' => 'Maximum number of posts to return',
                ],
            ],
            'global' => [
                'filter' => [
                    'name' => 'Filter by keyword',
                    'type' => 'text',
                    'required' => false,
                    'title' => 'Only return posts containing this keyword',
                ],
            ],
        ];
    }

    public function collectData(): void
    {
        $username = $this->getInput('username');
        $limit = (int) $this->getInput('limit');
        $filter = $this->getInput('filter');

        // Your data collection logic here
        $html = getSimpleHTMLDOM(sprintf('%s/user/%s', self::URI, $username));
        
        $count = 0;
        foreach ($html->find('article.post') as $element) {
            if ($count >= $limit) {
                break;
            }

            $title = $element->find('h2', 0)->plaintext ?? '';
            $content = $element->find('.content', 0)->innertext ?? '';

            // Apply filter if specified
            if ($filter && stripos($content, $filter) === false) {
                continue;
            }

            $this->items[] = [
                'title' => $title,
                'uri' => $element->find('a', 0)->href ?? self::URI,
                'content' => $content,
                'timestamp' => strtotime($element->find('time', 0)->datetime ?? 'now'),
            ];

            $count++;
        }
    }
}