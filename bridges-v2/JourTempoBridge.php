<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;
use RSSBridge\Utils\Json;

final class JourTempoBridge extends BridgeAbstract
{
    public const NAME = 'Jour Tempo : jours EJP et jours PP';
    public const URI = 'https://jourtempo.fr/';
    public const DESCRIPTION = 'EDF EJP days and RTE peak days (PP) in France: today and tomorrow, from the free Jour Tempo API';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    private const API_BASE = 'https://jourtempo.fr/api/v1/';
    private const PAGE_EJP = 'https://jourtempo.fr/ejp/ejp-demain/';
    private const PAGE_PP = 'https://jourtempo.fr/couleurs/jours-pp1-pp2/';
    private const TIMEZONE = 'Europe/Paris';
    private const DAY_PATTERN = 'EEEE d MMMM yyyy';
    private const UPDATED_PATTERN = 'd MMMM yyyy \'à\' H\' h \'mm';

    public const PARAMETERS = [
        '' => [
            'signal' => [
                'name' => 'Signal',
                'type' => 'list',
                'defaultValue' => 'ejp',
                'values' => [
                    'Jours EJP (EDF)' => 'ejp',
                    'Jours PP (RTE, jours rouges Tempo depuis novembre 2026)' => 'pp',
                ],
            ],
        ],
    ];

    public function collectData(): void
    {
        $signal = $this->getSignal();
        $url = self::API_BASE . $signal . '.json';
        $data = Json::decode((string) getContents($url, ['Accept: application/json']));

        if (is_array($data) === false) {
            throwServerException('Invalid JSON response from the Jour Tempo API');
        }

        $attribution = $this->stringField($data, 'attribution');
        $collectedAt = strtotime($this->stringField($data, 'collecte_le'));
        $expiresAt = strtotime($this->stringField($data, 'perime_apres'));
        $expired = $expiresAt !== false && $expiresAt < time();

        $updated = '';
        if ($collectedAt !== false) {
            $updated = $this->formatTimestamp($collectedAt, self::UPDATED_PATTERN);
        }

        foreach (['aujourdhui' => 'Aujourd\'hui', 'demain' => 'Demain'] as $key => $label) {
            $day = $data[$key] ?? null;
            if (is_array($day) === false) {
                continue;
            }

            $rawDate = $this->stringField($day, 'date');
            $code = $this->stringField($day, 'code');
            if ($code === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate) !== 1) {
                continue;
            }

            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $rawDate, new \DateTimeZone(self::TIMEZONE));
            if ($date === false) {
                continue;
            }

            $libelle = $this->stringField($day, 'libelle');
            if ($libelle === '') {
                $libelle = $code;
            }
            if ($expired === true) {
                $libelle = 'Inconnu';
            }

            $displayDate = $this->formatTimestamp($date->getTimestamp(), self::DAY_PATTERN);
            if ($displayDate === '') {
                $displayDate = $rawDate;
            }

            $title = sprintf('%s %s : %s', $label, $displayDate, $libelle);
            $content = '<p>' . e($title) . '</p>';

            $detail = $this->stringField($day, 'detail');
            if ($detail !== '') {
                $content .= '<p>' . e($detail) . '</p>';
            }
            if ($expired === true) {
                $content .= '<p><small>Donnée périmée : statut non confirmé par la source.</small></p>';
            }
            if ($updated !== '') {
                $content .= '<p><small>Mis à jour le ' . e($updated) . '</small></p>';
            }
            if ($attribution !== '') {
                $content .= '<p><small>' . e($attribution) . '</small></p>';
            }

            $this->items[] = [
                'uri' => $this->getURI(),
                'title' => $title,
                'content' => $content,
                'timestamp' => $date->getTimestamp(),
                'uid' => $signal . '-' . $rawDate . '-' . $code . ($expired === true ? '-perime' : ''),
            ];
        }

        if ($this->items === []) {
            throwServerException('No day status found in the Jour Tempo API response');
        }
    }

    public function getName(): string
    {
        return $this->getSignal() === 'pp' ? 'Jour Tempo : jours PP' : parent::getName();
    }

    public function getURI(): string
    {
        return $this->getSignal() === 'pp' ? self::PAGE_PP : self::PAGE_EJP;
    }

    private function getSignal(): string
    {
        return $this->getInput('signal') === 'pp' ? 'pp' : 'ejp';
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function stringField(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) === true ? trim($value) : '';
    }

    private function formatTimestamp(int $timestamp, string $pattern): string
    {
        $date = (new \DateTimeImmutable('@' . (string) $timestamp))->setTimezone(new \DateTimeZone(self::TIMEZONE));
        $formatter = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::FULL, \IntlDateFormatter::NONE, self::TIMEZONE, null, $pattern);
        $formatted = $formatter->format($date);

        return is_string($formatted) === true ? $formatted : '';
    }
}
