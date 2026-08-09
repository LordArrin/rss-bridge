<?php

declare(strict_types=1);

class FreeNginxBridge extends NginxBase
{
    const NAME = 'FreeNginx';
    const URI = 'https://freenginx.org/';
    const DESCRIPTION = 'Returns FreeNginx releases with changelogs and other news';
    const MAINTAINER = 'LordArrin';

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
