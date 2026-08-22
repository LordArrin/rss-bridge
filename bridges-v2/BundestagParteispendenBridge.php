<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class BundestagParteispendenBridge extends BridgeAbstract
{
    public const NAME = 'Deutscher Bundestag - Parteispenden';
    public const URI = 'https://www.bundestag.de/parlament/praesidium/parteienfinanzierung/fundstellen50000';
    public const DESCRIPTION = 'Returns the latest "soft money" donations to parties represented in the German Bundestag';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 86400;

    private const CONTENT_TEMPLATE = <<<TMPL
<p><b>Partei:</b><br>%s</p>
<p><b>Spendenbetrag:</b><br>%s</p>
<p><b>Spender:</b><br>%s</p>
<p><b>Eingang der Spende:</b><br>%s</p>
TMPL;

    public function getIcon(): string
    {
        return 'https://www.bundestag.de/static/appdata/includes/images/layout/favicon.ico';
    }

    public function collectData(): void
    {
        $html = getContents(self::URI);
        if ($html === '') {
            throwServerException('Could not fetch main page: ' . self::URI);
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $this->resolveRelativeUrls($dom->documentElement, $this->getURI());

        $firstAnchor = $dom->querySelector('a.e-linkListItem__anchor');
        if ($firstAnchor === null) {
            throwServerException('Could not find the proper HTML element.');
        }

        $url = $firstAnchor->getAttribute('href') ?? '';
        if ($url === '') {
            throwServerException('Could not extract URL from anchor element.');
        }

        $pageHtml = getContents($url);
        if ($pageHtml === '') {
            throwServerException('Could not fetch donations page: ' . $url);
        }

        libxml_use_internal_errors(true);
        $pageDom = \Dom\HTMLDocument::createFromString($pageHtml);
        libxml_use_internal_errors(false);

        $this->resolveRelativeUrls($pageDom->documentElement, $url);

        $rows = $pageDom->querySelectorAll('table.table > tbody > tr');
        if ($rows->length === 0) {
            throwServerException('Could not find the proper HTML elements.');
        }

        foreach ($rows as $row) {
            $item = $this->generateItemFromRow($row);
            if (is_array($item) === true) {
                $item['uri'] = $url;
                $this->items[] = $item;
            }
        }
    }

    private function generateItemFromRow(\Dom\Element $row): ?array
    {
        $cells = $row->querySelectorAll('td');
        if ($cells->length !== 5) {
            return null;
        }

        $partyEl = $cells->item(0)?->querySelector('p');
        $amountEl = $cells->item(1)?->querySelector('p');
        $donorEl = $cells->item(2)?->querySelector('p');
        $dateEl = $cells->item(3)?->querySelector('p');
        $dipEl = $cells->item(4)?->querySelector('a.dipLink');

        if ($partyEl === null || $amountEl === null || $donorEl === null || $dateEl === null) {
            return null;
        }

        $party = trim($partyEl->innerHTML ?? '');
        $amountText = trim($amountEl->innerHTML ?? '');

        $donor = trim($donorEl->innerHTML ?? '');
        $date = str_replace(' ', '', trim($dateEl->innerHTML ?? ''));

        $content = sprintf(self::CONTENT_TEMPLATE, $party, $amount, $donor, $date);

        $item = [
            'title' => $party . ': ' . $amount,
            'content' => $content,
            'uid' => sha1($content),
        ];

        if ($dipEl !== null) {
            $dipHref = $dipEl->getAttribute('href') ?? '';
            if ($dipHref !== '') {
                $item['enclosures'] = [$dipHref];
            }
        }

        $dateTime = \DateTime::createFromFormat('d.m.Y', $date);
        if ($dateTime !== false) {
            $item['timestamp'] = $dateTime->getTimestamp();
        }

        return $item;
    }

    private function resolveRelativeUrls(?\Dom\Element $container, string $baseUrl): void
    {
        if ($container === null) {
            return;
        }

        $base = rtrim($baseUrl, '/');
        $elements = $container->querySelectorAll('[src], [href]');
        foreach ($elements as $el) {
            foreach (['src', 'href'] as $attr) {
                $value = $el->getAttribute($attr);
                if ($value === null) {
                    continue;
                }
                if (str_starts_with($value, '/') === true && str_starts_with($value, '//') === false) {
                    $parsedBase = parse_url($base);
                    $scheme = $parsedBase['scheme'] ?? 'https';
                    $host = $parsedBase['host'] ?? '';
                    if ($host !== '') {
                        $el->setAttribute($attr, $scheme . '://' . $host . $value);
                    }
                }
            }
        }
    }
}
