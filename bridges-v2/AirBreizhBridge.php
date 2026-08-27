<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

use function urljoin;

final class AirBreizhBridge extends BridgeAbstract
{
    public const NAME = 'Air Breizh';
    public const URI = 'https://www.airbreizh.asso.fr/';
    public const DESCRIPTION = 'Returns newests publications on Air Breizh';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        'Publications' => [
            'theme' => [
                'name' => 'Thematique',
                'type' => 'list',
                'values' => [
                    'Tout' => '',
                    'Rapport d\'activite' => 'rapport-dactivite',
                    'Etude' => 'etudes',
                    'Information' => 'information',
                    'Autres documents' => 'autres-documents',
                    'Plan Régional de Surveillance de la qualité de l\'air' => 'prsqa',
                    'Transport' => 'transport',
                ],
            ],
        ],
    ];

    private const CSS = [
        'image' => 'display: block; max-width: 500px; height: auto; margin: 10px 0;',
    ];

    public function collectData()
    {
        $theme = (string)($this->getInput('theme') ?? '');
        $url = self::URI . 'publications/?fwp_publications_thematiques=' . rawurlencode($theme);

        $html = getContents($url);

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $articles = iterator_to_array($dom->querySelectorAll('article'));

        foreach ($articles as $article) {
            if ($article instanceof \Dom\Element === false) {
                continue;
            }

            $h2 = $article->querySelector('h2');
            if ($h2 === null) {
                continue;
            }

            $title = trim($h2->textContent ?? '');
            if ($title === '') {
                continue;
            }

            $cardImage = $article->querySelector('.card__image img');
            $imageSrc = null;
            if ($cardImage !== null) {
                $src = $cardImage->getAttribute('src');
                if ($src !== null && $src !== '') {
                    $imageSrc = urljoin(self::URI, $src);
                }
            }

            $cardText = $article->querySelector('.card__text');
            $preview = '';
            if ($cardText !== null) {
                $preview = trim($cardText->textContent ?? '');
            }

            $buttonsLink = $article->querySelector('.publi__buttons a');
            $itemUri = null;
            if ($buttonsLink !== null) {
                $href = $buttonsLink->getAttribute('href');
                if ($href !== null && $href !== '') {
                    $itemUri = urljoin(self::URI, $href);
                }
            }

            if ($itemUri === null) {
                continue;
            }

            $content = '';
            if ($imageSrc !== null) {
                $content .= '<img src="' . e($imageSrc) . '" style="' . self::CSS['image'] . '" />';
                $content .= '<br/>';
            }
            $content .= e($preview);

            $item = [];
            $item['title'] = $title;
            $item['author'] = 'Air Breizh';
            $item['content'] = $content;
            $item['uri'] = $itemUri;
            $item['uid'] = $itemUri;

            $this->items[] = $item;
        }
    }
}
