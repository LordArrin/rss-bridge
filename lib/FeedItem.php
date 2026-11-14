<?php

declare(strict_types=1);

namespace RSSBridge;

class FeedItem
{
    /**
     * Maximum accepted length of a data: URI enclosure (base64 text).
     * 2 MiB of text is roughly 1.5 MiB of binary payload.
     */
    private const MAX_DATA_URI_LENGTH = 2097152;

    /**
     * Only base64-encoded raster image data URIs are accepted as enclosures.
     * SVG is excluded (scriptable) and the strict base64 charset check prevents
     * smuggled markup. /D so a trailing newline cannot bypass the anchor.
     */
    private const DATA_URI_IMAGE_PATTERN = '/^data:image\/(?:png|jpe?g|gif|webp|avif);base64,[A-Za-z0-9+\/]+={0,2}$/D';

    protected ?string $uri = null;
    protected ?string $title = null;
    protected ?int $timestamp = null;
    protected ?string $author = null;
    protected ?string $content = null;

    /**
     * @var list<string>
     */
    protected array $enclosures = [];

    /**
     * @var list<string>
     */
    protected array $categories = [];

    protected ?string $uid = null;

    /**
     * @var array<string, mixed>
     */
    protected array $misc = [];

    /**
     * @param array<string, mixed> $itemArray
     */
    public static function fromArray(array $itemArray): self
    {
        $item = new self();
        foreach ($itemArray as $key => $value) {
            // __set() is typed string; under strict_types an int key would throw
            // a TypeError. Numeric keys carry no meaning for feed items — skip.
            if (is_string($key) === false) {
                continue;
            }
            $item->__set($key, $value);
        }
        return $item;
    }

    private function __construct()
    {
    }

    public function __set(string $name, mixed $value): void
    {
        switch ($name) {
            case 'uri':
                $this->setURI($value);
                break;
            case 'title':
                $this->setTitle($value);
                break;
            case 'timestamp':
                $this->setTimestamp($value);
                break;
            case 'author':
                $this->setAuthor($value);
                break;
            case 'content':
                $this->setContent($value);
                break;
            case 'enclosures':
                $this->setEnclosures($value);
                break;
            case 'categories':
                $this->setCategories($value);
                break;
            case 'uid':
                $this->setUid($value);
                break;
            default:
                $this->addMisc($name, $value);
        }
    }

    public function __get(string $name): mixed
    {
        switch ($name) {
            case 'uri':
                return $this->getURI();
            case 'title':
                return $this->getTitle();
            case 'timestamp':
                return $this->getTimestamp();
            case 'author':
                return $this->getAuthor();
            case 'content':
                return $this->getContent();
            case 'enclosures':
                return $this->getEnclosures();
            case 'categories':
                return $this->getCategories();
            case 'uid':
                return $this->getUid();
            default:
                if (array_key_exists($name, $this->misc) === true) {
                    return $this->misc[$name];
                }
                return null;
        }
    }

    public function getURI(): ?string
    {
        return $this->uri;
    }

    public function setURI(mixed $uri): void
    {
        $this->uri = null;

        if ($uri instanceof \Dom\Element) {
            if ($uri->hasAttribute('href') === true) { // Anchor
                $uri = $uri->getAttribute('href');
            } elseif ($uri->hasAttribute('src') === true) { // Image
                $uri = $uri->getAttribute('src');
            }
        }
        if (is_string($uri) === false) {
            return;
        }
        $uri = trim($uri);
        // Intentionally doing a weak url validation here because FILTER_VALIDATE_URL is too strict
        if (preg_match('#^https?://#i', $uri) !== 1) {
            return;
        }
        $this->uri = $uri;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(mixed $title): void
    {
        $this->title = null;
        if (is_string($title) === true) {
            // No truncation here: upstream kept full titles, and display-level
            // shortening belongs to the formats, not the data model.
            $this->title = trim($title);
        }
    }

    public function getTimestamp(): ?int
    {
        return $this->timestamp;
    }

    public function setTimestamp(mixed $datetime): void
    {
        $this->timestamp = null;

        if ($datetime === null) {
            return;
        }

        if (is_numeric($datetime) === true) {
            $timestamp = (int) $datetime;
        } elseif (is_string($datetime) === true) {
            $timestamp = strtotime($datetime);
            if ($timestamp === false) {
                return;
            }
        } else {
            return;
        }

        // Accept pre-1970 (negative) dates; keep 0 as the "unknown" sentinel.
        if ($timestamp !== 0) {
            $this->timestamp = $timestamp;
        }
    }

    public function getAuthor(): ?string
    {
        return $this->author;
    }

    public function setAuthor(mixed $author): void
    {
        $this->author = null;
        if (is_string($author) === true) {
            $this->author = $author;
        }
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(mixed $content): void
    {
        $this->content = null;

        if ($content instanceof \Dom\HTMLDocument) {
            $content = $content->saveHtml();
        } elseif ($content instanceof \Dom\Element) {
            $content = $content->outerHTML;
        }

        if (is_string($content) === true) {
            $this->content = $content;
        }
    }

    /**
     * @return list<string>
     */
    public function getEnclosures(): array
    {
        return $this->enclosures;
    }

    public function setEnclosures(mixed $enclosures): void
    {
        $this->enclosures = [];

        if (is_array($enclosures) === false) {
            return;
        }
        foreach ($enclosures as $enclosure) {
            if (is_string($enclosure) === false) {
                continue;
            }

            if (str_starts_with($enclosure, 'data:') === true) {
                // data: URIs are only accepted for base64-encoded raster images,
                // with a hard size cap. Anything else (text/html, svg+xml, ...)
                // is rejected to avoid scriptable content and feed bloat.
                if (strlen($enclosure) > self::MAX_DATA_URI_LENGTH) {
                    continue;
                }
                if (preg_match(self::DATA_URI_IMAGE_PATTERN, $enclosure) !== 1) {
                    continue;
                }
            } else {
                // Weak scheme check (same as setURI): FILTER_VALIDATE_URL silently
                // dropped valid IDN/unicode/space-containing URLs. Relative URLs
                // are still rejected — formats do not absolutize enclosures.
                $enclosure = trim($enclosure);
                if (preg_match('#^https?://#i', $enclosure) !== 1) {
                    continue;
                }
            }

            if (in_array($enclosure, $this->enclosures, true) === false) {
                $this->enclosures[] = $enclosure;
            }
        }
    }

    /**
     * @return list<string>
     */
    public function getCategories(): array
    {
        return $this->categories;
    }

    public function setCategories(mixed $categories): void
    {
        $this->categories = [];

        if (is_array($categories) === false) {
            return;
        }
        foreach ($categories as $category) {
            if (is_string($category) === true) {
                $this->categories[] = $category;
            }
        }
    }

    public function getUid(): ?string
    {
        return $this->uid;
    }

    public function setUid(mixed $uid): void
    {
        $this->uid = null;
        if (is_string($uid) === false) {
            return;
        }
        if (preg_match('/^[a-f0-9]{40}$/', $uid) === 1) {
            // Preserve an already-hashed sha1 (lowercase) as-is. Note: migrating
            // from the old "sha1 everything" behavior changes such UIDs once,
            // which can surface a one-time batch of duplicates in readers.
            $this->uid = $uid;
        } else {
            $this->uid = sha1($uid);
        }
    }

    public function addMisc(string $name, mixed $value): void
    {
        $this->misc[$name] = $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge(
            [
                'uri' => $this->uri,
                'title' => $this->title,
                'timestamp' => $this->timestamp,
                'author' => $this->author,
                'content' => $this->content,
                'enclosures' => $this->enclosures,
                'categories' => $this->categories,
                'uid' => $this->uid,
            ],
            $this->misc
        );
    }
}
