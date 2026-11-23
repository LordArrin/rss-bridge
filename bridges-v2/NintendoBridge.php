<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;

final class NintendoBridge extends BridgeAbstract
{
    public const NAME = 'Nintendo Software Updates';
    public const URI = 'https://www.nintendo.com/en-gb/Support/Welcome-to-Nintendo-Support-11593.html';
    public const DESCRIPTION = 'Returns app updates from Nintendo website';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;
    public const ICON = 'https://www.nintendo.com/eu/media/images/01_website_elements/icons/favicon.ico';

    public const PARAMETERS = [
        '' => [
            'category' => [
                'name' => 'Category',
                'type' => 'list',
                'values' => [
                    'All' => 'all',
                    'Mario Kart 8 Deluxe' => 'mk8d',
                    'Splatoon 2' => 's2',
                    'Super Mario 3D All-Stars' => 'sm3as',
                    'Super Mario 3D World + Bowser’s Fury' => 'sm3wbf',
                    'Super Mario Bros. Wonder' => 'smbw',
                    'Super Mario Maker 2' => 'smm2',
                    'Super Mario Odyssey' => 'smo',
                    'Super Smash Bros. Ultimate' => 'ssbu',
                    'Switch Firmware' => 'sf',
                    'The Legend of Zelda: Link’s Awakening' => 'tlozla',
                    'The Legend of Zelda: Skyward Sword HD' => 'tlozss',
                    'The Legend of Zelda: Tears of the Kingdom' => 'tloztotk',
                    'Xenoblade Chronicles 2' => 'xc2',
                ],
                'defaultValue' => 'mk8d',
                'title' => 'Select category',
            ],
            'country' => [
                'name' => 'Country',
                'type' => 'list',
                'values' => [
                    'België' => 'be/nl',
                    'Belgique' => 'be/fr',
                    'Deutschland' => 'de',
                    'España' => 'es',
                    'France' => 'fr',
                    'Italia' => 'it',
                    'Nederland' => 'nl',
                    'Österreich' => 'at',
                    'Portugal' => 'pt',
                    'Schweiz' => 'ch/de',
                    'Suisse' => 'ch/fr',
                    'Svizzera' => 'ch/it',
                    'UK & Ireland' => 'co.uk',
                ],
                'defaultValue' => 'co.uk',
                'title' => 'Select your country',
            ],
        ],
    ];

    private const FEED_SOURCE_URL = [
        'mk8d' => [
            'de' => 'Support/Nintendo-Switch/Spiele-Updates/Das-Aktualisieren-von-Mario-Kart-8-Deluxe-1482895.html',
            'es' => 'Ayuda/Nintendo-Switch/Actualizaciones-de-juegos/Como-actualizar-Mario-Kart-8-Deluxe-1482895.html',
            'fr' => 'Assistance/Nintendo-Switch/Mises-a-jour-des-logiciels/Comment-mettre-a-jour-Mario-Kart-8-Deluxe-1482895.html',
            'it' => 'Assistenza/Nintendo-Switch/Aggiornamenti-dei-giochi/Come-aggiornare-Mario-Kart-8-Deluxe-1482895.html',
            'nl' => 'Support/Nintendo-Switch/Software-updates/Zo-voer-je-een-update-voor-Mario-Kart-8-Deluxe-uit-1482895.html',
            'pt' => 'Assistencia/Nintendo-Switch/Atualizacoes-para-jogos/Como-atualizar-Mario-Kart-8-Deluxe-1482895.html',
            'en' => 'Support/Nintendo-Switch/Game-Updates/How-to-Update-Mario-Kart-8-Deluxe-1482895.html',
        ],
        's2' => [
            'de' => 'Support/Nintendo-Switch/Spiele-Updates/Software-Updates-fur-Splatoon-2-1482897.html',
            'es' => 'Ayuda/Nintendo-Switch/Actualizaciones-de-juegos/Como-actualizar-Splatoon-2-1482897.html',
            'fr' => 'Assistance/Nintendo-Switch/Mises-a-jour-des-logiciels/Comment-mettre-a-jour-Splatoon-2-1482897.html',
            'it' => 'Assistenza/Nintendo-Switch/Aggiornamenti-dei-giochi/Come-aggiornare-Splatoon-2-1482897.html',
            'nl' => 'Support/Nintendo-Switch/Software-updates/Zo-werk-je-Splatoon-2-bij-1482897.html',
            'pt' => 'Assistencia/Nintendo-Switch/Atualizacoes-para-jogos/Como-atualizar-Splatoon-2-1482897.html',
            'en' => 'Support/Nintendo-Switch/Game-Updates/How-to-Update-Splatoon-2-1482897.html',
        ],
        'sm3as' => [
            'de' => 'Support/Nintendo-Switch/Spiele-Updates/Das-Aktualisieren-von-Super-Mario-3D-All-Stars--1844226.html',
            'es' => 'Ayuda/Nintendo-Switch/Actualizaciones-de-juegos/Como-actualizar-Super-Mario-3D-All-Stars-1844226.html',
            'fr' => 'Assistance/Nintendo-Switch/Mises-a-jour-des-logiciels/Comment-mettre-a-jour-Super-Mario-3D-All-Stars-1844226.html',
            'it' => 'Assistenza/Nintendo-Switch/Aggiornamenti-dei-giochi/Come-aggiornare-Super-Mario-3D-All-Stars-1844226.html',
            'nl' => 'Support/Nintendo-Switch/Software-updates/Zo-werk-je-Update-Super-Mario-3D-All-Stars-bij-1844226.html',
            'pt' => 'Assistencia/Nintendo-Switch/Atualizacoes-para-jogos/Como-atualizar-Super-Mario-3D-All-Stars-1844226.html',
            'en' => 'Support/Nintendo-Switch/Game-Updates/How-to-Update-Super-Mario-3D-All-Stars-1844226.html',
        ],
        'sm3wbf' => [
            'de' => 'Support/Nintendo-Switch/Spiele-Updates/Das-Aktualisieren-von-Super-Mario-3D-World-Bowser-s-Fury--1920668.html',
            'es' => 'Ayuda/Nintendo-Switch/Actualizaciones-de-juegos/Como-actualizar-Super-Mario-3D-World-Bowser-s-Fury-1920668.html',
            'pt' => 'Assistencia/Nintendo-Switch/Atualizacoes-para-jogos/Como-atualizar-Super-Mario-3D-World-Bowser-s-Fury-1920668.html',
            'en' => 'Support/Nintendo-Switch/Game-Updates/How-to-Update-Super-Mario-3D-World-Bowser-s-Fury-1920668.html',
        ],
        'smbw' => [
            'de' => 'Support/Nintendo-Switch/Spiele-Updates/Das-Aktualisieren-von-Super-Mario-Bros-Wonder--2485410.html',
            'es' => 'Ayuda/Nintendo-Switch/Actualizaciones-de-juegos/Como-actualizar-Super-Mario-Bros-Wonder-2485410.html',
            'fr' => 'Assistance/Nintendo-Switch/Mises-a-jour-des-logiciels/Comment-mettre-a-jour-Super-Mario-Bros-Wonder--2485410.html',
            'it' => 'Assistenza/Nintendo-Switch/Aggiornamenti-dei-giochi/Come-aggiornare-Super-Mario-Bros-Wonder-2485410.html',
            'nl' => 'Support/Nintendo-Switch/Software-updates/Zo-werk-je-Super-Mario-Bros-Wonder-bij-2485410.html',
            'pt' => 'Assistencia/Nintendo-Switch/Atualizacoes-para-jogos/Como-atualizar-Super-Mario-Bros-Wonder-2485410.html',
            'en' => 'Support/Nintendo-Switch/Game-Updates/How-to-Update-Super-Mario-Bros-Wonder-2485410.html',
        ],
        'smm2' => [
            'de' => 'Support/Nintendo-Switch/Spiele-Updates/Software-Updates-fur-Super-Mario-Maker-2-1586745.html',
            'es' => 'Ayuda/Nintendo-Switch/Actualizaciones-de-juegos/Como-actualizar-Super-Mario-Maker-2-1586745.html',
            'fr' => 'Assistance/Nintendo-Switch/Mises-a-jour-des-logiciels/Comment-mettre-a-jour-Super-Mario-Maker-2-1586745.html',
            'it' => 'Assistenza/Nintendo-Switch/Aggiornamenti-dei-giochi/Come-aggiornare-Super-Mario-Maker-2-1586745.html',
            'nl' => 'Support/Nintendo-Switch/Software-updates/Zo-werk-je-Super-Mario-Maker-2-bij-1586745.html',
            'pt' => 'Assistencia/Nintendo-Switch/Atualizacoes-para-jogos/Como-atualizar-Super-Mario-Maker-2-1586745.html',
            'en' => 'Support/Nintendo-Switch/Game-Updates/How-to-Update-Super-Mario-Maker-2-1586745.html',
        ],
        'smo' => [
            'de' => 'Support/Nintendo-Switch/Spiele-Updates/Das-Aktualisieren-von-Super-Mario-Odyssey--1482901.html',
            'es' => 'Ayuda/Nintendo-Switch/Actualizaciones-de-juegos/Como-actualizar-Super-Mario-Odyssey-1482901.html',
            'fr' => 'Assistance/Nintendo-Switch/Mises-a-jour-des-logiciels/Comment-mettre-a-jour-Super-Mario-Odyssey-1482901.html',
            'it' => 'Assistenza/Nintendo-Switch/Aggiornamenti-dei-giochi/Come-aggiornare-Super-Mario-Odyssey-1482901.html',
            'nl' => 'Support/Nintendo-Switch/Software-updates/Zo-werk-je-Super-Mario-Odyssey-bij-1482901.html',
            'pt' => 'Assistencia/Nintendo-Switch/Atualizacoes-para-jogos/Como-atualizar-Super-Mario-Odyssey-1482901.html',
            'en' => 'Support/Nintendo-Switch/Game-Updates/How-to-Update-Super-Mario-Odyssey-1482901.html',
        ],
        'ssbu' => [
            'de' => 'Support/Nintendo-Switch/Spiele-Updates/So-fuhren-Sie-ein-Update-fur-Super-Smash-Bros-Ultimate-durch-1484130.html',
            'es' => 'Ayuda/Nintendo-Switch/Actualizaciones-de-juegos/Como-actualizar-Super-Smash-Bros-Ultimate-1484130.html',
            'fr' => 'Assistance/Nintendo-Switch/Mises-a-jour-des-logiciels/Comment-mettre-a-jour-Super-Smash-Bros-Ultimate-1484130.html',
            'it' => 'Assistenza/Nintendo-Switch/Aggiornamenti-dei-giochi/Come-aggiornare-Super-Smash-Bros-Ultimate-1484130.html',
            'nl' => 'Support/Nintendo-Switch/Software-updates/Zo-werk-je-Super-Smash-Bros-Ultimate-bij-1484130.html',
            'pt' => 'Assistencia/Nintendo-Switch/Atualizacoes-para-jogos/Como-atualizar-Super-Smash-Bros-Ultimate-1484130.html',
            'en' => 'Support/Nintendo-Switch/Game-Updates/How-to-Update-Super-Smash-Bros-Ultimate-1484130.html',
        ],
        'sf' => [
            'de' => 'Support/Fehlersuche/Informationen-zu-System-Updates-fur-Nintendo-Switch-1445507.html',
            'es' => 'Ayuda/Localizacion-y-resolucion-de-problemas/Historial-de-actualizaciones-de-Nintendo-Switch-1445507.html',
            'fr' => 'Assistance/Depannage/Informations-sur-la-mise-a-jour-de-la-console-Nintendo-Switch-1445507.html',
            'it' => 'Assistenza/Risoluzione-dei-problemi/Informazioni-sugli-aggiornamenti-della-console-Nintendo-Switch-1445507.html',
            'nl' => 'Support/Problemen-oplossen/Informatie-over-systeemupdates-voor-de-Nintendo-Switch-1445507.html',
            'pt' => 'Assistencia/Resolucao-de-problemas/Informacoes-sobre-as-atualizacoes-da-Nintendo-Switch-1445507.html',
            'en' => 'Support/Troubleshooting/Nintendo-Switch-System-Update-Information-1445507.html',
        ],
        'tlozla' => [
            'de' => 'Support/Nintendo-Switch/Spiele-Updates/Das-Aktualisieren-von-The-Legend-of-Zelda-Link-s-Awakening--1666739.html',
            'es' => 'Ayuda/Nintendo-Switch/Actualizaciones-de-juegos/Como-actualizar-The-Legend-of-Zelda-Link-s-Awakening-1666739.html',
            'fr' => 'Assistance/Nintendo-Switch/Mises-a-jour-des-logiciels/Comment-mettre-a-jour-The-Legend-of-Zelda-Link-s-Awakening-1666739.html',
            'it' => 'Assistenza/Nintendo-Switch/Aggiornamenti-dei-giochi/Come-aggiornare-The-Legend-of-Zelda-Link-s-Awakening-1666739.html',
            'nl' => 'Support/Nintendo-Switch/Software-updates/Zo-werk-je-The-Legend-of-Zelda-Link-s-Awakening-bij-1666739.html',
            'pt' => 'Assistencia/Nintendo-Switch/Atualizacoes-para-jogos/Como-atualizar-The-Legend-of-Zelda-Link-s-Awakening-1666739.html',
            'en' => 'Support/Nintendo-Switch/Game-Updates/How-to-Update-The-Legend-of-Zelda-Link-s-Awakening-1666739.html',
        ],
        'tlozss' => [
            'de' => 'Support/Nintendo-Switch/Spiele-Updates/Aktualisierung-von-The-Legend-of-Zelda-Skyward-Sword-HD-2022801.html',
            'es' => 'Ayuda/Nintendo-Switch/Actualizaciones-de-juegos/Como-actualizar-The-Legend-of-Zelda-Skyward-Sword-HD-2022801.html',
            'it' => 'Assistenza/Nintendo-Switch/Aggiornamenti-dei-giochi/Come-aggiornare-The-Legend-of-Zelda-Skyward-Sword-HD-2022801.html',
            'nl' => 'Support/Nintendo-Switch/Software-updates/Zo-voer-je-een-update-uit-voor-The-Legend-of-Zelda-Skyward-Sword-HD-2022801.html',
            'pt' => 'Assistencia/Nintendo-Switch/Atualizacoes-para-jogos/Como-atualizar-The-Legend-of-Zelda-Skyward-Sword-HD-2022801.html',
            'en' => 'Support/Nintendo-Switch/Game-Updates/How-to-Update-The-Legend-of-Zelda-Skyward-Sword-HD-2022801.html',
        ],
        'tloztotk' => [
            'de' => 'Support/Nintendo-Switch/Spiele-Updates/Das-Aktualisieren-von-The-Legend-of-Zelda-Tears-of-the-Kingdom--2388231.html',
            'es' => 'Ayuda/Nintendo-Switch/Actualizaciones-de-juegos/Como-actualizar-The-Legend-of-Zelda-Tears-of-the-Kingdom-2388231.html',
            'fr' => 'Assistance/Nintendo-Switch/Mises-a-jour-des-logiciels/Comment-mettre-a-jour-The-Legend-of-Zelda-Tears-of-the-Kingdom-2388231.html',
            'it' => 'Assistenza/Nintendo-Switch/Aggiornamenti-dei-giochi/Come-aggiornare-The-Legend-of-Zelda-The-Legend-of-Zelda-Tears-of-the-Kingdom-2388231.html',
            'nl' => 'Support/Nintendo-Switch/Software-updates/Zo-werk-je-The-Legend-of-Zelda-The-Legend-of-Zelda-Tears-of-the-Kingdom-bij-2388231.html',
            'pt' => 'Assistencia/Nintendo-Switch/Atualizacoes-para-jogos/Como-atualizar-The-Legend-of-Zelda-Tears-of-the-Kingdom-2388231.html',
            'en' => 'Support/Nintendo-Switch/Game-Updates/How-to-Update-The-Legend-of-Zelda-Tears-of-the-Kingdom-2388231.html',
        ],
        'xc2' => [
            'de' => 'Support/Nintendo-Switch/Spiele-Updates/Das-Aktualisieren-von-Xenoblade-Chronicles-2--1482905.html',
            'es' => 'Ayuda/Nintendo-Switch/Actualizaciones-de-juegos/Como-actualizar-Xenoblade-Chronicles-2-1482905.html',
            'fr' => 'Assistance/Nintendo-Switch/Mises-a-jour-des-logiciels/Comment-mettre-a-jour-Xenoblade-Chronicles-2-1482905.html',
            'it' => 'Assistenza/Nintendo-Switch/Aggiornamenti-dei-giochi/Come-aggiornare-Xenoblade-Chronicles-2-1482905.html',
            'nl' => 'Support/Nintendo-Switch/Software-updates/Zo-werk-je-Xenoblade-Chronicles-2-bij-1482905.html',
            'pt' => 'Assistencia/Nintendo-Switch/Atualizacoes-para-jogos/Como-Atualizar-Xenoblade-Chronicles-2-1482905.html',
            'en' => 'Support/Nintendo-Switch/Game-Updates/How-to-Update-Xenoblade-Chronicles-2-1482905.html',
        ],
    ];

    private const COUNTRY_LOCALE = [
        'be/nl' => 'nl-be',
        'be/fr' => 'fr-be',
        'de' => 'de-de',
        'es' => 'es-es',
        'fr' => 'fr-fr',
        'it' => 'it-it',
        'nl' => 'nl-nl',
        'at' => 'de-at',
        'pt' => 'pt-pt',
        'ch/de' => 'de-ch',
        'ch/fr' => 'fr-ch',
        'ch/it' => 'it-ch',
        'co.uk' => 'en-gb',
    ];

    private const LOCALE_FALLBACK = [
        'tlozss' => ['de-ch' => 'de-de'],
    ];

    private const MONTH_NAMES = [
        'januari' => 'January', 'februari' => 'February', 'maart' => 'March',
        'april' => 'April', 'mei' => 'May', 'juni' => 'June',
        'juli' => 'July', 'augustus' => 'August', 'september' => 'September',
        'oktober' => 'October', 'november' => 'November', 'december' => 'December',
        'janvier' => 'January', 'février' => 'February', 'mars' => 'March',
        'avril' => 'April', 'mai' => 'May', 'juin' => 'June',
        'juillet' => 'July', 'août' => 'August', 'septembre' => 'September',
        'octobre' => 'October', 'novembre' => 'November', 'décembre' => 'December',
        'januar' => 'January', 'februar' => 'February', 'märz' => 'March',
        'august' => 'August', 'dezember' => 'December', 'enero' => 'January',
        'febrero' => 'February', 'marzo' => 'March', 'abril' => 'April',
        'mayo' => 'May', 'junio' => 'June', 'julio' => 'July',
        'agosto' => 'August', 'septiembre' => 'September', 'octubre' => 'October',
        'noviembre' => 'November', 'diciembre' => 'December', 'gennaio' => 'January',
        'febbraio' => 'February', 'aprile' => 'April', 'maggio' => 'May',
        'giugno' => 'June', 'luglio' => 'July', 'settembre' => 'September',
        'ottobre' => 'October', 'dicembre' => 'December', 'janeiro' => 'January',
        'fevereiro' => 'February', 'março' => 'March', 'maio' => 'May',
        'junho' => 'June', 'julho' => 'July', 'setembro' => 'September',
        'outubro' => 'October', 'novembro' => 'November', 'dezembro' => 'December',
    ];

    public function collectData(): void
    {
        $category = $this->getInput('category');
        $category = is_string($category) === true && $category !== '' ? $category : 'mk8d';

        if ($category === 'all') {
            foreach (self::PARAMETERS['']['category']['values'] as $catKey) {
                if ($catKey === 'all') {
                    continue;
                }
                $this->collectCategory($catKey, true);
            }
            return;
        }

        $this->collectCategory($category, false);
    }

    public function getURI(): string
    {
        $category = $this->getInput('category');
        if (is_string($category) === true && $category !== '' && $category !== 'all') {
            return $this->getSourceUrl($category);
        }
        return self::URI;
    }

    public function getIcon(): string
    {
        return self::ICON;
    }

    private function collectCategory(string $category, bool $prefixTitle): void
    {
        $url = $this->getSourceUrl($category);
        $dom = getSimpleHTMLDOM($url);

        $canonical = $dom->querySelector('link[rel="canonical"]')?->getAttribute('href');
        $uri = ($canonical !== null && $canonical !== '') ? $canonical : $url;
        $categoryName = $this->categoryName($category);

        $selector = $category === 'sf' ? 'div#latest.collapse[rel="1"]' : 'div.col-xs-12.content > div.collapse[id^="v"]';

        foreach ($dom->querySelectorAll($selector) as $div) {
            $id = $div->getAttribute('id') ?? '';
            if ($id === '') {
                continue;
            }

            $titleNode = $div->querySelector('h2, strong');
            $title = $titleNode !== null ? trim($titleNode->textContent ?? '') : '';
            $title = preg_replace('~ [(\[].*~s', '', $title) ?? $title;
            if ($prefixTitle === true) {
                $title = $categoryName . ' ' . $title;
            }

            $link = $dom->querySelector('a.collapse_link[data-target="#' . $id . '"]');
            $timestamp = $link !== null ? $this->parseDate($link->textContent ?? '') : null;

            $item = [
                'title' => $title,
                'uri' => $uri,
                'content' => trim($div->innerHTML),
                'uid' => $category . '-' . $id,
                'categories' => [$categoryName],
            ];
            if ($timestamp !== null) {
                $item['timestamp'] = $timestamp;
            }

            $this->items[] = $item;
        }
    }

    private function categoryName(string $category): string
    {
        $name = array_search($category, self::PARAMETERS['']['category']['values'], true);
        return is_string($name) === true ? $name : $category;
    }

    private function getSourceUrl(string $category): string
    {
        $locale = $this->getLocale($category);
        $language = substr($locale, 0, 2);
        $path = self::FEED_SOURCE_URL[$category][$language] ?? self::FEED_SOURCE_URL[$category]['en'];
        return 'https://www.nintendo.com/' . $locale . '/' . $path;
    }

    private function getLocale(string $category): string
    {
        $country = $this->getInput('country');
        $country = is_string($country) === true ? $country : '';
        $locale = self::COUNTRY_LOCALE[$country] ?? 'en-gb';
        $locale = self::LOCALE_FALLBACK[$category][$locale] ?? $locale;
        $language = substr($locale, 0, 2);
        return isset(self::FEED_SOURCE_URL[$category][$language]) === true ? $locale : 'en-gb';
    }

    private function parseDate(string $text): ?int
    {
        if (preg_match('/[\[(]([^\])]*)[\])]/', $text, $m) === 1) {
            $value = $m[1];
        } else {
            $value = $text;
        }

        $value = $this->replaceMonths($value);
        $value = str_replace(["\u{00AD}", '--'], ['-', '-'], $value);

        $start = null;
        if (preg_match('/\d/', $value, $dm, PREG_OFFSET_CAPTURE) === 1) {
            $start = $dm[0][1];
        }
        foreach (['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'] as $month) {
            $pos = stripos($value, $month);
            if ($pos !== false && ($start === null || $pos < $start)) {
                $start = $pos;
            }
        }
        if ($start === null) {
            return null;
        }

        $value = trim(substr($value, $start));
        $value = rtrim($value, "]) \f\n\r\t\v\0");

        if (preg_match('/[A-Za-z]/', $value) === 1) {
            $timestamp = strtotime($value);
            return $timestamp !== false ? $timestamp : null;
        }

        if (preg_match('~(\d{1,2})\D+(\d{1,2})\D+(\d{2,4})~', $value, $m) === 1) {
            $first = (int) $m[1];
            $second = (int) $m[2];
            $year = (int) $m[3];
            if ($year < 100) {
                $year += 2000;
            }
            if (checkdate($second, $first, $year) === true) {
                return $this->makeTimestamp($year, $second, $first);
            }
            if (checkdate($first, $second, $year) === true) {
                return $this->makeTimestamp($year, $first, $second);
            }
        }

        $timestamp = strtotime($value);
        return $timestamp !== false ? $timestamp : null;
    }

    private function replaceMonths(string $value): string
    {
        $keys = array_keys(self::MONTH_NAMES);
        usort($keys, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $pattern = '/\b(' . implode('|', array_map(static fn (string $k): string => preg_quote($k, '/'), $keys)) . ')\b/iu';

        $matches = [];
        $count = preg_match_all($pattern, $value, $matches);
        if ($count === false || $count === 0) {
            return $value;
        }

        $replacements = [];
        foreach ($matches[1] as $token) {
            $key = mb_strtolower($token, 'UTF-8');
            $replacements[$token] = self::MONTH_NAMES[$key] ?? $token;
        }

        return strtr($value, $replacements);
    }

    private function makeTimestamp(int $year, int $month, int $day): ?int
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-n-j', $year . '-' . $month . '-' . $day);
        return $date !== false ? $date->getTimestamp() : null;
    }
}
