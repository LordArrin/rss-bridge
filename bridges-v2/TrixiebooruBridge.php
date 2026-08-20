<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

final class TrixiebooruBridge extends PhilomenaBase
{
    public const NAME = 'Trixiebooru';
    public const URI = 'https://trixiebooru.org/';
    public const DESCRIPTION = 'Returns images and videos from Trixiebooru search';
    public const MAINTAINER = 'LordArrin';
    public const CACHE_TIMEOUT = 1800;

    protected static function getAvailableFilters(): array
    {
        return [
            'Everything (No limits, shows ALL)' => 56027,
            '18+ R34 (Explicit allowed, hides gore/AI)' => 37432,
            '18+ Dark (Gore/grimdark allowed, hides explicit)' => 37429,
            'Legacy Default (Old safe mode, hides explicit)' => 37431,
            'Default (Modern safe, hides non-art & adult)' => 100073
        ];
    }

    protected static function getDefaultFilterId(): int
    {
        return 56027;
    }
}
