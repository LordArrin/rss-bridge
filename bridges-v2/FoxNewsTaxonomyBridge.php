<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use FeedExpander;

final class FoxNewsTaxonomyBridge extends FeedExpander
{
    public const NAME = 'Fox News Taxonomy Filter';
    public const URI = 'https://www.foxnews.com/';
    public const DESCRIPTION = 'Filters the Fox News latest feed by specific taxonomies using include/exclude lists';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        [
            'include' => [
                'name' => 'Include Taxonomies',
                'type' => 'text',
                'required' => false,
                'title' => 'Comma-separated taxonomies to KEEP',
                'defaultValue' => 'politics, world, us'
            ],
            'exclude' => [
                'name' => 'Exclude Taxonomies',
                'type' => 'text',
                'required' => false,
                'title' => 'Comma-separated taxonomies to REMOVE',
                'defaultValue' => 'deals, entertainment, sports'
            ]
        ]
    ];

    public function collectData(): void
    {
        $feedUrl = 'https://moxie.foxnews.com/google-publisher/latest.xml';

        $this->collectExpandableDatas($feedUrl);

        $includeInput = $this->getInput('include');
        $excludeInput = $this->getInput('exclude');

        $includeStr = null;
        if (is_string($includeInput) === true) {
            $includeStr = $includeInput;
        }
        $includes = $this->parseInputList($includeStr);

        $excludeStr = null;
        if (is_string($excludeInput) === true) {
            $excludeStr = $excludeInput;
        }
        $excludes = $this->parseInputList($excludeStr);

        $filteredItems = [];

        foreach ($this->items as $item) {
            if (is_array($item) === false) {
                continue;
            }

            $categories = $item['categories'] ?? [];
            if (is_array($categories) === false) {
                $categories = [];
            }

            $uri = strtolower((string) ($item['uri'] ?? ''));

            $shouldKeep = true;
            if (count($includes) > 0) {
                $shouldKeep = false;
                if ($this->matchesRules($categories, $uri, $includes) === true) {
                    $shouldKeep = true;
                }
            }

            if ($shouldKeep === true && count($excludes) > 0) {
                if ($this->matchesRules($categories, $uri, $excludes) === true) {
                    $shouldKeep = false;
                }
            }

            if ($shouldKeep === true) {
                unset($item['categories']);
                $filteredItems[] = $item;
            }
        }

        $this->items = $filteredItems;
    }

    private function parseInputList(?string $input): array
    {
        $inputValue = $input ?? '';
        if (trim($inputValue) === '') {
            return [];
        }

        $parts = explode(',', $inputValue);
        $cleanParts = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $cleanParts[] = strtolower($part);
            }
        }

        return $cleanParts;
    }

    private function matchesRules(array $categories, string $uri, array $rules): bool
    {
        foreach ($rules as $rule) {
            foreach ($categories as $category) {
                $category = strtolower(trim((string) $category));
                if ($category === $rule || str_starts_with($category, $rule . '/') === true) {
                    return true;
                }
                if ($category === 'fox-news/' . $rule || str_starts_with($category, 'fox-news/' . $rule . '/') === true) {
                    return true;
                }
            }

            $urlFragment = str_replace('fox-news/', '', $rule);
            if (str_contains($uri, '/' . $urlFragment . '/') === true || str_ends_with($uri, '/' . $urlFragment) === true) {
                return true;
            }
        }
        return false;
    }
}
