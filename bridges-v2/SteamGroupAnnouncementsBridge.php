<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use FeedExpander;

final class SteamGroupAnnouncementsBridge extends FeedExpander
{
    public const NAME = 'Steam Group Announcements';
    public const URI = 'https://steamcommunity.com/';
    public const DESCRIPTION = 'Returns latest announcements from a steam group';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        [
            'g' => [
                'name' => 'Group name',
                'exampleValue' => 'freegamesfinders',
                'required' => true
            ]
        ]
    ];

    public function collectData(): void
    {
        $uri = self::URI . 'groups/' . $this->getInput('g') . '/rss';
        $this->collectExpandableDatas($uri, 10);
    }
}
