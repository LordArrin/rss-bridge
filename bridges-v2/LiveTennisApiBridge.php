<?php

declare(strict_types=1);

namespace RSSBridge\Bridges;

use RSSBridge\BridgeAbstract;
use RSSBridge\Http\HttpException;
use Json;

final class LiveTennisApiBridge extends BridgeAbstract
{
    public const NAME = 'Live Tennis API';
    public const URI = 'https://livetennisapi.com/';
    public const DESCRIPTION = 'Tennis scores, schedule, results and fixtures for ATP, WTA, Challenger and ITF (needs a Live Tennis API key)';
    public const MAINTAINER = 'No maintainer';
    public const CACHE_TIMEOUT = 900;

    public const CONFIGURATION = [
        'api_key' => [
            'required' => false,
        ],
    ];

    public const PARAMETERS = [
        'global' => [
            'api_key' => [
                'name' => 'API Key',
                'type' => 'text',
                'required' => false,
                'title' => 'Your Live Tennis API key. Leave empty to use server default'
            ],
        ],
        '' => [
            'mode' => [
                'name' => 'Feed',
                'type' => 'list',
                'title' => 'Which slate of matches to publish',
                'defaultValue' => 'live',
                'values' => [
                    'Live matches' => 'live',
                    'Today, still to start' => 'today',
                    'Today, finished (needs a paid plan)' => 'results',
                    'Upcoming fixtures' => 'fixtures',
                ],
            ],
            'tour' => [
                'name' => 'Tour',
                'type' => 'list',
                'title' => 'Each tour covers its own singles and doubles draws',
                'defaultValue' => 'all',
                'values' => [
                    'All tours' => 'all',
                    'ATP' => 'atp',
                    'WTA' => 'wta',
                    'Challenger' => 'challenger',
                    'ITF' => 'itf',
                    'Juniors' => 'juniors',
                ],
            ],
            'draw' => [
                'name' => 'Draw',
                'type' => 'list',
                'title' => 'Team ties, where one event type covers both, match neither singles nor doubles',
                'defaultValue' => 'all',
                'values' => [
                    'Singles and doubles' => 'all',
                    'Singles' => 'singles',
                    'Doubles' => 'doubles',
                ],
            ],
            'player' => [
                'name' => 'Player name contains',
                'type' => 'text',
                'required' => false,
                'title' => 'Optional. Matched against both player names in the page that was fetched, case-insensitively',
                'exampleValue' => 'Sinner',
            ],
            'limit' => [
                'name' => 'Limit',
                'type' => 'number',
                'required' => false,
                'title' => 'How many matches to ask the api for, 1 to 200',
                'defaultValue' => 10,
                'exampleValue' => 10,
            ],
        ],
    ];

    private const API_BASE = 'https://api.livetennisapi.com/api/public/v1';

    public function collectData(): void
    {
        $apiKey = $this->resolveCredential('api_key');
        if ($apiKey === '') {
            throwClientException(sprintf(
                'This bridge needs a Live Tennis API key. Provide it in the bridge parameters or in config.ini.php as %s. Keys are issued at %s',
                '[LiveTennisApiBridge] api_key = "yourkey"',
                'https://livetennisapi.com/'
            ));
        }

        $mode = (string)$this->getInput('mode');
        if ($mode === '') {
            $mode = 'live';
        }

        $query = [];
        if ($mode === 'fixtures') {
            $path = '/fixtures';
        } else {
            $path = '/matches';
            $today = gmdate('Y-m-d');
            if ($mode === 'today') {
                $query['status'] = 'upcoming';
                $query['from'] = $today;
                $query['to'] = $today;
            } elseif ($mode === 'results') {
                $query['status'] = 'completed';
                $query['from'] = $today;
                $query['to'] = $today;
            } else {
                $query['status'] = 'live';
            }
        }

        $tour = (string)$this->getInput('tour');
        if ($tour === '') {
            $tour = 'all';
        }
        if ($tour !== 'all') {
            $query['tour'] = $tour;
        }

        $draw = (string)$this->getInput('draw');
        if ($draw === '') {
            $draw = 'all';
        }
        if ($draw !== 'all') {
            $query['draw'] = $draw;
        }

        $query['limit'] = $this->getLimit();

        $rows = $this->fetchList($path, $query, $apiKey);
        $nameFilter = trim((string)$this->getInput('player'));

        foreach ($rows as $row) {
            if (is_array($row) === false) {
                continue;
            }
            $isFixture = ($mode === 'fixtures');
            $containsName = self::namesContain($row, $isFixture, $nameFilter);
            if ($nameFilter !== '' && $containsName === false) {
                continue;
            }
            $item = $isFixture === true ? $this->fixtureToItem($row) : $this->matchToItem($row);
            if ($item === null) {
                continue;
            }
            $this->items[] = $item;
        }
    }

    private function resolveCredential(string $name): string
    {
        $input = $this->getInput($name);
        if ($input !== null && $input !== '') {
            return (string)$input;
        }

        $option = $this->getOption($name);
        if ($option !== null && $option !== '') {
            return (string)$option;
        }

        return '';
    }

    private static function namesContain(array $row, bool $isFixture, string $needle): bool
    {
        if ($isFixture === true) {
            $names = [self::text($row['player1_name'] ?? null), self::text($row['player2_name'] ?? null)];
        } else {
            $players = isset($row['players']) === true && is_array($row['players']) === true ? $row['players'] : [];
            $names = [self::playerName($players['p1'] ?? null), self::playerName($players['p2'] ?? null)];
        }

        $found = array_find($names, fn(string $name): bool => $name !== '' && stripos($name, $needle) !== false);
        return $found !== null;
    }

    private function getLimit(): int
    {
        $limit = $this->getInput('limit');
        if (is_numeric($limit) === false) {
            return 50;
        }
        return max(1, min(200, (int)$limit));
    }

    private function fetchList(string $path, array $query, string $apiKey): array
    {
        $url = self::API_BASE . $path . '?' . http_build_query($query);
        $headers = [
            'Accept: application/json',
            'X-API-Key: ' . $apiKey,
        ];

        try {
            $body = getContents($url, $headers);
        } catch (HttpException $e) {
            throw self::apiException($e);
        }

        try {
            $json = Json::decode($body);
        } catch (\JsonException $e) {
            throwServerException('The Live Tennis API returned a body that is not valid JSON');
        }

        if (is_array($json) === false || isset($json['data']) === false || is_array($json['data']) === false) {
            throwServerException('The Live Tennis API response carried no data array');
        }
        return array_values($json['data']);
    }

    private static function apiException(HttpException $e): \Exception
    {
        $code = $e->getCode();
        $error = '';
        $detail = '';
        if ($e->response !== null) {
            try {
                $decoded = Json::decode($e->response->getBody());
                if (is_array($decoded) === true) {
                    $error = isset($decoded['error']) === true && is_string($decoded['error']) === true ? $decoded['error'] : '';
                    $detail = isset($decoded['detail']) === true && is_string($decoded['detail']) === true ? $decoded['detail'] : '';
                }
            } catch (\JsonException $jsonException) {
            }
        }
        $suffix = '';
        if ($detail !== '') {
            $suffix = ' The api said: ' . $detail;
        } elseif ($error !== '') {
            $suffix = ' The api said: ' . $error;
        }

        return match ($code) {
            401 => new \Exception('The Live Tennis API rejected the configured api_key (401). Check your API key.' . $suffix),
            403 => new \Exception('This feed is not included in the plan the configured api_key holds (403).' . $suffix),
            429 => self::rateLimitException($e->response, $suffix),
            400 => new \Exception('The Live Tennis API rejected the query (400).' . $suffix),
            default => new \Exception(sprintf('The Live Tennis API request failed with status %s.%s', $code, $suffix)),
        };
    }

    private static function rateLimitException(mixed $response, string $suffix): \Exception
    {
        $retryAfter = null;
        if ($response !== null && method_exists($response, 'getHeader') === true) {
            $retryAfter = $response->getHeader('retry-after');
        }
        $message = 'Rate limited by the Live Tennis API (429).';
        if (is_string($retryAfter) === true && $retryAfter !== '') {
            $message .= ' Retry after ' . $retryAfter . ' seconds.';
        }
        throwRateLimitException($message . $suffix);
    }

    private function matchToItem(array $match): ?array
    {
        $id = $match['id'] ?? null;
        if (is_numeric($id) === false) {
            return null;
        }
        $id = (int)$id;

        $players = isset($match['players']) === true && is_array($match['players']) === true ? $match['players'] : [];
        $p1 = self::playerName($players['p1'] ?? null, 'Player 1');
        $p2 = self::playerName($players['p2'] ?? null, 'Player 2');

        $score = isset($match['score']) === true && is_array($match['score']) === true ? $match['score'] : null;
        $gamesText = self::formatGamesBySet($score === null ? null : ($score['games'] ?? null));
        $status = self::statusLabel($match, $p1, $p2);
        $tournament = self::text($match['tournament'] ?? null);

        $title = $p1 . ' v ' . $p2;
        if ($gamesText !== '') {
            $title .= ' ' . $gamesText;
        }
        $title .= ' (' . $status . ')';
        if ($tournament !== '') {
            $title .= ' — ' . $tournament;
        }

        return [
            'title' => $title,
            'uri' => self::API_BASE . '/matches/' . $id,
            'uid' => 'livetennisapi:match:' . $id,
            'timestamp' => self::matchTimestamp($match, $score),
            'author' => $tournament !== '' ? $tournament : 'Live Tennis API',
            'categories' => self::categories($match),
            'content' => self::matchContent($match, $score, $p1, $p2, $status, $gamesText),
        ];
    }

    private function fixtureToItem(array $fixture): ?array
    {
        $id = $fixture['id'] ?? null;
        if (is_numeric($id) === false) {
            return null;
        }
        $id = (int)$id;

        $p1 = self::text($fixture['player1_name'] ?? null, 'Player 1');
        $p2 = self::text($fixture['player2_name'] ?? null, 'Player 2');
        $tournament = self::text($fixture['tournament'] ?? null);
        $startTime = self::text($fixture['start_time'] ?? null);
        $eventDate = self::text($fixture['event_date'] ?? null);

        if ($startTime !== '') {
            $when = 'starts ' . self::formatInstant($startTime);
        } elseif ($eventDate !== '') {
            $when = 'scheduled for ' . $eventDate . ', no time yet';
        } else {
            $when = 'no start time published';
        }

        $title = $p1 . ' v ' . $p2 . ' (' . $when . ')';
        if ($tournament !== '') {
            $title .= ' — ' . $tournament;
        }

        $rows = [];
        if ($tournament !== '') {
            $rows['Tournament'] = $tournament . self::suffix(self::text($fixture['round'] ?? null), ' — ');
        }
        $rows['When'] = ucfirst($when);
        if (self::text($fixture['surface'] ?? null) !== '') {
            $rows['Surface'] = self::text($fixture['surface'] ?? null);
        }
        if (self::text($fixture['tour'] ?? null) !== '') {
            $rows['Tour'] = self::text($fixture['tour'] ?? null);
        }
        if (self::text($fixture['status'] ?? null) !== '') {
            $rows['Status'] = self::text($fixture['status'] ?? null);
        }
        $rows['Players'] = $p1 . ' v ' . $p2;

        return [
            'title' => $title,
            'uri' => self::API_BASE . '/matches/' . $id,
            'uid' => 'livetennisapi:fixture:' . $id,
            'timestamp' => $startTime !== '' ? $startTime : $eventDate,
            'author' => $tournament !== '' ? $tournament : 'Live Tennis API',
            'content' => self::renderRows($rows),
        ];
    }

    private static function matchContent(array $match, ?array $score, string $p1, string $p2, string $status, string $gamesText): string
    {
        $rows = [];

        $tournament = self::text($match['tournament'] ?? null);
        if ($tournament !== '') {
            $rows['Tournament'] = $tournament . self::suffix(self::text($match['round'] ?? null), ' — ');
        }
        $surface = self::text($match['surface'] ?? null);
        if ($surface !== '') {
            $indoor = isset($match['indoor']) === true ? (bool)$match['indoor'] : false;
            $rows['Surface'] = $surface . ($indoor === true ? ' (indoor)' : '');
        }
        $draw = self::text($match['draw'] ?? null);
        if ($draw !== '') {
            $rows['Draw'] = $draw;
        }
        $format = self::text($match['format'] ?? null);
        if ($format === 'BO5') {
            $rows['Format'] = 'best of five';
        } elseif ($format === 'BO3') {
            $rows['Format'] = 'best of three';
        }

        $rows['Status'] = $status;

        if ($score !== null) {
            $sets = $score['sets'] ?? null;
            if (is_array($sets) === true && isset($sets[0]) === true && isset($sets[1]) === true && is_numeric($sets[0]) === true && is_numeric($sets[1]) === true) {
                $rows['Sets'] = (int)$sets[0] . '-' . (int)$sets[1];
            }
            if ($gamesText !== '') {
                $rows['Games by set'] = $gamesText;
            }

            $points = self::formatPoints($score);
            if ($points !== '') {
                $isTiebreak = isset($score['is_tiebreak']) === true ? (bool)$score['is_tiebreak'] : false;
                $rows[$isTiebreak === true ? 'Tiebreak' : 'Current game'] = $points;
            }

            $server = self::serverNumber($score);
            if ($server !== null) {
                $rows['Serving'] = $server === 1 ? $p1 : $p2;
            }
            $isBreak = self::isBreakPoint($score);
            if ($isBreak === true) {
                $rows['Break point'] = ($server === 1 ? $p2 : $p1) . ' is a point from the break';
            }

            $stamp = self::text($score['timestamp'] ?? null);
            if ($stamp !== '') {
                $rows['Score read at'] = self::formatInstant($stamp);
            }
        }

        $scheduled = self::text($match['scheduled_time'] ?? null);
        if ($scheduled !== '') {
            $rows['Scheduled'] = self::formatInstant($scheduled);
        }
        $liveAt = self::text($match['live_at'] ?? null);
        if ($liveAt !== '') {
            $rows['Last seen in play'] = self::formatInstant($liveAt);
        }

        $players = isset($match['players']) === true && is_array($match['players']) === true ? $match['players'] : [];
        $rows['Players'] = self::playerLine($players['p1'] ?? null, $p1) . ' v ' . self::playerLine($players['p2'] ?? null, $p2);

        return self::renderRows($rows);
    }

    private static function isBreakPoint(?array $score): bool
    {
        if ($score === null) {
            return false;
        }
        $isTiebreak = isset($score['is_tiebreak']) === true ? (bool)$score['is_tiebreak'] : false;
        if ($isTiebreak === true) {
            return false;
        }
        $server = self::serverNumber($score);
        if ($server === null) {
            return false;
        }
        $points = $score['points'] ?? null;
        if (is_array($points) === false) {
            return false;
        }
        $serverPoints = $points[$server - 1] ?? null;
        $receiverPoints = $points[$server === 1 ? 1 : 0] ?? null;
        if (is_string($serverPoints) === false || is_string($receiverPoints) === false) {
            return false;
        }
        if ($receiverPoints === 'AD') {
            return true;
        }
        if ($receiverPoints !== '40') {
            return false;
        }
        $validServerPoints = ['0', '15', '30'];
        return in_array($serverPoints, $validServerPoints, true) === true;
    }

    private static function serverNumber(?array $score): ?int
    {
        if ($score === null) {
            return null;
        }
        $server = $score['server'] ?? null;
        if (is_numeric($server) === false) {
            return null;
        }
        $server = (int)$server;
        return ($server === 1 || $server === 2) ? $server : null;
    }

    private static function formatGamesBySet(mixed $games): string
    {
        if (is_array($games) === false || is_array($games[0] ?? null) === false || is_array($games[1] ?? null) === false) {
            return '';
        }
        $first = array_values($games[0]);
        $second = array_values($games[1]);
        $sets = [];
        $setCount = max(count($first), count($second));
        for ($i = 0; $i < $setCount; $i++) {
            $a = $first[$i] ?? null;
            $b = $second[$i] ?? null;
            if (is_numeric($a) === false || is_numeric($b) === false) {
                continue;
            }
            $sets[] = (int)$a . '-' . (int)$b;
        }
        return implode(' ', $sets);
    }

    private static function formatPoints(array $score): string
    {
        $points = $score['points'] ?? null;
        if (is_array($points) === false) {
            return '';
        }
        $a = $points[0] ?? null;
        $b = $points[1] ?? null;
        if (is_string($a) === false || is_string($b) === false || $a === '' || $b === '') {
            return '';
        }
        return $a . '-' . $b;
    }

    private static function statusLabel(array $match, string $p1, string $p2): string
    {
        $status = self::text($match['status'] ?? null);
        if ($status !== 'completed') {
            $eventStatus = self::text($match['event_status'] ?? null);
            if ($status === 'live' && $eventStatus === 'Interrupted') {
                return 'live, interrupted';
            }
            if ($status === 'cancelled') {
                return $eventStatus !== '' ? 'cancelled, ' . strtolower($eventStatus) : 'cancelled';
            }
            return $status !== '' ? $status : 'unknown';
        }

        $winner = $match['winner'] ?? null;
        $winnerName = '';
        if (is_numeric($winner) === true) {
            $winnerName = ((int)$winner) === 1 ? $p1 : $p2;
        }
        $withdrew = $match['withdrew'] ?? null;
        $withdrewName = '';
        if (is_numeric($withdrew) === true) {
            $withdrewName = ((int)$withdrew) === 1 ? $p1 : $p2;
        }

        $outcome = self::text($match['outcome'] ?? null);
        return match ($outcome) {
            'retired' => $withdrewName !== '' ? $withdrewName . ' retired' : 'retired',
            'walkover' => $winnerName !== '' ? 'walkover to ' . $winnerName : 'walkover',
            'default' => $winnerName !== '' ? 'default, ' . $winnerName . ' through' : 'default',
            'abandoned' => 'abandoned unfinished',
            'unresolved' => 'closed unfinished, result unresolved',
            default => $winnerName !== '' ? $winnerName . ' won' : 'completed',
        };
    }

    private static function matchTimestamp(array $match, ?array $score): string
    {
        if ($score !== null) {
            $stamp = self::text($score['timestamp'] ?? null);
            if ($stamp !== '') {
                return $stamp;
            }
        }
        $liveAt = self::text($match['live_at'] ?? null);
        if ($liveAt !== '') {
            return $liveAt;
        }
        return self::text($match['scheduled_time'] ?? null);
    }

    private static function categories(array $match): array
    {
        $categories = [];
        foreach (['tour', 'draw', 'round_code'] as $field) {
            $value = self::text($match[$field] ?? null);
            if ($value !== '') {
                $categories[] = $value;
            }
        }
        return $categories;
    }

    private static function playerName(mixed $player, string $fallback = ''): string
    {
        if (is_array($player) === false) {
            return $fallback;
        }
        return self::text($player['name'] ?? null, $fallback);
    }

    private static function playerLine(mixed $player, string $name): string
    {
        if (is_array($player) === false) {
            return $name;
        }
        $notes = [];
        $country = self::text($player['country'] ?? null);
        if ($country !== '') {
            $notes[] = strtoupper($country);
        }
        $ranking = $player['ranking'] ?? null;
        if (is_numeric($ranking) === true) {
            $notes[] = 'no. ' . (int)$ranking;
        }
        return $notes === [] ? $name : $name . ' (' . implode(', ', $notes) . ')';
    }

    private static function formatInstant(string $instant): string
    {
        $timestamp = strtotime($instant);
        if ($timestamp === false) {
            return $instant;
        }
        return gmdate('Y-m-d H:i', $timestamp) . ' UTC';
    }

    private static function renderRows(array $rows): string
    {
        $html = '';
        foreach ($rows as $label => $value) {
            if ($value === '') {
                continue;
            }
            $html .= sprintf('<li><strong>%s:</strong> %s</li>', e((string)$label), e($value));
        }
        return '<ul>' . $html . '</ul>';
    }

    private static function suffix(string $value, string $separator): string
    {
        return $value === '' ? '' : $separator . $value;
    }

    private static function text(mixed $value, string $fallback = ''): string
    {
        if (is_string($value) === true && trim($value) !== '') {
            return trim($value);
        }
        if (is_int($value) === true || is_float($value) === true) {
            return (string)$value;
        }
        return $fallback;
    }
}
