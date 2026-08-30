<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class FirefoxAddonsBridge extends BridgeAbstract
{
    public const NAME = 'Firefox Add-ons';
    public const URI = 'https://addons.mozilla.org/';
    public const DESCRIPTION = 'Returns version history for a Firefox Add-on';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        [
            'id' => [
                'name' => 'Add-on ID',
                'type' => 'text',
                'required' => true,
                'exampleValue' => 'ublock-origin',
            ]
        ]
    ];

    private const CSS = [
        'ul' => 'list-style-type:disc;margin:0.5em 0;padding-left:1.5em;',
        'li' => 'margin:0.5em 0;',
        'img' => 'max-width:100%;height:auto;',
    ];

    private const JUNK_SELECTORS = [
        'script',
        'style',
        'noscript',
        'iframe',
    ];

    private string $feedName = '';

    public function collectData(): void
    {
        $html = getContents($this->getURI());

        if (is_string($html) === false || $html === '') {
            \throwServerException('Empty response from add-on page');
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $titleNode = $dom->querySelector('h1[class="AddonTitle"] > a');
        if ($titleNode !== null) {
            $this->feedName = (string) $titleNode->textContent;
        }

        $authorNode = $dom->querySelector('span.AddonTitle-author > a');
        $author = ($authorNode !== null) ? (string) $authorNode->textContent : '';

        $versionCards = $dom->querySelectorAll('li.AddonVersionCard');
        foreach ($versionCards as $li) {
            if ($li instanceof \Dom\Element === false) {
                continue;
            }

            $item = [];

            $versionNode = $li->querySelector('h2.AddonVersionCard-version');
            $item['title'] = ($versionNode !== null) ? (string) $versionNode->textContent : '';
            $item['uid'] = $item['title'];
            $item['uri'] = $this->getURI();
            $item['author'] = $author;

            $fileInfoNode = $li->querySelector('div.AddonVersionCard-fileInfo');
            $size = '';
            if ($fileInfoNode !== null) {
                $fileInfoText = (string) $fileInfoNode->textContent;
                $releaseDateRegex = '/Released ([\w, ]+) - ([\w. ]+)/';
                if (preg_match($releaseDateRegex, $fileInfoText, $match) === 1) {
                    $timestamp = strtotime($match[1]);
                    if ($timestamp !== false) {
                        $item['timestamp'] = $timestamp;
                    }
                    $size = (string) $match[2];
                }
            }

            $compatNode = $li->querySelector('div.AddonVersionCard-compatibility');
            $compatibility = ($compatNode !== null) ? (string) $compatNode->textContent : '';

            $licenseNode = $li->querySelector('p.AddonVersionCard-license');
            $license = ($licenseNode !== null) ? (string) $licenseNode->innerHTML : '';

            $downloadlink = '';
            $downloadNode = $li->querySelector('a.InstallButtonWrapper-download-link');
            if ($downloadNode !== null) {
                $downloadlink = (string) ($downloadNode->getAttribute('href') ?? '');
            } else {
                $altDownloadNode = $li->querySelector('a.Button.Button--action.AMInstallButton-button.Button--puffy');
                if ($altDownloadNode !== null) {
                    $downloadlink = (string) ($altDownloadNode->getAttribute('href') ?? '');
                }
            }

            $releaseNotesNode = $li->querySelector('div.AddonVersionCard-releaseNotes');
            $releaseNotes = ($releaseNotesNode !== null) ? $this->removeLinkRedirects($releaseNotesNode) : '';

            $xpiFilename = '';
            $xpiFileRegex = '/([A-Za-z0-9_.-]+)\.xpi$/';
            if (preg_match($xpiFileRegex, $downloadlink, $match) === 1) {
                $xpiFilename = (string) $match[0];
            }

            $escapedCompatibility = htmlspecialchars($compatibility);
            $escapedDownloadlink = htmlspecialchars($downloadlink);
            $escapedXpiFilename = htmlspecialchars($xpiFilename);
            $escapedSize = htmlspecialchars($size);

            $item['content'] = <<<EOD
<p><strong>Release Notes</strong></p>
{$releaseNotes}
<p><strong>Compatibility</strong></p>
<p>{$escapedCompatibility}</p>
<p><strong>License</strong></p>
{$license}
<p><strong>Download</strong></p>
<p><a href="{$escapedDownloadlink}">{$escapedXpiFilename}</a> ({$escapedSize})</p>
EOD;

            $this->items[] = $item;
        }
    }

    public function getURI(): string
    {
        $id = $this->getInput('id');
        if (is_string($id) === true && $id !== '') {
            return self::URI . 'en-US/firefox/addon/' . $id . '/versions/';
        }

        return parent::getURI();
    }

    public function getName(): string
    {
        if ($this->feedName !== '') {
            return $this->feedName . ' - Firefox Add-on';
        }

        return parent::getName();
    }

    private function removeLinkRedirects(\Dom\Node $node): string
    {
        if ($node instanceof \Dom\Element === false && $node instanceof \Dom\HTMLDocument === false) {
            return '';
        }

        $outgoingRegex = '/https:\/\/prod\.outgoing\.prod\.webservices\.mozgcp\.net\/v1\/(?:[A-z0-9]+)\//';
        $links = $node->querySelectorAll('a');
        foreach ($links as $a) {
            if ($a instanceof \Dom\Element === false) {
                continue;
            }
            $href = (string) ($a->getAttribute('href') ?? '');
            if ($href !== '') {
                $cleaned = preg_replace($outgoingRegex, '', $href);
                if (is_string($cleaned) === true) {
                    $a->setAttribute('href', urldecode($cleaned));
                }
            }
        }

        foreach ($node->querySelectorAll('ul') as $ul) {
            if ($ul instanceof \Dom\Element === true) {
                $ul->setAttribute('style', self::CSS['ul']);
            }
        }

        foreach ($node->querySelectorAll('li') as $li) {
            if ($li instanceof \Dom\Element === true) {
                $li->setAttribute('style', self::CSS['li']);
            }
        }

        return $this->cleanHtml((string) $node->innerHTML);
    }

    private function cleanHtml(string $html): string
    {
        if ($html === '' || $html === null) {
            return '';
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString('<div>' . $html . '</div>');
        libxml_use_internal_errors(false);

        $wrapper = $dom->querySelector('div');
        if ($wrapper === null) {
            return $html;
        }

        foreach ($wrapper->querySelectorAll(implode(',', self::JUNK_SELECTORS)) as $junk) {
            if ($junk instanceof \Dom\Element === true) {
                $junk->remove();
            }
        }

        foreach ($wrapper->querySelectorAll('img') as $img) {
            if ($img instanceof \Dom\Element === true) {
                $img->removeAttribute('width');
                $img->removeAttribute('height');
                $img->setAttribute('style', self::CSS['img']);
            }
        }

        return trim((string) $wrapper->innerHTML);
    }

    public function detectParameters($url): ?array
    {
        $urlString = (string) $url;
        $params = [];

        $pattern = '/addons\.mozilla\.org\/(?:[\w-]+\/)?firefox\/addon\/([\w-]+)/';
        if (preg_match($pattern, $urlString, $matches) === 1) {
            $params['id'] = (string) $matches[1];
            return $params;
        }

        return null;
    }
}
