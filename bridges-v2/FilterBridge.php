<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use FeedExpander;

final class FilterBridge extends FeedExpander
{
    public const NAME = 'Filter';
    public const DESCRIPTION = 'Filters a feed of your choice';
    public const URI = 'https://github.com/RSS-Bridge/rss-bridge';
    public const MAINTAINER = 'no maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [[
        'url' => [
            'name' => 'Feed URL',
            'type'  => 'text',
            'exampleValue' => 'https://lorem-rss.herokuapp.com/feed?unit=day',
            'required' => true,
        ],
        'name' => [
            'name'          => 'Feed name (optional)',
            'type'          => 'text',
            'exampleValue'  => 'My feed',
            'required'      => false,
        ],
        'filter' => [
            'name' => 'Filter (regular expression!!!)',
            'required' => false,
        ],
        'filter_type' => [
            'name' => 'Filter type',
            'type' => 'list',
            'required' => false,
            'values' => [
                'Keep matching items' => 'permit',
                'Hide matching items' => 'block',
            ],
            'defaultValue' => 'permit',
        ],
        'case_insensitive' => [
            'name' => 'Case-insensitive filter',
            'type' => 'checkbox',
            'required' => false,
        ],
        'fix_encoding' => [
            'name' => 'Attempt Latin1/UTF-8 fixes when evaluating filter',
            'type' => 'checkbox',
            'required' => false,
        ],
        'target_author' => [
            'name' => 'Apply filter on author',
            'type' => 'checkbox',
            'required' => false,
        ],
        'target_content' => [
            'name' => 'Apply filter on content',
            'type' => 'checkbox',
            'required' => false,
        ],
        'target_title' => [
            'name' => 'Apply filter on title',
            'type' => 'checkbox',
            'required' => false,
            'defaultValue' => 'checked'
        ],
        'target_uri' => [
            'name' => 'Apply filter on URI/URL',
            'type' => 'checkbox',
            'required' => false,
        ],
        'title_from_content' => [
            'name' => 'Generate title from content (overwrite existing title)',
            'type' => 'checkbox',
            'required' => false,
        ],
        'length_limit' => [
            'name' => 'Max length analyzed by filter (-1: no limit)',
            'type' => 'number',
            'required' => false,
            'defaultValue' => -1,
        ],
    ]];

    public function collectData(): void
    {
        $url = (string)$this->getInput('url');
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throwClientException('The url parameter must refer to a valid http or https URL.');
        }
        $this->collectExpandableDatas($this->getURI());
    }

    protected function parseItem($item): ?array
    {
        if ($this->getInput('title_from_content') === true && array_key_exists('content', $item) === true) {
            libxml_use_internal_errors(true);
            $dom = \Dom\HTMLDocument::createFromString((string)$item['content']);
            libxml_use_internal_errors(false);

            $plaintext = $dom->textContent ?? '';
            if (mb_strlen($plaintext) < 51) {
                $item['title'] = $plaintext;
            } else {
                $pos = strpos((string)$item['content'], ' ', 50);
                if ($pos === false) {
                    $item['title'] = mb_substr($plaintext, 0, 50);
                } else {
                    $item['title'] = mb_substr($plaintext, 0, $pos);
                    if (mb_strlen($plaintext) >= $pos) {
                        $item['title'] .= '...';
                    }
                }
            }
        }

        $filter = (string)($this->getInput('filter') ?? '');
        if ($filter === '') {
            return $item;
        }

        if (str_contains($filter, '#') === false) {
            $delimiter = '#';
        } elseif (str_contains($filter, '/') === false) {
            $delimiter = '/';
        } else {
            throwClientException('Cannot use both / and # inside filter');
        }

        $regex = $delimiter . $filter . $delimiter;
        if ($this->getInput('case_insensitive') === true) {
            $regex .= 'i';
        }

        $filter_fields = [];
        if ($this->getInput('target_author') === true) {
            $filter_fields[] = (string)($item['author'] ?? '');
        }
        if ($this->getInput('target_content') === true) {
            $filter_fields[] = (string)($item['content'] ?? '');
        }
        if ($this->getInput('target_title') === true) {
            $filter_fields[] = (string)($item['title'] ?? '');
        }
        if ($this->getInput('target_uri') === true) {
            $filter_fields[] = (string)($item['uri'] ?? '');
        }

        $keep_item = false;
        $length_limit = (int)$this->getInput('length_limit');

        foreach ($filter_fields as $field) {
            if ($length_limit > 0) {
                $field = substr($field, 0, $length_limit);
            }

            $result = @preg_match($regex, $field);
            if ($result === 1) {
                $keep_item = true;
            }

            if ($this->getInput('fix_encoding') === true) {
                $latin1 = mb_convert_encoding($field, 'ISO-8859-1', 'UTF-8');
                $utf8 = mb_convert_encoding($field, 'UTF-8', 'ISO-8859-1');

                if (@preg_match($regex, $latin1) === 1) {
                    $keep_item = true;
                }
                if (@preg_match($regex, $utf8) === 1) {
                    $keep_item = true;
                }
            }

            if ($keep_item === true) {
                break;
            }
        }

        if ($this->getInput('filter_type') === 'block') {
            $keep_item = $keep_item === false;
        }

        if ($keep_item === true) {
            return $item;
        }

        return null;
    }

    public function getURI(): string
    {
        $url = $this->getInput('url');
        if ($url !== null && $url !== '') {
            return (string)$url;
        }
        return parent::getURI();
    }

    public function getName(): string
    {
        $name = $this->getInput('name');
        if ($name !== null && $name !== '') {
            return (string)$name;
        }
        return parent::getName();
    }
}
