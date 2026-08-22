<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use BridgeAbstract;

final class BookMyShowBridge extends BridgeAbstract
{
    public const NAME = 'BookMyShow';
    public const URI = 'https://in.bookmyshow.com';
    public const DESCRIPTION = 'Returns the latest events on BookMyShow';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 10800;

    private const TIMEZONE = 'Asia/Kolkata';
    private const PLAYS = 'PL';
    private const EVENTS = 'CT';
    private const MOVIES = 'MT';

    private const MOVIES_IMAGE_BASE_FORMAT = 'https://in.bmscdn.com/iedb/movies/images/mobile/thumbnail/large/%s.jpg';

    private const CATEGORIES = [
        'PL' => 'Plays',
        'CT' => 'Events',
        'MT' => 'Movies',
    ];

    private const URL_PREFIX = 'https://in.bookmyshow.com/serv/getData?cmd=QUICKBOOK&type=';
    private const SYNOPSIS_REGEX = '/If you [\w\s,]+synopsis\@bookmyshow\.com/';

    private const TABLE_STYLE = 'border-collapse: collapse; width: 100%; margin: 10px 0;';
    private const TH_STYLE = 'border: 1px solid #ddd; padding: 8px; text-align: left; vertical-align: top; width: 30%; font-weight: bold;';
    private const TD_STYLE = 'border: 1px solid #ddd; padding: 8px; text-align: left; vertical-align: top;';

    private const TABLE_HEADERS = [
        'Genre' => 'Genre',
        'Language' => 'Language',
        'Length' => 'Length',
        'EventIsGlobal' => 'Global Event',
        'MinPrice' => 'Minimum Price',
        'EventSoldOut' => 'Sold Out',
    ];

    private const MOVIE_TABLE_HEADERS = [
        'Duration' => 'Screentime',
        'EventCensor' => 'Rating',
    ];

    private const INNER_MOVIE_HEADERS = [
        'EventLanguage' => 'Language',
        'EventDimension' => 'Formats',
        'EventIsAtmosEnabled' => 'Dolby Atmos',
        'IsMovieClubEnabled' => 'Movie Club',
    ];

    public const PARAMETERS = [
        [
            'city' => [
                'name' => 'City',
                'type' => 'list',
                'defaultValue' => 'MUMBAI',
                'values' => [
                    'Mumbai' => 'MUMBAI',
                    'National Capital Region (NCR)' => 'NCR',
                    'Bengaluru' => 'BANG',
                    'Hyderabad' => 'HYD',
                    'Ahmedabad' => 'AHD',
                    'Chandigarh' => 'CHD',
                    'Chennai' => 'CHEN',
                    'Pune' => 'PUNE',
                    'Kolkata' => 'KOLK',
                    'Kochi' => 'KOCH',
                    'Goa' => 'GOA',
                    'Jaipur' => 'JAIP',
                    'Lucknow' => 'LUCK',
                    'Indore' => 'IND',
                    'Nagpur' => 'NAGP',
                    'Patna' => 'PATN',
                    'Surat' => 'SURT',
                    'Vadodara' => 'VAD',
                    'Coimbatore' => 'COIM',
                    'Trivandrum' => 'TRIV',
                    'Visakhapatnam' => 'VIZA',
                    'Kanpur' => 'KANP',
                ],
            ],
            'category' => [
                'name' => 'Category',
                'type' => 'list',
                'defaultValue' => 'MT',
                'values' => [
                    'Plays' => 'PL',
                    'Events' => 'CT',
                    'Movies' => 'MT',
                ],
            ],
            'language' => [
                'name' => 'Language',
                'type' => 'list',
                'defaultValue' => 'all',
                'values' => [
                    'All' => 'all',
                    'Kannada' => 'Kannada',
                    'English' => 'English',
                    'Hindi' => 'Hindi',
                    'Telugu' => 'Telugu',
                    'Tamil' => 'Tamil',
                    'Malayalam' => 'Malayalam',
                    'Gujarati' => 'Gujarati',
                    'Assamese' => 'Assamese',
                ],
            ],
            'include_online' => [
                'name' => 'Include Online Events',
                'type' => 'checkbox',
                'defaultValue' => false,
                'title' => 'Whether to include Online Events (applies only in case of "Events" category)',
            ],
        ],
    ];

    private array $languages = [];

    public function collectData(): void
    {
        $cityInput = $this->getInput('city');
        $categoryInput = $this->getInput('category');

        $city = 'MUMBAI';
        if (is_string($cityInput) === true) {
            $city = $cityInput;
        }

        $category = self::MOVIES;
        if (is_string($categoryInput) === true) {
            $category = $categoryInput;
        }

        $url = self::URL_PREFIX . $category;
        $headers = $this->makeHeaders($city);

        $responseBody = getContents($url, $headers);
        $data = json_decode($responseBody, true);

        if (is_array($data) === false) {
            throwServerException('Failed to parse JSON response');
        }

        $events = [];
        if ($category === self::MOVIES) {
            $events = $data['moviesData']['BookMyShow']['arrEvents'] ?? [];
        } else {
            $events = $data['data']['BookMyShow']['arrEvent'] ?? [];
        }

        if (is_array($events) === false) {
            throwServerException('No events found in response');
        }

        foreach ($events as $event) {
            if (is_array($event) === false) {
                continue;
            }

            $item = $this->generateEventData($event, $category);
            if ($item !== null && $this->matchesFilters($event) === true) {
                $this->items[] = $item;
            }
        }

        usort($this->items, function ($a, $b): int {
            return ($b['timestamp'] ?? 0) - ($a['timestamp'] ?? 0);
        });

        $this->items = array_slice($this->items, 0, 15);

        if ($this->items === []) {
            throwServerException('No events matched the filters');
        }
    }

    public function getName(): string
    {
        $cityInput = $this->getInput('city');
        $categoryInput = $this->getInput('category');

        if ($cityInput === null || $categoryInput === null) {
            return parent::getName();
        }

        $city = (string) $cityInput;
        $category = (string) $categoryInput;

        if (isset(self::CATEGORIES[$category]) === false) {
            return parent::getName();
        }

        $categoryName = self::CATEGORIES[$category];

        $cityNames = array_flip($this->getCities());
        $cityName = $cityNames[$city] ?? $city;

        $languageInput = $this->getInput('language');
        if ($languageInput !== null && $languageInput !== 'all') {
            $language = ucwords((string) $languageInput);
            return sprintf('BookMyShow: %s %s in %s', $language, $categoryName, $cityName);
        }

        return sprintf('BookMyShow: %s in %s', $categoryName, $cityName);
    }

    private function getCities(): array
    {
        return [
            'Mumbai' => 'MUMBAI', 'National Capital Region (NCR)' => 'NCR', 'Bengaluru' => 'BANG',
            'Hyderabad' => 'HYD', 'Ahmedabad' => 'AHD', 'Chandigarh' => 'CHD', 'Chennai' => 'CHEN',
            'Pune' => 'PUNE', 'Kolkata' => 'KOLK', 'Kochi' => 'KOCH', 'Goa' => 'GOA', 'Jaipur' => 'JAIP',
            'Lucknow' => 'LUCK', 'Indore' => 'IND', 'Nagpur' => 'NAGP', 'Patna' => 'PATN',
            'Surat' => 'SURT', 'Vadodara' => 'VAD', 'Coimbatore' => 'COIM', 'Trivandrum' => 'TRIV',
            'Visakhapatnam' => 'VIZA', 'Kanpur' => 'KANP',
        ];
    }

    private function makeHeaders(string $city): array
    {
        $uniqid = uniqid();
        $rgn = urlencode('|Code=' . $city . '|');
        return ['Cookie: bmsId=' . $uniqid . '; Rgn=' . $rgn . ';'];
    }

    private function getDatesHtml(array $dates): string
    {
        if ($dates === []) {
            return '';
        }

        $tz = new \DateTimeZone(self::TIMEZONE);
        $firstCode = $dates[0]['ShowDateCode'] ?? null;

        if ($firstCode === null) {
            return '';
        }

        $firstDate = \DateTime::createFromFormat('Ymd', (string) $firstCode, $tz);
        if ($firstDate === false) {
            return '';
        }

        $firstFormatted = $firstDate->format('D, d M Y');

        if (count($dates) === 1) {
            return '<p>Date: ' . $firstFormatted . '</p>';
        }

        $lastIndex = count($dates) - 1;
        $lastCode = $dates[$lastIndex]['ShowDateCode'] ?? null;

        if ($lastCode === null) {
            return '<p>Date: ' . $firstFormatted . '</p>';
        }

        $lastDate = \DateTime::createFromFormat('Ymd', (string) $lastCode, $tz);
        if ($lastDate === false) {
            return '<p>Date: ' . $firstFormatted . '</p>';
        }

        return '<p>Dates: ' . $firstFormatted . ' - ' . $lastDate->format('D, d M Y') . '</p>';
    }

    private function generateEventHtml(array $event, string $category): string
    {
        $dates = $event['arrDates'] ?? [];
        if (is_array($dates) === false) {
            $dates = [];
        }

        $html = $this->getDatesHtml($dates);

        if ($category === self::MOVIES) {
            $html .= $this->generateMovieHtml($event);
        } else {
            $html .= $this->generateStandardHtml($event);
        }

        $venues = $event['arrVenues'] ?? [];
        if (is_array($venues) === true) {
            $html .= $this->generateVenueHtml($venues);
        }

        return $html;
    }

    private function generateVenueHtml(array $venues): string
    {
        $html = '<h3>Venues</h3>';
        $html .= '<table style="' . self::TABLE_STYLE . '">';
        $html .= '<thead><tr>';
        $html .= '<th style="' . self::TH_STYLE . '">Venue</th>';
        $html .= '<th style="' . self::TH_STYLE . '">Directions</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($venues as $venueData) {
            if (is_array($venueData) === false) {
                continue;
            }

            $venueName = htmlspecialchars((string) ($venueData['VenueName'] ?? ''), ENT_QUOTES, 'UTF-8');
            $address = htmlspecialchars((string) ($venueData['VenueAddress'] ?? ''), ENT_QUOTES, 'UTF-8');
            $lat = $venueData['VenueLatitude'] ?? null;
            $lon = $venueData['VenueLongitude'] ?? null;

            $directions = '';
            if ($lat !== null && $lon !== null) {
                $directions = $this->generateDirectionsHtml((string) $lat, (string) $lon, $venueName);
            }

            $html .= '<tr>';
            $html .= '<td style="' . self::TD_STYLE . '">' . $venueName . '</td>';
            $html .= '<td style="' . self::TD_STYLE . '">' . $address . '<br>' . $directions . '</td>';
            $html .= '</tr>';
        }

        return $html . '</tbody></table>';
    }

    private function generateEventDetails(array $event, array $headers = self::TABLE_HEADERS): string
    {
        $html = '';

        foreach ($headers as $key => $header) {
            $value = $event[$key] ?? '';

            if ($header === 'Language') {
                $this->languages = [(string) $value];
            }

            if ($value === 'Y') {
                $value = 'Yes';
            } elseif ($value === 'N') {
                $value = 'No';
            }

            if ((string) $value === '') {
                continue;
            }

            $safeValue = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            $safeHeader = htmlspecialchars($header, ENT_QUOTES, 'UTF-8');

            $html .= '<strong>' . $safeHeader . ':</strong> ' . $safeValue . '<br>';
        }

        return $html;
    }

    private function generateStandardHtml(array $event): string
    {
        $details = $this->generateEventDetails($event);

        $imgSrc = htmlspecialchars((string) ($event['BannerURL'] ?? ''), ENT_QUOTES, 'UTF-8');

        $description = $this->extractDescription($event);

        $html = '';
        if ($imgSrc !== '') {
            $html .= '<p><img title="Event Banner" src="' . $imgSrc . '"></p>';
        }

        if ($details !== '') {
            $html .= '<p>' . $details . '</p>';
        }

        if ($description !== '') {
            $html .= '<p>' . $description . '</p>';
        }

        return $html;
    }

    private function generateInnerMovieDetails(array $data): string
    {
        $headers = ['EventLanguage', 'EventDimension'];
        $booleanHeaders = ['EventIsAtmosEnabled', 'IsMovieClubEnabled'];

        $items = [];

        foreach ($data as $row) {
            if (is_array($row) === false) {
                continue;
            }

            foreach ($headers as $header) {
                $items[$header][] = (string) ($row[$header] ?? '');
            }
            foreach ($booleanHeaders as $header) {
                $items[$header][] = (string) ($row[$header] ?? '');
            }
        }

        foreach ($headers as $header) {
            $items[$header] = array_values(array_unique($items[$header] ?? []));

            if ($header === 'EventLanguage') {
                $this->languages = $items[$header];
            }
        }

        $html = '';

        foreach ($headers as $header) {
            if (isset(self::INNER_MOVIE_HEADERS[$header]) === true) {
                $values = implode(', ', $items[$header] ?? []);
                $label = htmlspecialchars(self::INNER_MOVIE_HEADERS[$header], ENT_QUOTES, 'UTF-8');
                $html .= '<strong>' . $label . ':</strong> ' . htmlspecialchars($values, ENT_QUOTES, 'UTF-8') . '<br>';
            }
        }

        foreach ($booleanHeaders as $header) {
            if (isset(self::INNER_MOVIE_HEADERS[$header]) === true) {
                $hasYes = in_array('Y', $items[$header] ?? [], true);
                if ($hasYes === true) {
                    $label = htmlspecialchars(self::INNER_MOVIE_HEADERS[$header], ENT_QUOTES, 'UTF-8');
                    $html .= '<strong>' . $label . ':</strong> Yes<br>';
                }
            }
        }

        return $html;
    }

    private function generateMovieHtml(array $eventGroup): string
    {
        $childEvents = $eventGroup['ChildEvents'] ?? [];
        if (is_array($childEvents) === false || $childEvents === []) {
            return '';
        }

        $data = $childEvents[0];
        if (is_array($data) === false) {
            return '';
        }

        $details = $this->generateEventDetails($data, self::MOVIE_TABLE_HEADERS);

        $imageCode = (string) ($data['EventImageCode'] ?? '');
        $imgSrc = htmlspecialchars(sprintf(self::MOVIES_IMAGE_BASE_FORMAT, $imageCode), ENT_QUOTES, 'UTF-8');

        $innerHtml = $this->generateInnerMovieDetails($childEvents);

        $description = $this->extractDescription($eventGroup, $data);

        $eventTrailerURL = htmlspecialchars((string) ($data['EventTrailerURL'] ?? ''), ENT_QUOTES, 'UTF-8');

        $html = '';
        if ($imgSrc !== '') {
            $html .= '<p><img title="Movie Poster" src="' . $imgSrc . '"></p>';
        }

        if ($details !== '') {
            $html .= '<p>' . $details . '</p>';
        }

        $html .= '<p>' . $innerHtml . '</p>';

        if ($description !== '') {
            $html .= '<p>' . $description . '</p>';
        }

        if ($eventTrailerURL !== '') {
            $html .= '<p><a href="' . $eventTrailerURL . '" title="Trailer URL">Watch Trailer</a></p>';
        }

        return $html;
    }

    private function extractDescription(array $eventGroup, array $childData = []): string
    {
        $descriptionFields = [
            'EventSynopsis',
            'EventDescription',
            'description',
            'longDescription',
            'about',
            'synopsis',
        ];

        foreach ($descriptionFields as $field) {
            if (isset($childData[$field]) === true && is_string($childData[$field]) === true && $childData[$field] !== '') {
                return $this->cleanDescription($childData[$field]);
            }

            if (isset($eventGroup[$field]) === true && is_string($eventGroup[$field]) === true && $eventGroup[$field] !== '') {
                return $this->cleanDescription($eventGroup[$field]);
            }
        }

        return '';
    }

    private function cleanDescription(string $desc): string
    {
        $desc = preg_replace(self::SYNOPSIS_REGEX, '', $desc) ?? $desc;

        $desc = preg_replace('/<br\s*\/?>/i', ' ', $desc) ?? $desc;

        $desc = strip_tags($desc);

        $desc = preg_replace('/[\x{200B}-\x{200F}\x{2028}-\x{202F}\x{FEFF}\x{00A0}\x{2060}]/u', ' ', $desc) ?? $desc;

        $desc = preg_replace('/\s+/u', ' ', $desc) ?? $desc;

        $desc = trim($desc);

        return htmlspecialchars($desc, ENT_QUOTES, 'UTF-8');
    }

    private function generateMovieUrl(array $eventGroup): string
    {
        $urlTitle = (string) ($eventGroup['EventURLTitle'] ?? '');
        $eventCode = (string) ($eventGroup['EventCode'] ?? '');
        return self::URI . '/movies/' . $urlTitle . '/' . $eventCode;
    }

    private function generateMoviesData(array $eventGroup): ?array
    {
        $childEvents = $eventGroup['ChildEvents'] ?? [];
        if (is_array($childEvents) === false || $childEvents === []) {
            return null;
        }

        $data = $childEvents[0];
        if (is_array($data) === false) {
            return null;
        }

        $eventDate = (string) ($data['EventDate'] ?? '');
        $date = $eventDate !== '' ? new \DateTime($eventDate) : new \DateTime();

        $genreString = (string) ($eventGroup['EventGrpGenre'] ?? '');
        $categories = array_filter(
            explode('|', ucwords(strtolower($genreString), '|'))
        );
        $categories = array_values($categories);

        return [
            'uri' => $this->generateMovieUrl($eventGroup),
            'title' => (string) ($eventGroup['EventTitle'] ?? 'Untitled'),
            'timestamp' => (int) $date->format('U'),
            'author' => 'BookMyShow',
            'content' => $this->generateMovieHtml($eventGroup),
            'categories' => $categories,
            'uid' => (string) ($eventGroup['EventGroup'] ?? md5($this->generateMovieUrl($eventGroup))),
        ];
    }

    private function generateEventData(array $event, string $category): ?array
    {
        if ($category === self::MOVIES) {
            return $this->generateMoviesData($event);
        }

        return $this->generateGenericEventData($event, $category);
    }

    private function generateGenericEventData(array $event, string $category): ?array
    {
        $datetime = (string) ($event['Event_dtmCreated'] ?? '');
        if ($datetime === '') {
            return null;
        }

        try {
            $date = new \DateTime($datetime);
        } catch (\Exception $e) {
            return null;
        }

        if (isset(self::CATEGORIES[$category]) === false) {
            return null;
        }

        $genreArray = $event['GenreArray'] ?? [];
        if (is_array($genreArray) === false) {
            $genreArray = [];
        }

        $categories = array_merge([self::CATEGORIES[$category]], $genreArray);

        return [
            'uri' => (string) ($event['FShareURL'] ?? self::URI),
            'title' => (string) ($event['EventTitle'] ?? 'Untitled'),
            'timestamp' => (int) $date->format('U'),
            'author' => 'BookMyShow',
            'content' => $this->generateEventHtml($event, $category),
            'categories' => $categories,
            'uid' => (string) ($event['EventGroupCode'] ?? md5((string) ($event['FShareURL'] ?? ''))),
        ];
    }

    private function isEventOnline(array $event): bool
    {
        $venues = $event['arrVenues'] ?? [];
        if (is_array($venues) === false || count($venues) !== 1) {
            return false;
        }

        $venueName = (string) ($venues[0]['VenueName'] ?? '');
        return preg_match('/(Online|Zoom)/i', $venueName) === 1;
    }

    private function matchesLanguage(): bool
    {
        $languageInput = $this->getInput('language');

        if ($languageInput === null || $languageInput === 'all') {
            return true;
        }

        $language = (string) $languageInput;
        return in_array($language, $this->languages, true);
    }

    private function matchesOnline(array $event): bool
    {
        $includeOnlineInput = $this->getInput('include_online');
        $includeOnline = $includeOnlineInput === true || $includeOnlineInput === 'on';

        if ($includeOnline === true) {
            return true;
        }

        return $this->isEventOnline($event) === false;
    }

    private function matchesFilters(array $event): bool
    {
        return $this->matchesLanguage() === true && $this->matchesOnline($event) === true;
    }

    private function generateDirectionsHtml(string $lat, string $long, string $address = ''): string
    {
        $encodedAddress = urlencode($address);

        $links = [
            'Apple Maps' => 'http://maps.apple.com/maps?q=%s,%s',
            'Google Maps' => 'http://maps.google.com/maps?ll=%s,%s',
            'OpenStreetMap' => 'https://www.openstreetmap.org/?mlat=%s&mlon=%s&zoom=12',
            'GeoURI' => 'geo:%s,%s?q=%s',
        ];

        $locations = [];

        foreach ($links as $app => $str) {
            $url = sprintf($str, $lat, $long, $encodedAddress);
            $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            $safeApp = htmlspecialchars($app, ENT_QUOTES, 'UTF-8');
            $locations[] = '<a href="' . $safeUrl . '" title="' . $safeApp . '">' . $safeApp . '</a>';
        }

        return implode(', ', $locations);
    }
}
