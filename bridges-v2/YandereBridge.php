<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

final class YandereBridge extends MoebooruBase
{
    public const NAME = 'Yande.re';
    public const URI = 'https://yande.re/';
    public const DESCRIPTION = 'Returns latest posts from yande.re';
    public const MAINTAINER = 'No maintainer';

    protected static function getBaseApiUrl(): string
    {
        return 'https://yande.re/post.json';
    }

    protected static function getPostUrlTemplate(): string
    {
        return 'https://yande.re/post/show/';
    }

    protected static function getBaseCategory(): string
    {
        return 'yande.re';
    }
}
