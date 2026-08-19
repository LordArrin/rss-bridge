<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

final class NginxBridge extends NginxBase
{
    const NAME = 'Nginx';
    const URI = 'https://nginx.org/';
    const DESCRIPTION = 'Returns Nginx releases with changelogs and other news';
    const MAINTAINER = 'LordArrin';

    protected function getSoftwareName(): string
    {
        return 'nginx';
    }

    protected function getNewsUrl(): string
    {
        return 'https://nginx.org/news.html';
    }

    protected function getChangesUrl(): string
    {
        return 'https://nginx.org/en/CHANGES';
    }

    protected function getTitlePrefix(): string
    {
        return 'NGINX';
    }

    protected function getUidPrefix(): string
    {
        return 'nginx';
    }

    public function getName(): string
    {
        return $this->getInput('source') === 'news' ? 'NGINX News' : 'NGINX Releases';
    }

    public function getURI(): string
    {
        return 'https://nginx.org/';
    }
}
