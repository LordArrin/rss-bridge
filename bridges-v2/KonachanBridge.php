<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

final class KonachanBridge extends MoebooruBase
{
    public const NAME = 'Konachan';
    public const URI = 'https://konachan.com/';
    public const DESCRIPTION = 'Returns latest posts from konachan.com';
    public const MAINTAINER = 'No maintainer';

    protected static function getBaseApiUrl(): string
    {
        return 'https://konachan.com/post.json';
    }

    protected static function getPostUrlTemplate(): string
    {
        return 'https://konachan.com/post/show/';
    }

    protected static function getBaseCategory(): string
    {
        return 'konachan.com';
    }
}
