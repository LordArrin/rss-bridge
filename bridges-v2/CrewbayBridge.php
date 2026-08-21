<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class CrewbayBridge extends BridgeAbstract
{
    public const NAME = 'Crewbay';
    public const URI = 'https://www.crewbay.com';
    public const DESCRIPTION = 'Returns the newest sailing offers.';
    public const MAINTAINER = 'no maintainer';
    public const CACHE_TIMEOUT = 3600;

    public const PARAMETERS = [
        [
            'keyword' => [
                'name' => 'Filter by keyword',
                'title' => 'Enter the keyword to filter here'
            ],
            'type' => [
                'name' => 'Type of search',
                'title' => 'Choose between finding a boat or a crew',
                'type' => 'list',
                'values' => [
                    'Find a boat' => 'boats',
                    'Find a crew' => 'crew'
                ]
            ],
            'status' => [
                'name' => 'Status on the boat',
                'title' => 'Choose between recreational or professional classified ads',
                'type' => 'list',
                'values' => [
                    'Recreational' => 'recreational',
                    'Professional' => 'professional'
                ]
            ],
            'recreational_position' => [
                'name' => 'Recreational position wanted',
                'title' => 'Filter by recreational position you wanted aboard',
                'required' => false,
                'type' => 'list',
                'values' => [
                    '' => '',
                    'Amateur Crew' => 'Amateur Crew',
                    'Friendship' => 'Friendship',
                    'Competent Crew' => 'Competent Crew',
                    'Racing' => 'Racing',
                    'Voluntary work' => 'Voluntary work',
                    'Mile building' => 'Mile building'
                ]
            ],
            'professional_position' => [
                'name' => 'Professional position wanted',
                'title' => 'Filter by professional position you wanted aboard',
                'required' => false,
                'type' => 'list',
                'values' => [
                    '' => '',
                    '1st Engineer' => '1st Engineer',
                    '1st Mate' => '1st Mate',
                    'Beautician' => 'Beautician',
                    'Bosun' => 'Bosun',
                    'Captain' => 'Captain',
                    'Chef' => 'Chef',
                    'Steward(ess)' => 'Steward(ess)',
                    'Deckhand' => 'Deckhand',
                    'Delivery Crew' => 'Delivery Crew',
                    'Dive Instructor' => 'Dive Instructor',
                    'Masseur' => 'Masseur',
                    'Medical Staff' => 'Medical Staff',
                    'Nanny' => 'Nanny',
                    'Navigator' => 'Navigator',
                    'Racing Crew' => 'Racing Crew',
                    'Teacher' => 'Teacher',
                    'Electrical Engineer' => 'Electrical Engineer',
                    'Fitter' => 'Fitter',
                    '2nd Engineer' => '2nd Engineer',
                    '3rd Engineer' => '3rd Engineer',
                    'Lead Deckhand' => 'Lead Deckhand',
                    'Security Officer' => 'Security Officer',
                    'O.O.W' => 'O.O.W',
                    '1st Officer' => '1st Officer',
                    '2nd Officer' => '2nd Officer',
                    '3rd Officer' => '3rd Officer',
                    'Captain/Engineer' => 'Captain/Engineer',
                    'Hairdresser' => 'Hairdresser',
                    'Fitness Trainer' => 'Fitness Trainer',
                    'Laundry' => 'Laundry',
                    'Solo Steward/ess' => 'Solo Steward/ess',
                    'Stew/Deck' => 'Stew/Deck',
                    '2nd Steward/ess' => '2nd Steward/ess',
                    '3rd Steward/ess' => '3rd Steward/ess',
                    'Chief Steward/ess' => 'Chief Steward/ess',
                    'Head Housekeeper' => 'Head Housekeeper',
                    'Purser' => 'Purser',
                    'Cook' => 'Cook',
                    'Cook/Stew' => 'Cook/Stew',
                    '2nd Chef' => '2nd Chef',
                    'Head Chef' => 'Head Chef',
                    'Administrator' => 'Administrator',
                    'P.A' => 'P.A',
                    'Villa staff' => 'Villa staff',
                    'Housekeeping/Stew' => 'Housekeeping/Stew',
                    'Stew/Beautician' => 'Stew/Beautician',
                    'Stew/Masseuse' => 'Stew/Masseuse',
                    'Manager' => 'Manager',
                    'Sailing instructor' => 'Sailing instructor'
                ]
            ]
        ]
    ];

    public function collectData(): void
    {
        $url = $this->getURI();
        $html = getContents($url);
        if ($html === '') {
            throwServerException('Could not fetch: ' . $url);
        }

        libxml_use_internal_errors(true);
        $dom = \Dom\HTMLDocument::createFromString($html);
        libxml_use_internal_errors(false);

        $this->resolveRelativeUrls($dom->documentElement, $url);

        $annonces = $dom->querySelectorAll('#SearchResults div.result');
        $limit = 0;

        foreach ($annonces as $annonce) {
            $detail = $annonce->querySelector('.btn--profile');
            if ($detail === null) {
                continue;
            }

            $detailHref = $detail->getAttribute('href') ?? '';
            if ($detailHref === '') {
                continue;
            }

            $detailHtml = getContents($detailHref);
            if ($detailHtml === '') {
                continue;
            }

            libxml_use_internal_errors(true);
            $htmlDetail = \Dom\HTMLDocument::createFromString($detailHtml);
            libxml_use_internal_errors(false);

            $this->resolveRelativeUrls($htmlDetail->documentElement, $detailHref);

            $recPosition = $this->getInput('recreational_position');
            $profPosition = $this->getInput('professional_position');

            if (($recPosition !== null && $recPosition !== '') || ($profPosition !== null && $profPosition !== '')) {
                $positions = [];
                $type = $this->getInput('type');
                $status = $this->getInput('status');

                if ($type === 'boats') {
                    if ($status === 'professional') {
                        $positionEl = $annonce->querySelector('.title .position');
                        if ($positionEl !== null) {
                            $positions = [trim($positionEl->textContent ?? '')];
                        }
                    } else {
                        $contentLi = $annonce->querySelector('.content li');
                        if ($contentLi !== null) {
                            $positions = [str_replace('Wanted:', '', trim($contentLi->textContent ?? ''))];
                        }
                    }
                } else {
                    $listElements = $htmlDetail->querySelectorAll('.viewer-details .viewer-list');
                    $listArray = iterator_to_array($listElements);
                    if ($listArray !== []) {
                        $lastList = end($listArray);
                        $valueSpan = $lastList->querySelector('span.value');
                        if ($valueSpan !== null) {
                            $positions = explode("\r\n", trim($valueSpan->textContent ?? ''));
                        }
                    }
                }

                $found = false;
                $keyword = $status === 'professional' ? 'professional_position' : 'recreational_position';
                $inputValue = $this->getInput($keyword);

                if ($inputValue !== null && $inputValue !== '') {
                    foreach ($positions as $position) {
                        if (str_contains(trim($position), (string)$inputValue) === true) {
                            $found = true;
                            break;
                        }
                    }
                }

                if ($found === false) {
                    continue;
                }
            }

            $item = [];

            $type = $this->getInput('type');
            if ($type === 'boats') {
                $titleSelector = '.title h2';
            } else {
                $titleSelector = '.layout__item h2';
            }

            $userLink = $annonce->querySelector('.result--description a');
            $userName = $userLink !== null ? trim($userLink->textContent ?? '') : '';

            $titleEl = $annonce->querySelector($titleSelector);
            $annonceTitle = $titleEl !== null ? trim($titleEl->textContent ?? '') : '';

            if ($annonceTitle === '') {
                $item['title'] = $userName;
            } else {
                $item['title'] = $userName . ' - ' . $annonceTitle;
            }

            $item['uri'] = $detailHref;

            $images = $annonce->querySelectorAll('.avatar img');
            $imagesArray = iterator_to_array($images);
            if ($imagesArray !== []) {
                $lastImage = end($imagesArray);
                $imgSrc = $lastImage->getAttribute('src') ?? '';
                if ($imgSrc !== '') {
                    $item['enclosures'] = [$imgSrc];
                }
            }

            $introInfo = $htmlDetail->querySelector('.viewer-intro--info');
            $content = $introInfo !== null ? ($introInfo->innerHTML ?? '') : '';

            $sections = $htmlDetail->querySelectorAll('.viewer-container .viewer-section');
            foreach ($sections as $section) {
                $sectionTitle = $section->querySelector('.viewer-section-title');
                if ($sectionTitle !== null) {
                    $sectionClass = $section->getAttribute('class') ?? '';
                    $classParts = explode(' ', $sectionClass);
                    $class = str_replace('viewer-', '', $classParts[0]);

                    if (in_array($class, ['apply', 'photos', 'reviews', 'contact', 'experience', 'qa'], true) === false) {
                        $titleH3 = $section->querySelector('.viewer-section-title h3');
                        $sectionContent = $section->querySelector('.viewer-section-content');

                        if ($titleH3 !== null) {
                            $content .= $htmlDetail->saveHTML($titleH3);
                        }
                        if ($sectionContent !== null) {
                            $content .= $htmlDetail->saveHTML($sectionContent);
                        }
                    }
                } else {
                    $contentH3 = $section->querySelector('.viewer-section-content h3');
                    $contentP = $section->querySelector('.viewer-section-content p');

                    if ($contentH3 !== null) {
                        $content .= $htmlDetail->saveHTML($contentH3);
                    }
                    if ($contentP !== null) {
                        $content .= $htmlDetail->saveHTML($contentP);
                    }
                }
            }

            $keywordInput = $this->getInput('keyword');
            if ($keywordInput !== null && $keywordInput !== '') {
                $keyword = strtolower((string)$keywordInput);
                if (str_contains(strtolower($item['title']), $keyword) === false) {
                    if (str_contains(strtolower($content), $keyword) === false) {
                        continue;
                    }
                }
            }

            $item['content'] = $content;

            $tags = $htmlDetail->querySelectorAll('li.viewer-tags--tag');
            foreach ($tags as $tag) {
                $text = trim($tag->textContent ?? '');
                if ($text === '') {
                    continue;
                }
                if (isset($item['categories']) === false) {
                    $item['categories'] = [];
                }
                if (in_array($text, $item['categories'], true) === false) {
                    $item['categories'][] = $text;
                }
            }

            $this->items[] = $item;
            $limit += 1;

            if ($limit === 10) {
                break;
            }
        }
    }

    public function getURI(): string
    {
        $uri = parent::getURI();

        $type = $this->getInput('type');
        if ($type === 'boats') {
            $uri .= '/boats';
        } else {
            $uri .= '/crew';
        }

        $status = $this->getInput('status');
        if ($status === 'professional') {
            $uri .= '/professional';
        } else {
            $uri .= '/recreational';
        }

        return $uri;
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
