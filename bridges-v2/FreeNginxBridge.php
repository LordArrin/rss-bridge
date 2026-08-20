<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

final class FreeNginxBridge extends NginxBase
{
    public const NAME = 'FreeNginx';
    public const URI = 'https://freenginx.org/';
    public const DESCRIPTION = 'Returns FreeNginx releases with changelogs and other news';
    public const MAINTAINER = 'LordArrin';

    protected function getSoftwareName(): string
    {
        return 'freenginx';
    }

    protected function getNewsUrl(): string
    {
        return 'https://freenginx.org/';
    }

    protected function getChangesUrl(): string
    {
        return 'https://freenginx.org/en/CHANGES';
    }

    protected function getTitlePrefix(): string
    {
        return 'freenginx';
    }

    protected function getUidPrefix(): string
    {
        return 'freenginx';
    }

    public function getName(): string
    {
        return $this->getInput('source') === 'news' ? 'Freenginx News' : 'Freenginx Releases';
    }

    public function getURI(): string
    {
        return 'https://freenginx.org/';
    }
}
