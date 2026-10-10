<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;
use function RSSBridge\Exceptions\throwServerException;

final class WikipediaBridge extends BridgeAbstract
{
    public const NAME = 'Wikipedia bridge for many languages';
    public const URI = 'https://www.wikipedia.org/';
    public const DESCRIPTION = 'Returns articles for a language of your choice';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 10800;

    private const SUBJECT_TFA = 0;
    private const SUBJECT_DYK = 1;
    private const SUBJECT_ITD = 2;

    private const STOP_SECTIONS = [
        'См. также', 'See also', 'Voir aussi', 'Siehe auch', 'Zie ook',
        'Примечания', 'Notes', 'Notes et références', 'Einzelnachweise', 'Noten',
        'Литература', 'References', 'Bibliographie', 'Literatur', 'Literatuur',
        'Ссылки', 'External links', 'Liens externes', 'Weblinks', 'Externe links',
        'Навигационные шаблоны', 'Navigation',
    ];

    public const PARAMETERS = [
        '' => [
            'language' => [
                'name' => 'Language',
                'type' => 'list',
                'title' => 'Select your language',
                'exampleValue' => 'English',
                'values' => [
                    'English' => 'en',
                    'Русский' => 'ru',
                    'Dutch' => 'nl',
                    'Esperanto' => 'eo',
                    'French' => 'fr',
                    'German' => 'de',
                ],
            ],
            'subject' => [
                'name' => 'Subject',
                'type' => 'list',
                'title' => 'What subject are you interested in?',
                'exampleValue' => 'Today\'s featured article',
                'values' => [
                    'Today\'s featured article' => 'tfa',
                    'Did you know…' => 'dyk',
                    'In this day' => 'itd',
                ],
            ],
            'fullarticle' => [
                'name' => 'Load full article',
                'type' => 'checkbox',
                'title' => 'Activate to always load the full article',
                'defaultValue' => false,
            ],
        ],
    ];

    public function getName(): string
    {
        $language = (string)$this->getInput('language');
        $subject = (string)$this->getInput('subject');

        if ($subject === 'tfa') {
            return "Today's featured article from {$language}.wikipedia.org";
        }

        if ($subject === 'dyk') {
            return "Did you know? - articles from {$language}.wikipedia.org";
        }

        if ($subject === 'itd') {
            return "In this day from {$language}.wikipedia.org";
        }

        return parent::getName();
    }

    public function getURI(): string
    {
        $language = $this->getInput('language');
        if ($language !== null && is_string($language) === true && $language !== '') {
            return 'https://' . strtolower($language) . '.wikipedia.org';
        }

        return parent::getURI();
    }

    public function collectData(): void
    {
        $language = (string)$this->getInput('language');
        $subject = (string)$this->getInput('subject');
        $fullArticle = (bool)$this->getInput('fullarticle');

        $subjectId = match ($subject) {
            'tfa' => self::SUBJECT_TFA,
            'dyk' => self::SUBJECT_DYK,
            'itd' => self::SUBJECT_ITD,
            default => self::SUBJECT_TFA,
        };

        $baseUri = $this->getURI();
        $html = getSimpleHTMLDOM($baseUri . '/wiki');

        $function = 'getContents' . ucfirst(strtolower($language));

        if (method_exists($this, $function) === false) {
            throwServerException("A function to get the contents for your language is missing ('{$function}')!");
        }

        $this->$function($html, $subjectId, $fullArticle);
    }

    private function isInComplexStructure(\Dom\Element $element): bool
    {
        $current = $element;
        while ($current !== null && $current instanceof \Dom\Element) {
            $class = $current->getAttribute('class') ?? '';
            if (
                str_contains($class, 'infobox') === true ||
                str_contains($class, 'clade') === true ||
                str_contains($class, 'cladogram') === true
            ) {
                return true;
            }
            $current = $current->parentElement;
        }
        return false;
    }

    private function cleanHtmlNode(\Dom\Element $node): void
    {
        $selectorsToRemove = [
            'style',
            'script',
            '.mw-editsection',
            'sup.reference',
            '.noprint',
            'link',
            '[href*="Проект:Избранные_статьи/Кандидаты"]',
            '[href*="Шаблон:Текущая_избранная_статья"]',
            '[src*="OOjs_UI_icon_ellipsis.svg"]',
            '.item-content div div div',
        ];

        foreach ($node->querySelectorAll(implode(', ', $selectorsToRemove)) as $service) {
            $service->remove();
        }

        foreach ($node->querySelectorAll('*') as $el) {
            if ($this->isInComplexStructure($el) === false) {
                $el->removeAttribute('style');
            }
            $el->removeAttribute('typeof');
            $el->removeAttribute('about');
            $el->removeAttribute('data-mw');
            $el->removeAttribute('data-mw-section-id');
            $el->removeAttribute('id');
            $el->removeAttribute('class');
        }
    }

    private function processQuotesAndFloats(\Dom\Element $content): void
    {
        foreach ($content->querySelectorAll('.ts-Начало_цитаты-quote, .quote, blockquote') as $quote) {
            $quote->setAttribute('style', 'border-left: 4px solid #ccc; margin: 1em 0; padding: 0.5em 1em; background: #f9f9f9;');
            $quote->removeAttribute('class');
        }

        foreach ($content->querySelectorAll('.floatright') as $float) {
            $float->setAttribute('style', 'float: right; margin: 0 0 1em 1em;');
            $float->removeAttribute('class');
        }
    }

    private function replaceUriInHtmlElement(string $html): string
    {
        $baseUri = $this->getURI();
        $html = preg_replace('/href="\/wiki\//', 'href="' . $baseUri . '/wiki/', $html);
        $html = preg_replace('/href="\//', 'href="' . $baseUri . '/', $html);
        $html = preg_replace('/src="\/\//', 'src="https://', $html);
        return $html;
    }

    private function addTodaysFeaturedArticleGeneric(
        \Dom\Element $element,
        bool $fullArticle,
        string $anchorText = '...',
        int $anchorFallbackIndex = 0
    ): void {
        $element = clone $element;

        $lastUl = $element->querySelector('ul:last-of-type');
        if ($lastUl !== null) {
            $lastUl->remove();
        } else {
            $lastDiv = $element->querySelector('div:last-of-type');
            if ($lastDiv !== null) {
                $lastDiv->remove();
            }
        }

        $target = null;
        $anchors = $element->querySelectorAll('p a');

        foreach ($anchors as $anchor) {
            $anchorTextContent = trim($anchor->textContent ?? '');
            if ($anchorTextContent === $anchorText || str_ends_with($anchorTextContent, $anchorText) === true) {
                $target = $anchor;
                break;
            }
        }

        if ($target === null && count($anchors) > abs($anchorFallbackIndex)) {
            $index = $anchorFallbackIndex < 0 ? count($anchors) + $anchorFallbackIndex : $anchorFallbackIndex;
            $target = $anchors[$index];
        }

        if ($target === null) {
            return;
        }

        $href = $target->getAttribute('href');
        if ($href === null) {
            return;
        }

        $item = [];
        $item['uri'] = urljoin($this->getURI(), $href);

        $linkTitle = $target->getAttribute('title');
        if ($linkTitle !== null && $linkTitle !== '') {
            $item['title'] = $linkTitle;
        } else {
            $item['title'] = trim($target->textContent ?? 'Untitled');
        }

        if ($fullArticle === false) {
            $this->cleanHtmlNode($element);
            $html = $this->replaceUriInHtmlElement($element->innerHTML);
            $item['content'] = strip_tags($html, '<a><p><br><img><b><i><em><strong>');
        } else {
            $item['content'] = $this->loadFullArticle($item['uri']);
        }

        $this->items[] = $item;
    }

    private function addDidYouKnowGeneric(\Dom\Element $element, bool $fullArticle): void
    {
        $ul = $element->querySelector('ul');
        if ($ul === null) {
            return;
        }

        foreach ($ul->querySelectorAll('li') as $entry) {
            $firstAnchor = $entry->querySelector('a');
            if ($firstAnchor === null) {
                continue;
            }

            $href = $firstAnchor->getAttribute('href');
            if ($href === null) {
                continue;
            }

            $item = [];
            $item['uri'] = urljoin($this->getURI(), $href);
            $item['title'] = trim($entry->textContent ?? '');

            if ($fullArticle === false) {
                $this->cleanHtmlNode($entry);
                $item['content'] = $this->replaceUriInHtmlElement($entry->innerHTML);
            } else {
                $item['content'] = $this->loadFullArticle($item['uri']);
            }

            $this->items[] = $item;
        }
    }

    private function addInThisDayGeneric(\Dom\Element $element): void
    {
        $element = clone $element;

        $title = 'In this day';
        $heading = $element->querySelector('h2, h3, .mp-h2, .mp-h3');
        if ($heading !== null) {
            $title = trim($heading->textContent ?? 'In this day');
            $heading->remove();
        }

        $firstP = $element->querySelector('p');
        if ($firstP !== null) {
            $text = trim($firstP->textContent ?? '');
            if (preg_match('/^(В этот день|On this day|En ce jour|Heute|Op deze dag)/i', $text) === 1) {
                $firstP->remove();
            }
        }

        $uri = $this->getURI();
        $dateLink = $element->querySelector('a[href*="/wiki/"]');
        if ($dateLink !== null) {
            $href = $dateLink->getAttribute('href');
            if ($href !== null) {
                $uri = urljoin($this->getURI(), $href);
            }
        }

        $this->cleanHtmlNode($element);
        $this->processQuotesAndFloats($element);

        $html = $this->replaceUriInHtmlElement($element->innerHTML);

        $this->items[] = [
            'title' => $title,
            'uri' => $uri,
            'content' => $html,
            'timestamp' => time(),
        ];
    }

    private function loadFullArticle(string $uri): string
    {
        $contentHtml = getSimpleHTMLDOMCached($uri);

        if ($contentHtml === null) {
            throwServerException('Could not load site: ' . $uri . '!');
        }

        $content = $contentHtml->querySelector('#mw-content-text .mw-parser-output');

        if ($content === null) {
            throwServerException('Could not find content in page: ' . $uri . '!');
        }

        $content = clone $content;

        $infoboxHtml = '';
        $infoboxes = $content->querySelectorAll('table.infobox');
        foreach ($infoboxes as $infobox) {
            $infoboxClone = clone $infobox;

            foreach ($infoboxClone->querySelectorAll('.mw-editsection, sup.reference, .noprint') as $service) {
                $service->remove();
            }

            foreach ($infoboxClone->querySelectorAll('*') as $el) {
                $el->removeAttribute('typeof');
                $el->removeAttribute('about');
                $el->removeAttribute('data-mw');
                $el->removeAttribute('id');
                $class = $el->getAttribute('class');
                if ($class !== null && str_contains($class, 'infobox') === false) {
                    $el->removeAttribute('class');
                }
            }

            $tableStyles = 'border-collapse: collapse; border: 1px solid #ccc; font-size: 90%; max-width: 300px;';
            $infoboxClone->setAttribute('style', $tableStyles);

            $infoboxHtml .= $infoboxClone->outerHTML;
            $infobox->remove();
        }

        $toc = $content->querySelector('#toc');
        if ($toc !== null) {
            $toc->remove();
        }

        $sections = $content->querySelectorAll('section');
        if (count($sections) > 0) {
            foreach ($sections as $section) {
                $heading = $section->querySelector('h2, h3');
                if ($heading !== null) {
                    $headingText = trim($heading->textContent ?? '');

                    $isStopSection = false;
                    foreach (self::STOP_SECTIONS as $stopSection) {
                        if (mb_strpos($headingText, $stopSection) !== false) {
                            $isStopSection = true;
                            break;
                        }
                    }

                    if ($isStopSection === true) {
                        $section->remove();
                    }
                }
            }
        } else {
            $this->trimArticleAtStopSections($content);
        }

        foreach ($content->querySelectorAll('.navbox, .navbox-container, .vertical-navbox, .sidebar, table.metadata, .sistersitebox, .catlinks, .printfooter') as $nav) {
            $nav->remove();
        }

        $this->processQuotesAndFloats($content);

        $this->cleanHtmlNode($content);

        $html = '';

        if ($infoboxHtml !== '') {
            $html .= '<div style="float:right; margin-left:1em; margin-bottom:1em; max-width:300px;">' . $infoboxHtml . '</div>';
        }

        $html .= $this->replaceUriInHtmlElement($content->innerHTML);

        return $html;
    }

    private function trimArticleAtStopSections(\Dom\Element $content): void
    {
        $foundStop = false;
        $elementsToRemove = [];

        foreach ($content->childNodes as $child) {
            if ($child instanceof \Dom\Element === false) {
                continue;
            }

            if (in_array($child->tagName, ['h2', 'h3'], true) === true) {
                $headingText = trim($child->textContent ?? '');

                foreach (self::STOP_SECTIONS as $stopSection) {
                    if (mb_strpos($headingText, $stopSection) !== false) {
                        $foundStop = true;
                        break;
                    }
                }
            }

            if ($foundStop === true) {
                $elementsToRemove[] = $child;
            }
        }

        foreach ($elementsToRemove as $element) {
            $element->remove();
        }
    }

    private function getContentsDe(\Dom\HTMLDocument $html, int $subject, bool $fullArticle): void
    {
        if ($subject === self::SUBJECT_TFA) {
            $element = $html->querySelector('div#artikel div.hauptseite-box-content');
            if ($element !== null) {
                $this->addTodaysFeaturedArticleGeneric($element, $fullArticle);
            }
        } elseif ($subject === self::SUBJECT_DYK) {
            $element = $html->querySelector('div#wissenswertes');
            if ($element !== null) {
                $this->addDidYouKnowGeneric($element, $fullArticle);
            }
        } elseif ($subject === self::SUBJECT_ITD) {
            $element = $html->querySelector('div#geschichtlich');
            if ($element !== null) {
                $this->addInThisDayGeneric($element);
            }
        }
    }

    private function getContentsFr(\Dom\HTMLDocument $html, int $subject, bool $fullArticle): void
    {
        if ($subject === self::SUBJECT_TFA) {
            $element = $html->querySelector('div.accueil_2017_cadre');
            if ($element !== null) {
                $this->addTodaysFeaturedArticleGeneric($element, $fullArticle, 'Lire la suite');
            }
        } elseif ($subject === self::SUBJECT_DYK) {
            $elements = $html->querySelectorAll('div.accueil_2017_cadre');
            if (count($elements) > 2) {
                $this->addDidYouKnowGeneric($elements[2], $fullArticle);
            }
        } elseif ($subject === self::SUBJECT_ITD) {
            $element = $html->querySelector('div#actualites');
            if ($element !== null) {
                $this->addInThisDayGeneric($element);
            }
        }
    }

    private function getContentsEn(\Dom\HTMLDocument $html, int $subject, bool $fullArticle): void
    {
        if ($subject === self::SUBJECT_TFA) {
            $element = $html->querySelector('div#mp-tfa');
            if ($element !== null) {
                $this->addTodaysFeaturedArticleGeneric($element, $fullArticle, '...', -1);
            }
        } elseif ($subject === self::SUBJECT_DYK) {
            $element = $html->querySelector('div#mp-dyk');
            if ($element !== null) {
                $this->addDidYouKnowGeneric($element, $fullArticle);
            }
        } elseif ($subject === self::SUBJECT_ITD) {
            $element = $html->querySelector('div#mp-itn');
            if ($element !== null) {
                $this->addInThisDayGeneric($element);
            }
        }
    }

    private function getContentsRu(\Dom\HTMLDocument $html, int $subject, bool $fullArticle): void
    {
        if ($subject === self::SUBJECT_TFA) {
            $element = $html->querySelector('div#main-tfa');
            if ($element !== null) {
                $this->addTodaysFeaturedArticleGeneric($element, $fullArticle, '...', -1);
            }
        } elseif ($subject === self::SUBJECT_DYK) {
            $element = $html->querySelector('div#main-dyk');
            if ($element !== null) {
                $this->addDidYouKnowGeneric($element, $fullArticle);
            }
        } elseif ($subject === self::SUBJECT_ITD) {
            $element = $html->querySelector('div#main-itd');
            if ($element !== null) {
                $this->addInThisDayGeneric($element);
            }
        }
    }

    private function getContentsEo(\Dom\HTMLDocument $html, int $subject, bool $fullArticle): void
    {
        if ($subject === self::SUBJECT_TFA) {
            $element = $html->querySelector('div#mf-artikolo-de-la-monato');
            if ($element !== null) {
                $divs = $element->querySelectorAll('div');
                if (count($divs) > 1) {
                    $divs[count($divs) - 2]->remove();
                }
                $this->addTodaysFeaturedArticleGeneric($element, $fullArticle);
            }
        } elseif ($subject === self::SUBJECT_DYK) {
            $hpDivs = $html->querySelectorAll('div.hp');
            if (count($hpDivs) > 1) {
                $tables = $hpDivs[1]->querySelectorAll('table');
                if (count($tables) > 4) {
                    $tds = $tables[4]->querySelectorAll('td');
                    if (count($tds) > 0) {
                        $this->addDidYouKnowGeneric($tds[count($tds) - 1], $fullArticle);
                    }
                }
            }
        } elseif ($subject === self::SUBJECT_ITD) {
            $element = $html->querySelector('div#eventoj');
            if ($element !== null) {
                $this->addInThisDayGeneric($element);
            }
        }
    }

    private function getContentsNl(\Dom\HTMLDocument $html, int $subject, bool $fullArticle): void
    {
        if ($subject === self::SUBJECT_TFA) {
            $segment = $html->querySelector('td#segment-Uitgelicht div');
            if ($segment !== null) {
                $paragraphs = $segment->querySelectorAll('p');
                if (count($paragraphs) > 1) {
                    $paragraphs[1]->remove();
                }
                $this->addTodaysFeaturedArticleGeneric($segment, $fullArticle, 'Lees verder');
            }
        } elseif ($subject === self::SUBJECT_DYK) {
            $element = $html->querySelector('td#segment-Wist_je_dat div');
            if ($element !== null) {
                $this->addDidYouKnowGeneric($element, $fullArticle);
            }
        } elseif ($subject === self::SUBJECT_ITD) {
            $element = $html->querySelector('td#segment-In_het_nieuws div');
            if ($element !== null) {
                $this->addInThisDayGeneric($element);
            }
        }
    }
}
