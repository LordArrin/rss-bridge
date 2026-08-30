<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

use function urljoin;

final class SamsungMobileChangelogBridge extends BridgeAbstract
{
    public const NAME = 'Samsung Mobile Changelog';
    public const URI = 'https://doc.samsungmobile.com/';
    public const DESCRIPTION = 'Changelog of selected device from the Samsung Mobile documentation in English';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 86400;

    public const STR_BUILD_NUMBER = 'Build Number';
    public const STR_ANDROID_VERSION = 'Android version';
    public const STR_RELEASE_DATE = 'Release Date';
    public const STR_SECURITY_PATCH_LEVEL = 'Security patch level';

    public const PARAMETERS = [
        [
            'device' => [
                'name' => 'Device Model',
                'title' => 'The model name found in Settings → About phone/tablet\nSM-931B/DS → SM-S931B',
                'required' => true,
                'exampleValue' => 'SM-S931B',
            ],
            'region' => [
                'name' => 'Region',
                'title' => 'The 3 letter region code found in Service provider software version in\nSettings → About phone/tablet → Software information',
                'required' => true,
                'exampleValue' => 'EUX',
            ],
        ]
    ];

    private string $deviceName = '';

    public function collectData(): void
    {
        $url = $this->getURI();
        $html = getContents($url);

        if (is_string($html) === false || $html === '') {
            \throwServerException('Could not request changelog page: ' . $url);
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $dfltPageInput = $dom->querySelector('input#dflt_page');
        if ($dfltPageInput !== null) {
            $dfltPageValue = (string) ($dfltPageInput->getAttribute('value') ?? '');
            if ($dfltPageValue !== '') {
                $urlLanguage = urljoin($url, $dfltPageValue);
            } else {
                \throwServerException('Unable to find English version (empty dflt_page value)');
            }
        } else {
            $enOption = $dom->querySelector('option[value*="eng.html"]');
            if ($enOption !== null) {
                $enOptionValue = (string) ($enOption->getAttribute('value') ?? '');
                if ($enOptionValue !== '') {
                    $urlLanguage = urljoin($url, $enOptionValue);
                } else {
                    \throwServerException('Unable to find English version');
                }
            } else {
                \throwServerException('Unable to find English version');
            }
        }

        $html = getContents($urlLanguage);
        if (is_string($html) === false || $html === '') {
            \throwServerException('Could not request changelog: ' . $urlLanguage);
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $container = $dom->querySelector('div.container');
        if ($container === null) {
            \throwServerException('Unable to find container element');
        }

        $h1 = $dom->querySelector('h1');
        if ($h1 !== null) {
            $this->deviceName = trim((string) $h1->textContent);
        }

        $this->parseChangelog($container, $url);
    }

    private function parseChangelog(\Dom\Element $container, string $baseUrl): void
    {
        $reachedStart = false;
        $item = [];

        foreach ($container->children as $element) {
            if ($element instanceof \Dom\Element === false) {
                continue;
            }

            $nodeName = strtolower($element->nodeName);

            if ($nodeName === 'hr') {
                $reachedStart = true;
                $item = [];
                continue;
            }

            if ($reachedStart === false) {
                continue;
            }

            if ($nodeName === 'div' && $element->getAttribute('class') === 'row') {
                $divs = $element->querySelectorAll('div');
                if ($divs->length >= 4) {
                    $buildDiv = $divs->item(0);
                    $versionDiv = $divs->item(1);
                    $dateDiv = $divs->item(2);
                    $patchDiv = $divs->item(3);

                    $build = '';
                    if ($buildDiv !== null) {
                        $build = trim((string) $buildDiv->textContent);
                        $build = str_replace(self::STR_BUILD_NUMBER . ' : ', '', $build);
                    }

                    $version = '';
                    if ($versionDiv !== null) {
                        $version = trim((string) $versionDiv->textContent);
                        $version = str_replace(self::STR_ANDROID_VERSION . ' : ', '', $version);
                    }

                    $date = '';
                    if ($dateDiv !== null) {
                        $date = trim((string) $dateDiv->textContent);
                        $date = str_replace(self::STR_RELEASE_DATE . ' : ', '', $date);
                    }

                    $patch = '';
                    if ($patchDiv !== null) {
                        $patch = trim((string) $patchDiv->textContent);
                        $patch = str_replace(self::STR_SECURITY_PATCH_LEVEL . ' : ', '', $patch);
                    }

                    $item['title'] = $date . ' ' . $build;
                    $item['uri'] = $baseUrl;
                    $item['uid'] = md5($build . $date);

                    $timestamp = strtotime($date);
                    if ($timestamp !== false) {
                        $item['timestamp'] = $timestamp;
                    }

                    $content = '';
                    $content .= '<b>' . htmlspecialchars(self::STR_BUILD_NUMBER) . ':</b> ' . htmlspecialchars($build) . '<br>';
                    $content .= '<b>' . htmlspecialchars(self::STR_ANDROID_VERSION) . ':</b> ' . htmlspecialchars($version) . '<br>';
                    $content .= '<b>' . htmlspecialchars(self::STR_RELEASE_DATE) . ':</b> ' . htmlspecialchars($date) . '<br>';
                    $content .= '<b>' . htmlspecialchars(self::STR_SECURITY_PATCH_LEVEL) . ':</b> ' . htmlspecialchars($patch) . '<br>';
                    $content .= '<br><b>Changelog: </b><br>';

                    $item['content'] = $content;
                }
                continue;
            }

            if (isset($item['content']) === true) {
                $item['content'] .= $element->innerHTML;
                $this->items[] = $item;
                $item = [];
            }
        }
    }

    private function getBaseURL(): string
    {
        $device = (string) ($this->getInput('device') ?? '');
        $region = (string) ($this->getInput('region') ?? '');
        return self::URI . $device . '/' . $region . '/';
    }

    public function getURI(): string
    {
        $device = $this->getInput('device');
        if (is_string($device) === true && $device !== '') {
            return $this->getBaseURL() . 'doc.html';
        }
        return self::URI;
    }

    public function getName(): string
    {
        if ($this->deviceName !== '') {
            return htmlspecialchars_decode($this->deviceName) . ' - Changelog';
        }
        return self::NAME;
    }
}
