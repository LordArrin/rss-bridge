<?php

declare(strict_types=1);

namespace RSSBridge\Formats;

/**
 * SfeedFormat - sfeed tab-separated format
 * https://codemadness.org/sfeed-simple-feed-parser.html
 */
final class SfeedFormat extends FormatAbstract
{
    public const MIME_TYPE = 'text/plain';

    public function getMimeType(): string
    {
        return self::MIME_TYPE;
    }

    public function render(): string
    {
        $text = '';
        
        foreach ($this->getItems() as $item) {
            $itemArray = $item->toArray();
            
            $timestamp = $itemArray['timestamp'] ?? '';
            $title = $this->escape((string) ($itemArray['title'] ?? ''));
            $uri = (string) ($itemArray['uri'] ?? '');
            $content = $this->escape((string) ($itemArray['content'] ?? ''));
            $author = (string) ($itemArray['author'] ?? '');
            $enclosure = $this->getFirstEnclosure($itemArray['enclosures'] ?? []);
            $categories = $this->escape($this->getCategories($itemArray['categories'] ?? []));

            $text .= sprintf(
                "%s\t%s\t%s\t%s\thtml\t\t%s\t%s\t%s\n",
                $timestamp,
                preg_replace('/\s+/', ' ', $title),
                $uri,
                $content,
                $author,
                $enclosure,
                $categories
            );
        }

        return $text;
    }

    private function escape(string $str): string
    {
        $str = str_replace('\\', '\\\\', $str);
        $str = str_replace("\n", '\\n', $str);
        return str_replace("\t", '\\t', $str);
    }

    private function getFirstEnclosure(array $enclosures): string
    {
        return $enclosures[0] ?? '';
    }

    private function getCategories(array $cats): string
    {
        return implode('|', array_map('trim', $cats));
    }
}
