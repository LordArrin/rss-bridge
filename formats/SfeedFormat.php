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

            $timestamp = self::stringify($itemArray['timestamp'] ?? '');
            $title = $this->escape(self::stringify($itemArray['title'] ?? ''));
            $uri = self::stringify($itemArray['uri'] ?? '');
            $content = $this->escape(self::stringify($itemArray['content'] ?? ''));
            $author = self::stringify($itemArray['author'] ?? '');

            $rawEnclosures = $itemArray['enclosures'] ?? [];
            $enclosures = is_array($rawEnclosures) === true ? array_values(array_filter($rawEnclosures, fn($v) => is_string($v))) : [];
            $enclosure = $this->getFirstEnclosure($enclosures);

            $rawCategories = $itemArray['categories'] ?? [];
            $categories = is_array($rawCategories) === true ? array_values(array_filter($rawCategories, fn($v) => is_string($v))) : [];
            $categoriesStr = $this->escape($this->getCategories($categories));

            $titleNormalized = preg_replace('/\s+/', ' ', $title) ?? $title;

            $text .= sprintf(
                "%s\t%s\t%s\t%s\thtml\t\t%s\t%s\t%s\n",
                $timestamp,
                $titleNormalized,
                $uri,
                $content,
                $author,
                $enclosure,
                $categoriesStr
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

    /**
     * @param list<string> $enclosures
     */
    private function getFirstEnclosure(array $enclosures): string
    {
        return $enclosures[0] ?? '';
    }

    /**
     * @param list<string> $cats
     */
    private function getCategories(array $cats): string
    {
        return implode('|', array_map('trim', $cats));
    }

    private static function stringify(mixed $value): string
    {
        return is_scalar($value) === true ? (string) $value : '';
    }
}
