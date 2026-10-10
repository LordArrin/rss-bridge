<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;
use RSSBridge\Configuration;
use RSSBridge\FeedParser;
use RSSBridge\Http\HttpException;
use RSSBridge\Http\Response;
use RSSBridge\Json;
use function RSSBridge\Exceptions\throwServerException;

final class FLZBridge extends BridgeAbstract
{
    public const NAME = 'Fränkische Landeszeitung';
    public const URI = 'https://www.flz.de/';
    public const DESCRIPTION = 'West Middle Franconia by section and location, without PR publications. FLZ+ full text with stored credentials';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 1800;

    public const CONFIGURATION = [
        'username' => ['required' => false],
        'password' => ['required' => false],
    ];

    public const CONTEXT = 'Build Feed';

    public const PARAMETERS = [
        self::CONTEXT => [
            'r_blaulicht' => [
                'name' => 'Section: Emergency Services',
                'type' => 'checkbox',
            ],
            'r_bayern' => [
                'name' => 'Section: Bavaria',
                'type' => 'checkbox',
            ],
            'r_deutschlandunddiewelt' => [
                'name' => 'Section: Germany & World',
                'type' => 'checkbox',
            ],
            'r_sportausallerwelt' => [
                'name' => 'Section: Sports Worldwide',
                'type' => 'checkbox',
            ],
            'r_wirtschaft' => [
                'name' => 'Section: Business',
                'type' => 'checkbox',
            ],
            'r_gastro' => [
                'name' => 'Section: Food & Dining',
                'type' => 'checkbox',
            ],
            'r_ratgeber' => [
                'name' => 'Section: Advice',
                'type' => 'checkbox',
            ],
            'g_stadt_ansbach' => [
                'name' => 'Locations: City of Ansbach',
                'type' => 'checkbox',
                'title' => '1 location',
            ],
            'g_lk_ansbach' => [
                'name' => 'Locations: District of Ansbach',
                'type' => 'checkbox',
                'title' => '55 locations: Adelshofen, Arberg, Aurach and more',
            ],
            'g_lk_nea' => [
                'name' => 'Locations: District of NEA-Bad Windsheim',
                'type' => 'checkbox',
                'title' => '35 locations: Bad Windsheim, Baudenbach, Burgbernheim and more',
            ],
            'g_umland' => [
                'name' => 'Locations: Surrounding Area',
                'type' => 'checkbox',
                'title' => '13 locations: Creglingen, Erlangen, Fürth and more',
            ],
            'more_places' => [
                'name' => 'Individual Locations',
                'type' => 'text',
                'required' => false,
                'title' => 'Comma-separated if you don\'t want the entire district',
                'exampleValue' => 'Insingen, Sugenheim',
            ],
            'filter_ads' => [
                'name' => 'Filter PR Publications',
                'type' => 'checkbox',
                'defaultValue' => 'checked',
                'title' => 'Removes paid special publications, about 38% of posts in Business and Food & Dining',
            ],
            'fulltext' => [
                'name' => 'Full Text Instead of Teaser',
                'type' => 'checkbox',
                'title' => 'Fetches each article individually. FLZ+ articles require credentials in bridge configuration, otherwise they remain teasers',
            ],
            'limit' => self::LIMIT,
        ],
    ];

    public const SECTIONS = [
        'r_blaulicht' => 'blaulicht',
        'r_bayern' => 'bayern',
        'r_deutschlandunddiewelt' => 'deutschlandunddiewelt',
        'r_sportausallerwelt' => 'sportausallerwelt',
        'r_wirtschaft' => 'wirtschaft',
        'r_gastro' => 'gastro',
        'r_ratgeber' => 'ratgeber',
    ];

    public const PLACES = [
        'g_stadt_ansbach' => ['Ansbach'],
        'g_lk_ansbach' => [
            'Adelshofen', 'Arberg', 'Aurach', 'Bechhofen', 'Bruckberg', 'Buch am Wald',
            'Burgoberbach', 'Burk', 'Colmberg', 'Dentlein am Forst', 'Diebach', 'Dietenhofen',
            'Dinkelsbühl', 'Dombühl', 'Dürrwangen', 'Ehingen', 'Feuchtwangen', 'Flachslanden',
            'Gebsattel', 'Gerolfingen', 'Geslau', 'Heilsbronn', 'Herrieden', 'Insingen',
            'Langfurth', 'Lehrberg', 'Leutershausen', 'Lichtenau', 'Merkendorf', 'Mitteleschenbach',
            'Mönchsroth', 'Neuendettelsau', 'Neusitz', 'Oberdachstetten', 'Ohrenbach',
            'Petersaurach', 'Rothenburg', 'Rügland', 'Sachsen bei Ansbach', 'Schillingsfürst',
            'Schnelldorf', 'Schopfloch', 'Steinsfeld', 'Wassertrüdingen', 'Weidenbach',
            'Weihenzell', 'Weiltingen', 'Wettringen', 'Wieseth', 'Wilburgstetten',
            'Windelsbach', 'Windsbach', 'Wittelshofen', 'Wolframs-Eschenbach', 'Wörnitz',
        ],
        'g_lk_nea' => [
            'Bad Windsheim', 'Baudenbach', 'Burgbernheim', 'Burghaslach', 'Dachsbach',
            'Diespeck', 'Dietersheim', 'Emskirchen', 'Ergersheim', 'Gallmersgarten',
            'Gerhardshofen', 'Gollhofen', 'Gutenstetten', 'Hagenbüchach', 'Hemmersheim',
            'Illesheim', 'Ipsheim', 'Langenfeld', 'Markt Bibart', 'Markt Erlbach',
            'Markt Nordheim', 'Markt Taschendorf', 'Marktbergel', 'Münchsteinach', 'Neuhof',
            'Oberickelsheim', 'Oberscheinfeld', 'Scheinfeld', 'Simmershofen', 'Sugenheim',
            'Trautskirchen', 'Uehlfeld', 'Uffenheim', 'Weigenheim', 'Wilhelmsdorf',
        ],
        'g_umland' => [
            'Creglingen', 'Erlangen', 'Fürth', 'Gunzenhausen', 'München', 'Neustadt/Aisch',
            'Nürnberg', 'Roth', 'Schwabach', 'Treuchtlingen', 'Weißenburg', 'Wilhermsdorf',
            'Würzburg',
        ],
    ];

    public const AD_SECTION = '/sonderthemen';

    private const RSS_ENDPOINT = 'https://www.flz.de/api/content/public/rss?path=';
    private const SSO_URI = 'https://sso.flz.de/auth/authorize?client_id=pscontent-portal&redirect_uri=https://www.flz.de/abo_service';
    private const LOGON_STATUS_URI = 'https://sso.flz.de/auth/logonstatus';

    private const REQUEST_INTERVAL_S = 0.2;
    private const RATE_LIMIT_RETRIES = 3;
    private const RATE_LIMIT_BACKOFF_S = 1;

    private const DEADLINE_S = 42;
    private const MIN_REQUEST_S = 3;
    private const FEED_SHARE = 0.8;

    private const ARTICLE_CACHE_TTL = 21600;
    private const LOGIN_LOCK_TTL = 21600;

    private string $cookie = '';
    private string $loginProblem = '';
    private float $started = 0.0;
    private float $lastRequest = 0.0;
    private array $skippedFeeds = [];

    public function collectData(): void
    {
        $this->started = microtime(true);

        $fulltext = (bool) $this->getInput('fulltext');
        if ($fulltext === true) {
            $this->login();
        }

        $filterAds = (bool) $this->getInput('filter_ads');
        $ads = $filterAds === true ? $this->collectAds() : [];
        $feeds = $this->getFeeds();

        $items = [];
        $fetched = 0;

        foreach ($feeds as $path => $label) {
            $entries = $this->collectFeed($path);
            if ($entries === null) {
                continue;
            }
            $fetched++;

            foreach ($entries as $item) {
                $id = $item['uid'];
                if (isset($ads[$id]) === true) {
                    continue;
                }
                if (isset($items[$id]) === true) {
                    $items[$id]['categories'][] = $label;
                    continue;
                }
                $item['categories'] = [$label];
                $items[$id] = $item;
            }
        }

        if ($fetched === 0) {
            throwServerException(sprintf('None of the %d selected flz.de feeds could be fetched', count($feeds)));
        }

        usort($items, fn($a, $b) => ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0));
        $limit = (int) $this->getInput('limit');
        $items = array_slice($items, 0, $limit > 0 ? $limit : 50);

        foreach ($items as $item) {
            if ($fulltext === true) {
                $item['content'] = $this->collectFullContent($item);
            }
            $this->items[] = $item;
        }

        if ($this->skippedFeeds !== []) {
            $this->logger->warning(sprintf(
                'FLZ: %d of %d feeds are incomplete (throttled, or out of time after %.1f s): %s',
                count($this->skippedFeeds),
                count($feeds),
                microtime(true) - $this->started,
                implode(', ', $this->skippedFeeds)
            ));
        }
    }

    public function getName(): string
    {
        $fields = $this->checkedFields(array_merge(array_keys(self::SECTIONS), array_keys(self::PLACES)));
        $names = array_merge(
            array_map([$this, 'fieldLabel'], $fields),
            $this->getIndividualPlace()
        );

        return $names !== [] ? self::NAME . ': ' . implode(' · ', $names) : parent::getName();
    }

    public function getURI(): string
    {
        $feeds = $this->getFeeds();
        if (count($feeds) !== 1) {
            return parent::getURI();
        }

        $firstPath = array_key_first($feeds);
        $segments = explode('/', trim((string) $firstPath, '/'));

        return self::URI . implode('/', array_map(
            fn($segment) => rawurlencode(mb_strtolower($segment)),
            $segments
        ));
    }

    public function detectParameters($url): ?array
    {
        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH);

        if (in_array($host, ['flz.de', 'www.flz.de'], true) === false || is_string($path) === false) {
            return null;
        }

        $segments = explode('/', trim($path, '/'));

        if (count($segments) === 1) {
            $field = array_search(strtolower($segments[0]), self::SECTIONS, true);
            return $field !== false ? ['context' => self::CONTEXT, $field => 'on'] : null;
        }

        if (count($segments) === 2 && $segments[0] === 'orte' && $segments[1] !== '') {
            return ['context' => self::CONTEXT, 'more_places' => $this->placeFromPath(rawurldecode($segments[1]))];
        }

        return null;
    }

    private function getFeeds(): array
    {
        $sections = $this->checkedFields(array_keys(self::SECTIONS));
        $places = $this->getIndividualPlace();

        foreach ($this->checkedFields(array_keys(self::PLACES)) as $group) {
            $places = array_merge($places, self::PLACES[$group]);
        }

        if ($sections === [] && $places === []) {
            $sections = array_keys(self::SECTIONS);
        }

        $feeds = [];

        foreach ($sections as $field) {
            $feeds['/' . self::SECTIONS[$field]] = $this->fieldLabel($field);
        }

        foreach ($places as $place) {
            $feeds[$this->placePath($place)] = $place;
        }

        return $feeds;
    }

    private function checkedFields(array $fields): array
    {
        return array_values(array_filter($fields, fn($field) => (bool) $this->getInput($field)));
    }

    private function getIndividualPlace(): array
    {
        $input = $this->getInput('more_places');
        if (is_string($input) === false || $input === '') {
            return [];
        }

        $places = [];
        foreach (explode(',', $input) as $place) {
            $place = trim($place);
            if ($place !== '') {
                $places[] = $this->canonicalPlace($place);
            }
        }

        return array_values(array_unique($places));
    }

    private function canonicalPlace(string $name): string
    {
        $normalize = fn(string $place) => mb_strtolower(str_replace('-', ' ', $place));
        $normalizedName = $normalize($name);

        foreach (self::PLACES as $places) {
            foreach ($places as $place) {
                if ($normalize($place) === $normalizedName) {
                    return $place;
                }
            }
        }

        return $name;
    }

    private function placePath(string $place): string
    {
        return '/orte/' . str_replace(['/', ' '], ['_slash_', '-'], $place);
    }

    private function placeFromPath(string $slug): string
    {
        return $this->canonicalPlace(str_replace(['_slash_', '-'], ['/', ' '], $slug));
    }

    private function fieldLabel(string $field): string
    {
        $name = self::PARAMETERS[self::CONTEXT][$field]['name'] ?? $field;
        return explode(': ', $name, 2)[1] ?? $name;
    }

    private function collectFeed(string $path): ?array
    {
        $xml = $this->fetchFeed($path);
        if ($xml === null) {
            return null;
        }

        try {
            $parser = new FeedParser();
            $feed = $parser->parseFeed($xml);
        } catch (\Exception $e) {
            $this->logger->info(sprintf('FLZ: feed %s is not valid: %s', $path, $e->getMessage()));
            return null;
        }

        $items = [];

        foreach ($feed['items'] as $entry) {
            $uri = trim((string) ($entry['uri'] ?? ''));
            $title = trim((string) ($entry['title'] ?? ''));

            if ($uri === '' || $title === '') {
                continue;
            }

            $image = $entry['enclosures'][0] ?? '';
            $teaser = trim((string) ($entry['content'] ?? ''));

            $item = [
            'uri' => $uri,
            'uid' => $this->articleId($uri),
            'title' => $title,
            'content' => $this->buildContent(
                (string) $image,
                $teaser !== '' ? '<p>' . e($teaser) . '</p>' : ''
            ),
            ];

            if (isset($entry['timestamp']) === true) {
                $item['timestamp'] = $entry['timestamp'];
            }

            $items[] = $item;
        }

        return $items;
    }

    private function fetchFeed(string $path): ?string
    {
        $url = self::RSS_ENDPOINT . rawurlencode(base64_encode($path));

        for ($attempt = 0; $this->hasTimeLeft(self::FEED_SHARE) === true; $attempt++) {
            try {
                return $this->fetch($url);
            } catch (\Exception $e) {
                if ($e instanceof HttpException === false || $e->getCode() !== 429) {
                    $this->logger->info(sprintf('FLZ: feed %s not available: %s', $path, $e->getMessage()));
                    return null;
                }
            }

            $backoff = self::RATE_LIMIT_BACKOFF_S * (2 ** $attempt);
            if ($attempt === self::RATE_LIMIT_RETRIES || $this->secondsLeft(self::FEED_SHARE) - $backoff < self::MIN_REQUEST_S) {
                break;
            }

            sleep((int) $backoff);
        }

        $this->skippedFeeds[] = $path;
        return null;
    }

    private function articleId(string $url): string
    {
        if (preg_match('~/cnt-id-([\w-]+)~', $url, $match) === 1) {
            return $match[1];
        }
        return $url;
    }

    private function collectAds(): array
    {
        $ads = [];

        $sonderthemenFeed = $this->collectFeed(self::AD_SECTION);
        if ($sonderthemenFeed !== null) {
            foreach ($sonderthemenFeed as $item) {
                $ads[$item['uid']] = true;
            }
        }

        foreach ([self::URI, self::URI . 'orte'] as $url) {
            if ($this->hasTimeLeft(self::FEED_SHARE) === false) {
                break;
            }

            try {
                $html = $this->fetch($url);
                $dom = \Dom\HTMLDocument::createFromString($html);
            } catch (\Exception $e) {
                $this->logger->info(sprintf('FLZ: overview %s not available: %s', $url, $e->getMessage()));
                continue;
            }

            foreach ($dom->querySelectorAll('*') as $node) {
                if ($node instanceof \Dom\Element === false) {
                    continue;
                }

                $tagName = strtolower($node->tagName);
                if (str_ends_with($tagName, '-article-preview') === false) {
                    continue;
                }

                $innerHtml = (string) $node->innerHTML;
                if (str_contains($innerHtml, 'cat-ad-labeling') === false && str_contains($innerHtml, 'PR-Ver') === false) {
                    continue;
                }

                $link = $node->querySelector('a[href*="/cnt-id-"]');
                if ($link !== null) {
                    $href = $link->getAttribute('href');
                    if (is_string($href) === true && $href !== '') {
                        $ads[$this->articleId(urljoin(self::URI, $href))] = true;
                    }
                }
            }
        }

        return $ads;
    }

    private function collectFullContent(array $item): string
    {
        $image = $item['enclosures'][0] ?? '';
        $cacheKey = 'article_' . md5($item['uri'] . '|' . ($item['timestamp'] ?? ''));

        $body = $this->loadCacheValue($cacheKey);
        if (is_string($body) === true && $body !== '') {
            return $this->buildContent((string) $image, $body);
        }

        if ($this->hasTimeLeft() === false) {
            return $this->buildNotice('Full text skipped, time budget exhausted.') . $item['content'];
        }

        try {
            $html = $this->fetch($item['uri']);
            $dom = \Dom\HTMLDocument::createFromString($html);
            $body = $this->extractBody($dom);
        } catch (\Exception $e) {
            $this->logger->info(sprintf('FLZ: article %s not available: %s', $item['uri'], $e->getMessage()));
            return $this->buildNotice('Full text could not be fetched (article page not accessible).') . $item['content'];
        }

        if ($body === '') {
            return $this->buildNotice('Full text not found on article page.') . $item['content'];
        }

        if ($this->isPaywalled($html) === false) {
            $this->saveCacheValue($cacheKey, $body, self::ARTICLE_CACHE_TTL);
            return $this->buildContent((string) $image, $body);
        }

        $reason = $this->loginProblem !== '' ? $this->loginProblem : 'Login is active, but flz.de still doesn\'t deliver the article freely (subscription expired or article not included in subscription).';

        return $this->buildNotice('FLZ+ article as teaser only: ' . $reason) . $this->buildContent((string) $image, $body);
    }

    private function extractBody(\Dom\HTMLDocument $dom): string
    {
        $body = '';
        $elements = $dom->querySelectorAll('p.article-text, h3.article-subtitle-h3');

        foreach ($elements as $element) {
            if ($element instanceof \Dom\Element === false) {
                continue;
            }

            $text = html_entity_decode((string) $element->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim((string) preg_replace('~\s+~u', ' ', $text));

            if ($text !== '') {
                $tagName = strtolower($element->tagName);
                $body .= sprintf('<%1$s>%2$s</%1$s>', $tagName, e($text));
            }
        }

        return $body;
    }

    private function isPaywalled(string $html): bool
    {
        return str_contains($html, 'payWallContentPart') === true
            || str_contains($html, 'pw2-login-hint') === true
            || str_contains($html, '"restricted":true') === true;
    }

    private function buildContent(string $image, string $body): string
    {
        return $image !== '' ? '<p><img src="' . e($image) . '" alt=""></p>' . $body : $body;
    }

    private function buildNotice(string $text): string
    {
        return '<p><strong>[FLZ-Bridge] ' . e($text) . '</strong></p>';
    }

    private function login(): void
    {
        $username = (string) $this->getOption('username');
        $password = (string) $this->getOption('password');

        if ($username === '' || $password === '') {
            $this->loginProblem = 'No FLZ+ credentials configured in bridge configuration.';
            return;
        }

        if ($this->loadCacheValue('login_failed') !== null) {
            $this->loginProblem = sprintf(
                'Last FLZ+ login failed, further attempts are paused for up to %d hours. Check credentials and subscription.',
                (int) (self::LOGIN_LOCK_TTL / 3600)
            );
            return;
        }

        try {
            $this->cookie = $this->postLogin($username, $password);
            $statusRaw = getContents(self::LOGON_STATUS_URI, [
                'Accept: application/json',
                'Cookie: ' . $this->cookie,
            ], $this->requestOptions());
            $status = Json::decode((string) $statusRaw);

            if (is_array($status) === false || isset($status['code']) === false) {
                throw new \Exception('Unexpected answer from the logon status');
            }
        } catch (\Exception $e) {
            $this->logger->warning(sprintf('FLZ: login service failed: %s', $e->getMessage()));
            $this->cookie = '';
            $this->loginProblem = 'FLZ login service (sso.flz.de) was not reachable or returned an error.';
            return;
        }

        if ((int) $status['code'] === 2000) {
            return;
        }

        $reason = isset($status['message']) === true && $status['message'] !== '' ? (string) $status['message'] : 'Session not logged in';
        $this->logger->warning(sprintf(
            'FLZ: login failed (%s), pausing for %d hours',
            $reason,
            (int) (self::LOGIN_LOCK_TTL / 3600)
        ));
        $this->saveCacheValue('login_failed', (string) time(), self::LOGIN_LOCK_TTL);
        $this->loginProblem = sprintf('FLZ+ login failed (%s). Check credentials and subscription.', $reason);
        $this->cookie = '';
    }

    private function postLogin(string $username, string $password): string
    {
        $response = getContents(self::SSO_URI, [], [CURLOPT_FOLLOWLOCATION => false] + $this->requestOptions(), true);
        if ($response instanceof Response === false) {
            throw new \Exception('Expected Response object');
        }
        $jar = $this->mergeCookies($response);

        $response = getContents(self::SSO_URI, [
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: https://sso.flz.de',
            'Referer: ' . self::SSO_URI,
            'Cookie: ' . $jar,
        ], [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'username' => $username,
                'password' => $password,
                'remember_me' => '1',
                'logon' => 'Anmeldung',
            ]),
            CURLOPT_FOLLOWLOCATION => false,
        ] + $this->requestOptions(), true);

        if ($response instanceof Response === false) {
            throw new \Exception('Expected Response object');
        }
        $jar = $this->mergeCookies($response, $jar);

        $url = self::SSO_URI;
        $location = $response->getHeader('location');

        for ($hops = 0; $hops < 5 && is_string($location) === true && $location !== ''; $hops++) {
            $url = urljoin($url, $location);
            if ($this->isFlzHost($url) === false) {
                break;
            }

            $response = getContents($url, ['Cookie: ' . $jar], [CURLOPT_FOLLOWLOCATION => false] + $this->requestOptions(), true);
            if ($response instanceof Response === false) {
                throw new \Exception('Expected Response object');
            }

            $jar = $this->mergeCookies($response, $jar);
            $location = $response->getHeader('location');
        }

        return $jar;
    }

    private function mergeCookies(Response $response, string $jar = ''): string
    {
        $cookies = [];

        if ($jar !== '') {
            foreach (explode('; ', $jar) as $pair) {
                if (str_contains($pair, '=') === true) {
                    [$name, $value] = explode('=', $pair, 2);
                    $cookies[$name] = $value;
                }
            }
        }

        $setCookieHeader = $response->getHeader('set-cookie', true);
        if (is_array($setCookieHeader) === false) {
            $setCookieHeader = [];
        }

        foreach ($setCookieHeader as $line) {
            $pair = explode(';', (string) $line)[0];
            if (str_contains($pair, '=') === false) {
                continue;
            }

            [$name, $value] = explode('=', $pair, 2);
            if ($value !== '' && $value !== 'deleted') {
                $cookies[trim($name)] = $value;
            }
        }

        $parts = [];
        foreach ($cookies as $name => $value) {
            $parts[] = $name . '=' . $value;
        }

        return implode('; ', $parts);
    }

    private function fetch(string $url): string
    {
        $wait = self::REQUEST_INTERVAL_S - (microtime(true) - $this->lastRequest);
        if ($wait > 0) {
            usleep((int) ($wait * 1000000));
        }
        $this->lastRequest = microtime(true);

        $headers = [
            'Accept-Language: de-DE,de;q=0.9',
            'Referer: ' . self::URI,
        ];

        if ($this->cookie !== '' && $this->isFlzHost($url) === true) {
            $headers[] = 'Cookie: ' . $this->cookie;
        }

        return (string) getContents($url, $headers, $this->requestOptions());
    }

    private function requestOptions(): array
    {
        $timeout = (int) Configuration::getConfig('http', 'timeout');
        return [CURLOPT_TIMEOUT => max(1, $timeout)];
    }

    private function isFlzHost(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) === true && ($host === 'flz.de' || str_ends_with($host, '.flz.de'));
    }

    private function secondsLeft(float $share = 1.0): float
    {
        return $this->started + self::DEADLINE_S * $share - microtime(true);
    }

    private function hasTimeLeft(float $share = 1.0): bool
    {
        return $this->secondsLeft($share) >= self::MIN_REQUEST_S;
    }
}
