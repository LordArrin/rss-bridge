<?php

/**
 * HTTP client module containing request/response handling.
 *
 * @phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses
 * @phpcs:disable Generic.Files.OneClassPerFile.MultipleFound
 * @phpcs:disable Generic.Files.OneInterfacePerFile.MultipleFound
 * @phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols
 */

declare(strict_types=1);

namespace RSSBridge\Http;

use HttpException;
use CloudFlareException;

interface HttpClient
{
    /**
     * Performs an HTTP request and returns a Response.
     *
     * @param string $url The URL to request
     * @param array<string, mixed> $config Optional configuration
     * @return Response
     * @throws \HttpException
     */
    public function request(string $url, array $config = []): Response;
}

final class IpResolver
{
    public const DEFAULT_BLOCKED_CIDRS = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '::/128',
        '::1/128',
        '::ffff:0:0/104',
        'fc00::/7',
        'fe80::/10',
        'ff00::/8',
    ];

    /** @var list<string> */
    private array $blockedCidrs;

    /** @var list<string> */
    private array $allowedCidrs;

    /** @var array<string, array{time: int, ips: list<string>}> */
    private static array $cache = [];

    private const CACHE_TTL = 60;
    private const NEGATIVE_CACHE_TTL = 30;
    private const MAX_CACHE_ENTRIES = 512;

    /**
     * @param list<string>|string|null $blockedCidrs
     * @param list<string>|string|null $allowedCidrs
     */
    public function __construct(array|string|null $blockedCidrs = null, array|string|null $allowedCidrs = null)
    {
        $mergedBlocked = self::DEFAULT_BLOCKED_CIDRS;

        if ($blockedCidrs !== null) {
            if (is_string($blockedCidrs) === true) {
                $blockedCidrs = explode(',', $blockedCidrs);
            }
            foreach ($blockedCidrs as $cidr) {
                $cidr = trim($cidr);
                $normalizedCidr = self::normalizeToCidr($cidr);
                if ($normalizedCidr !== '' && self::isValidCidr($normalizedCidr) === true) {
                    $mergedBlocked[] = $normalizedCidr;
                }
            }
        }

        $this->blockedCidrs = array_values(array_unique($mergedBlocked));

        $mergedAllowed = [];
        if ($allowedCidrs !== null) {
            if (is_string($allowedCidrs) === true) {
                $allowedCidrs = explode(',', $allowedCidrs);
            }
            foreach ($allowedCidrs as $cidr) {
                $cidr = trim($cidr);
                $normalizedCidr = self::normalizeToCidr($cidr);
                if ($normalizedCidr !== '' && self::isValidCidr($normalizedCidr) === true) {
                    $mergedAllowed[] = $normalizedCidr;
                }
            }
        }

        $this->allowedCidrs = array_values(array_unique($mergedAllowed));
    }

    private static function normalizeToCidr(string $input): string
    {
        if ($input === '') {
            return '';
        }
        if (str_contains($input, '/') === true) {
            return $input;
        }
        if (filter_var($input, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return $input . '/128';
        }
        if (filter_var($input, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $input . '/32';
        }
        return '';
    }

    /**
     * Resolves a hostname to its IP addresses through the process-wide DNS cache.
     *
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        $host = strtolower(rtrim($host, '.'));
        if ($host === '') {
            return [];
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $cached = self::$cache[$host] ?? null;
        if ($cached !== null) {
            $ttl = $cached['ips'] === [] ? self::NEGATIVE_CACHE_TTL : self::CACHE_TTL;
            if ((time() - $cached['time']) < $ttl) {
                return $cached['ips'];
            }
        }

        $ips = gethostbynamel($host);
        $ips = is_array($ips) === true ? $ips : [];

        $aaaaRecords = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaaRecords) === false) {
            $aaaaRecords = [];
        }
        foreach ($aaaaRecords as $record) {
            if (isset($record['ipv6']) === true && filter_var($record['ipv6'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                $ips[] = $record['ipv6'];
            }
        }

        $ips = array_values(array_unique($ips));
        self::$cache[$host] = ['time' => time(), 'ips' => $ips];

        if (count(self::$cache) > self::MAX_CACHE_ENTRIES) {
            $now = time();
            foreach (self::$cache as $name => $entry) {
                $entryTtl = $entry['ips'] === [] ? self::NEGATIVE_CACHE_TTL : self::CACHE_TTL;
                if (($now - $entry['time']) >= $entryTtl) {
                    unset(self::$cache[$name]);
                }
            }
            if (count(self::$cache) > self::MAX_CACHE_ENTRIES) {
                uasort(self::$cache, static fn (array $a, array $b): int => $a['time'] <=> $b['time']);
                self::$cache = array_slice(self::$cache, -self::MAX_CACHE_ENTRIES, null, true);
            }
        }

        return $ips;
    }

    /**
     * Checks if an IP is allowed (not blocked).
     */
    public function isAllowed(string $ip): bool
    {
        return $this->isBlocked($ip) === false;
    }

    /**
     * Checks if an IP is blocked.
     */
    public function isBlocked(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true;
        }

        if ($this->isWhitelisted($ip) === true) {
            return false;
        }

        foreach ($this->blockedCidrs as $cidr) {
            if (self::matches($ip, $cidr) === true) {
                return true;
            }
        }
        return false;
    }

    /**
     * Checks if an IP is whitelisted.
     */
    public function isWhitelisted(string $ip): bool
    {
        foreach ($this->allowedCidrs as $cidr) {
            if (self::matches($ip, $cidr) === true) {
                return true;
            }
        }
        return false;
    }

    /**
     * Checks if an IP matches a CIDR range.
     */
    public static function matches(string $ip, string $cidr): bool
    {
        $parts = explode('/', $cidr, 2);
        $block = $parts[0];
        $bitsStr = $parts[1] ?? null;

        $ipBin = inet_pton($ip);
        $blockBin = inet_pton($block);
        if ($ipBin === false || $blockBin === false) {
            return false;
        }
        $bits = $bitsStr === null ? strlen($blockBin) * 8 : (int)$bitsStr;

        $ipBin = self::unwrapV4Mapped($ipBin);
        $blockBin = self::unwrapV4Mapped($blockBin);

        if (strlen($ipBin) !== strlen($blockBin)) {
            return false;
        }
        $maxBits = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }
        $fullBytes = intdiv($bits, 8);
        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($blockBin, 0, $fullBytes)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xff << (8 - $rem)) & 0xff;
        return (ord($ipBin[$fullBytes]) & $mask) === (ord($blockBin[$fullBytes]) & $mask);
    }

    /**
     * Unwraps IPv4-mapped IPv6 addresses to their IPv4 representation.
     */
    private static function unwrapV4Mapped(string $bin): string
    {
        if (strlen($bin) === 16 && substr($bin, 0, 10) === str_repeat("\x00", 10) && substr($bin, 10, 2) === "\xff\xff") {
            return substr($bin, 12);
        }
        return $bin;
    }

    /**
     * Validates a CIDR notation string.
     */
    public static function isValidCidr(string $cidr): bool
    {
        [$ip, $bits] = str_contains($cidr, '/') === true ? explode('/', $cidr, 2) : [$cidr, null];
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if ($bits === null) {
            return true;
        }
        if (ctype_digit($bits) === false) {
            return false;
        }
        $max = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 128 : 32;
        return (int)$bits >= 0 && (int)$bits <= $max;
    }
}

final class CurlHttpClient implements HttpClient
{
    private const RESOLVE_OPT = 10203;
    private const IMPERSONATE_OPT = 10240;
    private const TIMEVALUE_CRITICAL_OPT = 307;

    private static function impersonateOption(): int
    {
        return defined('CURLOPT_IMPERSONATE') === true ? (int)constant('CURLOPT_IMPERSONATE') : self::IMPERSONATE_OPT;
    }

    private static function resolveOption(): int
    {
        return defined('CURLOPT_RESOLVE') === true ? constant('CURLOPT_RESOLVE') : self::RESOLVE_OPT;
    }

    private static function timevalueCriticalOption(): int
    {
        return defined('CURLOPT_TIMEVALUE_CRITICAL') === true ? (int)constant('CURLOPT_TIMEVALUE_CRITICAL') : self::TIMEVALUE_CRITICAL_OPT;
    }

    /**
     * Gets the CURL_IMPERSONATE environment variable target.
     */
    public static function getImpersonateTarget(): ?string
    {
        $target = getenv('CURL_IMPERSONATE');
        if (is_string($target) === true && $target !== '') {
            return $target;
        }
        return null;
    }

    /**
     * Checks if the impersonate option is set to opt-out (empty or 'none').
     *
     * @param array<int, mixed> $curlOptions
     */
    private static function isOptOut(array $curlOptions): bool
    {
        $opt = self::impersonateOption();
        if (array_key_exists($opt, $curlOptions) === false) {
            return false;
        }
        $explicit = strtolower(trim((string)$curlOptions[$opt]));
        return $explicit === '' || $explicit === 'none';
    }

    private static ?IpResolver $ipResolver = null;

    /**
     * Gets or creates the singleton IpResolver instance.
     */
    private static function ipResolver(): IpResolver
    {
        if (self::$ipResolver instanceof IpResolver === false) {
            $blocked = getenv('HTTP_BLOCKED_IP_RANGES');
            $blocked = $blocked !== false && $blocked !== '' ? $blocked : null;
            $allowed = getenv('HTTP_ALLOWED_IP_RANGES');
            $allowed = $allowed !== false && $allowed !== '' ? $allowed : null;
            self::$ipResolver = new IpResolver($blocked, $allowed);
        }
        return self::$ipResolver;
    }

    /**
     * Checks if SSRF protection is enabled.
     */
    private static function isSsrfCheckEnabled(): bool
    {
        $value = getenv('HTTP_SSRF_CHECK');
        return $value === false || strtolower($value) !== 'false';
    }

    /**
     * Parses an absolute URL into its security-relevant components.
     *
     * @return array{host: string, port: int, scheme: string, path: string}|null
     */
    private static function parseHttpUrl(string $url): ?array
    {
        $parts = parse_url(trim($url));
        if (is_array($parts) === false || isset($parts['scheme'], $parts['host']) === false) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $host = strtolower($parts['host']);
        if (str_starts_with($host, '[') === true && str_ends_with($host, ']') === true) {
            $host = substr($host, 1, -1);
        }
        if ($host !== '' && str_starts_with($host, '.') === false) {
            $host = rtrim($host, '.');
        }
        if ($host === '') {
            return null;
        }

        return [
            'host' => $host,
            'port' => $parts['port'] ?? ($scheme === 'https' ? 443 : 80),
            'scheme' => $scheme,
            'path' => $parts['path'] ?? '/',
        ];
    }

    /**
     * Builds the CURLOPT_RESOLVE entries that pin a hostname to its DNS-cached, SSRF-vetted IP addresses.
     *
     * @return list<string> Zero or more CURLOPT_RESOLVE entry strings.
     */
    private function buildResolveEntries(string $url, bool $ssrfCheck): array
    {
        if ($ssrfCheck === false) {
            return [];
        }

        $parsed = self::parseHttpUrl($url);
        if ($parsed === null || $parsed['host'] === '') {
            return [];
        }

        $host = $parsed['host'];
        $port = $parsed['port'];

        if (str_starts_with($host, '.') === true) {
            return [];
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (self::ipResolver()->isBlocked($host) === true) {
                throw new \HttpException(sprintf(
                    'Access to %s is blocked: the IP falls into a private or reserved range (SSRF protection)',
                    $host
                ));
            }
            return [];
        }

        $ips = self::ipResolver()->resolve($host);
        if ($ips === []) {
            // Fail closed: an unresolvable host cannot be vetted, so refuse rather
            // than letting cURL resolve it unchecked. Escape hatch: HTTP_SSRF_CHECK=false.
            throw new \HttpException(sprintf(
                'Access to %s is blocked: the host could not be resolved for SSRF vetting (SSRF protection)',
                $host
            ));
        }

        $allowedIps = [];
        foreach ($ips as $ip) {
            if (self::ipResolver()->isAllowed($ip) === true) {
                $allowedIps[] = $ip;
            }
        }

        if ($allowedIps === []) {
            throw new \HttpException(sprintf(
                'Access to %s is blocked: the host resolves only to private or reserved IP ranges (SSRF protection)',
                $host
            ));
        }

        return [sprintf('+%s:%d:%s', $host, $port, implode(',', $allowedIps))];
    }

    /**
     * Returns the registrable domain (eTLD+1) of a host.
     *
     * Heuristic, NOT a full Public Suffix List: the last two labels are treated
     * as a public suffix when the TLD is a two-letter ccTLD and the second level
     * is a registry-like label (co/com/net/org/...). This is conservative — it
     * only ever requires MORE matching labels than the flat-TLD default, so it
     * cannot merge unrelated sites that the default would keep apart.
     *
     * @param string $host Lowercased hostname.
     * @return string|null The registrable domain, or null for IPs/edge cases.
     */
    private static function registrableDomain(string $host): ?string
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        $labels = explode('.', $host);
        $count = count($labels);
        if ($count < 2) {
            return null;
        }

        $suffixLength = 1;
        if ($count >= 3) {
            $tld = $labels[$count - 1];
            $secondLevel = $labels[$count - 2];
            $registryLabels = [
                'co', 'com', 'net', 'org', 'gov', 'gob', 'gouv', 'edu', 'ac',
                'go', 'or', 'ne', 'ad', 'nom', 'gen', 'firm', 'ind', 'web',
                'sch', 'asn', 'id', 'asso', 'ltd', 'plc', 'me', 'govt', 'muni',
                'idf', 're', 'pe', 'geek', 'maori', 'school', 'arts', 'rec',
                'info', 'biz', 'name', 'pro', 'mil', 'gv', 'coop', 'aero',
                'museum', 'travel', 'jobs', 'mobi', 'tel', 'asia', 'cat', 'post',
            ];
            if (strlen($tld) === 2 && in_array($secondLevel, $registryLabels, true) === true) {
                $suffixLength = 2;
            }
        }

        if ($count <= $suffixLength) {
            return $host;
        }

        return implode('.', array_slice($labels, -$suffixLength - 1));
    }

    /**
     * Resolves a relative or protocol-relative Location reference against the
     * original request URL per RFC 3986 section 5. Query/fragment are preserved.
     *
     * @param array{host: string, port: int, scheme: string, path: string} $base
     */
    private static function resolveReference(array $base, string $reference): string
    {
        $scheme = $base['scheme'];
        $host = $base['host'];
        $port = $base['port'];

        // Protocol-relative: //host[:port]/path
        if (str_starts_with($reference, '//') === true) {
            $authorityAndRest = substr($reference, 2);
            $slashPos = strpos($authorityAndRest, '/');
            $authority = $slashPos === false ? $authorityAndRest : substr($authorityAndRest, 0, $slashPos);
            $rest = $slashPos === false ? '/' : substr($authorityAndRest, $slashPos);

            if (str_starts_with($authority, '[') === true) {
                $close = strpos($authority, ']');
                if ($close !== false) {
                    $host = substr($authority, 0, $close + 1);
                    $tail = substr($authority, $close + 1);
                    if (str_starts_with($tail, ':') === true && ctype_digit(substr($tail, 1)) === true) {
                        $port = (int) substr($tail, 1);
                    }
                } else {
                    $host = $authority;
                }
            } else {
                $colon = strrpos($authority, ':');
                if ($colon !== false && ctype_digit(substr($authority, $colon + 1)) === true) {
                    $host = substr($authority, 0, $colon);
                    $port = (int) substr($authority, $colon + 1);
                } else {
                    $host = $authority;
                }
            }

            return self::buildUrl($scheme, $host, $port, self::removeDotSegments($rest));
        }

        // Absolute-path reference: /path
        if (str_starts_with($reference, '/') === true) {
            return self::buildUrl($scheme, $host, $port, self::removeDotSegments($reference));
        }

        // Relative path: merge with the base directory (RFC 3986 5.3). A base
        // path ending in "/" is itself the directory; otherwise strip the last
        // segment. This fixes the old dirname() bug for directory-like bases.
        $basePath = $base['path'];
        if ($basePath === '' || str_ends_with($basePath, '/') === true) {
            $merged = $basePath . $reference;
        } else {
            $lastSlash = strrpos($basePath, '/');
            $merged = ($lastSlash === false ? '' : substr($basePath, 0, $lastSlash + 1)) . $reference;
        }
        if (str_starts_with($merged, '/') === false) {
            $merged = '/' . $merged;
        }

        return self::buildUrl($scheme, $host, $port, self::removeDotSegments($merged));
    }

    /**
     * RFC 3986 section 5.2.4: remove "." and ".." segments, preserving a trailing
     * slash and any query/fragment suffix.
     */
    private static function removeDotSegments(string $path): string
    {
        $suffix = '';
        $cut = strcspn($path, '?#');
        if ($cut < strlen($path)) {
            $suffix = substr($path, $cut);
            $path = substr($path, 0, $cut);
        }

        $input = $path;
        $output = '';
        while ($input !== '') {
            if (str_starts_with($input, '../') === true) {
                $input = substr($input, 3);
                continue;
            }
            if (str_starts_with($input, './') === true) {
                $input = substr($input, 2);
                continue;
            }
            if (str_starts_with($input, '/./') === true) {
                $input = '/' . substr($input, 3);
                continue;
            }
            if ($input === '/.') {
                $input = '/';
                continue;
            }
            if (str_starts_with($input, '/../') === true) {
                $input = '/' . substr($input, 4);
                $lastSlash = strrpos($output, '/');
                $output = $lastSlash === false ? '' : substr($output, 0, $lastSlash);
                continue;
            }
            if ($input === '/..') {
                $input = '/';
                $lastSlash = strrpos($output, '/');
                $output = $lastSlash === false ? '' : substr($output, 0, $lastSlash);
                continue;
            }
            if ($input === '.' || $input === '..') {
                $input = '';
                continue;
            }
            $nextSlash = str_starts_with($input, '/') === true ? strpos($input, '/', 1) : strpos($input, '/');
            $segEnd = $nextSlash === false ? strlen($input) : $nextSlash;
            $output .= substr($input, 0, $segEnd);
            $input = substr($input, $segEnd);
        }

        return $output . $suffix;
    }

    /**
     * Builds scheme://host[:port]/path, omitting the port ONLY when it is the
     * default for the scheme (http:80, https:443). IPv6 literals are bracketed.
     */
    private static function buildUrl(string $scheme, string $host, int $port, string $path): string
    {
        if (str_contains($host, ':') === true && str_starts_with($host, '[') === false) {
            $host = '[' . $host . ']';
        }
        $isDefaultPort = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
        if ($isDefaultPort === true) {
            return sprintf('%s://%s%s', $scheme, $host, $path);
        }
        return sprintf('%s://%s:%d%s', $scheme, $host, $port, $path);
    }

    /**
     * Checks if two hosts belong to the same effective domain.
     *
     */
    private static function isSameEffectiveDomain(string $host1, string $host2): bool
    {
        if ($host1 === $host2) {
            return true;
        }

        if (str_ends_with($host1, '.' . $host2) === true || str_ends_with($host2, '.' . $host1) === true) {
            return true;
        }

        $domain1 = self::registrableDomain($host1);
        $domain2 = self::registrableDomain($host2);
        if ($domain1 !== null && $domain1 === $domain2) {
            return true;
        }

        return false;
    }

    /**
     * Performs an HTTP request with SSRF protection and retry logic.
     *
     * @param array<string, mixed> $config
     */
    public function request(string $url, array $config = []): Response
    {
        if ($url === '') {
            // Permanent configuration/programming error, not a transient timeout.
            // An explicit code avoids httpStatusFromException() mapping code 0 to 503.
            throw new \HttpException('URL cannot be empty', 500);
        }

        $ssrfCheck = $config['ssrf_check'] ?? null;
        if ($ssrfCheck === null) {
            $ssrfCheck = self::isSsrfCheckEnabled();
        }

        $resolveEntries = $this->buildResolveEntries($url, $ssrfCheck === true);

        $curlOptionsRaw = $config['curl_options'] ?? [];
        $optOutRequested = is_array($curlOptionsRaw) === true ? self::isOptOut($curlOptionsRaw) : false;
        $savedEnv = null;
        if ($optOutRequested === true && getenv('CURL_IMPERSONATE') !== false) {
            $savedEnv = (string)getenv('CURL_IMPERSONATE');
            putenv('CURL_IMPERSONATE');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            if ($savedEnv !== null) {
                putenv('CURL_IMPERSONATE=' . $savedEnv);
            }
            throw new \HttpException('Failed to initialize cURL');
        }

        try {
            return $this->doRequest($ch, $url, $config, $resolveEntries, $optOutRequested, $savedEnv);
        } finally {
            if ($savedEnv !== null) {
                putenv('CURL_IMPERSONATE=' . $savedEnv);
            }
        }
    }

    /**
     * Internal method that performs the actual cURL request with retry logic.
     *
     * @param array<string, mixed> $config
     * @param list<string> $resolveEntries
     */
    private function doRequest(
        \CurlHandle $ch,
        string $url,
        array $config,
        array $resolveEntries,
        bool $optOutRequested,
        ?string $savedEnv
    ): Response {
        if ($optOutRequested === true) {
            $config['curl_options'][self::impersonateOption()] = '';
        }

        $defaultConfig = [
            'useragent'             => null,
            'timeout'               => 5,
            'connect_timeout'       => null,
            'headers'               => [],
            'curl_options'          => [],
            'if_not_modified_since' => null,
            'if_modified_since'     => null,
            'if_unmodified_since'   => null,
            'last_modified'         => null,
            'retries'               => 2,
            'max_filesize'          => null,
            'max_redirections'      => 0,
            'ssrf_check'            => null,
        ];

        $config = array_merge($defaultConfig, $config);

        $budget = ((int)$config['timeout'] * (1 + (int)$config['retries'])) * 1.35;

        $maxExec = (int)ini_get('max_execution_time');
        if ($maxExec > 0) {
            $budget = min($budget, max(1.0, $maxExec - 2.0));
        }

        $deadline = $config['_deadline'] ?? (microtime(true) + $budget);

        $httpHeaders = [];
        $configHeaders = $config['headers'];
        if (is_array($configHeaders) === true) {
            foreach ($configHeaders as $name => $value) {
                $httpHeaders[] = sprintf('%s: %s', (string)$name, (string)$value);
            }
        }

        $impersonate = self::getImpersonateTarget();

        $curlOptions = [
            CURLOPT_HEADER          => false,
            CURLOPT_HTTPHEADER      => $httpHeaders,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_MAXREDIRS       => 0,
            CURLOPT_TIMEOUT         => (int)$config['timeout'],
            CURLOPT_CONNECTTIMEOUT  => (int)($config['connect_timeout'] ?? min(10, (int)((int)$config['timeout'] / 2))),
            CURLOPT_NOSIGNAL        => true,
            // Advertise and transparently decode all supported content encodings.
            // Callers may override via curl_options; this is the safe default.
            CURLOPT_ENCODING        => '',
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];

        $configuredUa = $config['useragent'] ?? null;
        if (
            (is_string($configuredUa) === true && $configuredUa !== '')
            && ($impersonate === null || $optOutRequested === true)
        ) {
            $curlOptions[CURLOPT_USERAGENT] = $configuredUa;
        }

        if ($config['if_not_modified_since'] !== null) {
            $curlOptions[CURLOPT_TIMEVALUE] = (int)$config['if_not_modified_since'];
            $curlOptions[self::timevalueCriticalOption()] = 1;
            $curlOptions[CURLOPT_TIMECONDITION] = CURL_TIMECOND_IFMODSINCE;
        } elseif (($config['if_modified_since'] ?? null) !== null) {
            $curlOptions[CURLOPT_TIMEVALUE] = (int)$config['if_modified_since'];
            $curlOptions[CURLOPT_TIMECONDITION] = CURL_TIMECOND_IFMODSINCE;
        } elseif (($config['if_unmodified_since'] ?? null) !== null) {
            $curlOptions[CURLOPT_TIMEVALUE] = (int)$config['if_unmodified_since'];
            $curlOptions[CURLOPT_TIMECONDITION] = CURL_TIMECOND_IFUNMODSINCE;
        } elseif (($config['last_modified'] ?? null) !== null) {
            $curlOptions[CURLOPT_TIMEVALUE] = (int)$config['last_modified'];
            $curlOptions[CURLOPT_TIMECONDITION] = CURL_TIMECOND_LASTMOD;
        }

        if ($config['max_filesize'] !== null) {
            $curlOptions[CURLOPT_MAXFILESIZE] = $config['max_filesize'];
            $curlOptions[CURLOPT_NOPROGRESS] = false;
            if (defined('CURLOPT_XFERINFOFUNCTION') === true) {
                $curlOptions[CURLOPT_XFERINFOFUNCTION] = function ($ch, $downloadSize, $downloaded, $uploadSize, $uploaded) use ($config) {
                    return ($downloaded > $config['max_filesize']) ? 1 : 0;
                };
            } else {
                $curlOptions[CURLOPT_PROGRESSFUNCTION] = function ($ch, $downloadSize, $downloaded, $uploadSize, $uploaded) use ($config) {
                    return ($downloaded > $config['max_filesize']) ? -1 : 0;
                };
            }
        }

        $configCurlOptions = $config['curl_options'];
        if (is_array($configCurlOptions) === true) {
            foreach ($configCurlOptions as $option => $value) {
                $curlOptions[(int)$option] = $value;
            }
        }

        if ($resolveEntries !== []) {
            $curlOptions[self::resolveOption()] = $resolveEntries;
        }

        if (curl_setopt_array($ch, $curlOptions) === false) {
            throw new \HttpException('Failed to set cURL options: tried to set an illegal curl option');
        }

        /** @var array<string, list<string>> $responseHeaders */
        $responseHeaders = [];
        $headerCallback = function ($ch, $rawHeader) use (&$responseHeaders) {
            $len = strlen($rawHeader);
            if ($rawHeader === "\r\n") {
                return $len;
            }
            if (preg_match('#^HTTP/(2|1\.1|1\.0)#', $rawHeader) === 1) {
                return $len;
            }
            $header = explode(':', $rawHeader, 2);
            if (count($header) !== 2) {
                return $len;
            }
            $name = mb_strtolower(trim($header[0]));
            $value = trim($header[1]);
            if (isset($responseHeaders[$name]) === false) {
                $responseHeaders[$name] = [];
            }
            $responseHeaders[$name][] = $value;
            return $len;
        };

        curl_setopt($ch, CURLOPT_HEADERFUNCTION, $headerCallback);

        $maxAttempts = 1 + (int)$config['retries'];
        $lastError = '';
        $lastErrno = 0;
        $body = false;

        $transientErrnos = [
            CURLE_COULDNT_CONNECT,
            CURLE_RECV_ERROR,
            CURLE_SEND_ERROR,
            CURLE_GOT_NOTHING,
            CURLE_PARTIAL_FILE,
            CURLE_SSL_CONNECT_ERROR,
        ];

        if (defined('CURLE_HTTP2') === true) {
            $transientErrnos[] = (int) constant('CURLE_HTTP2');
        } else {
            $transientErrnos[] = 16;
        }

        if (defined('CURLE_HTTP2_STREAM') === true) {
            $transientErrnos[] = (int) constant('CURLE_HTTP2_STREAM');
        } else {
            $transientErrnos[] = 96;
        }
        if ((int)$config['retries'] === 0) {
            $maxAttempts = 2;
        }

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $remaining = $deadline - microtime(true);
            if ($remaining < 1) {
                if ($attempt === 1) {
                    throw new \HttpException(sprintf(
                        'Refusing to request %s: no time left within the request deadline',
                        $url
                    ));
                }
                break;
            }
            $attemptTimeout = min((int)$config['timeout'], (int)ceil($remaining));
            $attemptConnectTimeout = min(
                (int)$curlOptions[CURLOPT_CONNECTTIMEOUT],
                max(1, $attemptTimeout - 1)
            );
            curl_setopt($ch, CURLOPT_TIMEOUT, $attemptTimeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $attemptConnectTimeout);
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, max(500, (int)round(min($remaining, $attemptTimeout) * 1000)));
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, max(500, $attemptConnectTimeout * 1000));

            $responseHeaders = [];
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, $headerCallback);

            $body = curl_exec($ch);
            if ($body !== false) {
                break;
            }

            $lastError = curl_error($ch);
            $lastErrno = curl_errno($ch);

            if (
                in_array($lastErrno, [
                    CURLE_SSL_CERTPROBLEM,
                    CURLE_SSL_CIPHER,
                    CURLE_BAD_CONTENT_ENCODING,
                    CURLE_URL_MALFORMAT,
                    CURLE_COULDNT_RESOLVE_HOST,
                    CURLE_OPERATION_TIMEDOUT,
                ], true) === true
            ) {
                break;
            }

            if ($attempt >= $maxAttempts) {
                break;
            }

            $isTransient = in_array($lastErrno, $transientErrnos, true) === true;
            if ((int)$config['retries'] === 0 && $isTransient === false) {
                break;
            }

            $remainingUs = (int)(($deadline - microtime(true)) * 1_000_000);
            if ($remainingUs < 1_000_000) {
                break;
            }
            if ($isTransient === true) {
                $delayUs = min(200_000, max(0, $remainingUs - 1_000_000));
                if ($delayUs <= 0) {
                    $delayUs = 1;
                }
            } else {
                $delayUs = min(
                    (2 ** ($attempt - 1)) * 300_000 + mt_rand(0, 200_000),
                    max(0, $remainingUs - 1_000_000)
                );
                if ($delayUs <= 0) {
                    break;
                }
            }
            usleep($delayUs);

            curl_reset($ch);
            assert($url !== '');
            curl_setopt($ch, CURLOPT_URL, $url);
            if (curl_setopt_array($ch, $curlOptions) === false) {
                throw new \HttpException('Failed to set cURL options: tried to set an illegal curl option');
            }
            if ($resolveEntries !== []) {
                curl_setopt($ch, self::resolveOption(), $resolveEntries);
            }
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, $headerCallback);
        }

        $statusCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($savedEnv !== null) {
            putenv('CURL_IMPERSONATE=' . $savedEnv);
            $savedEnv = null;
        }

        if ($body === false) {
            throw new \HttpException(sprintf(
                'cURL error %d: %s (see https://curl.se/libcurl/c/libcurl-errors.html) for %s',
                $lastErrno,
                $lastError,
                $url
            ));
        }

        if ($statusCode >= 300 && $statusCode < 400 && $statusCode !== 304) {
            /** @var array<string, list<string>> $responseHeaders */
            $locationHeaders = $responseHeaders['location'] ?? [];
            $location = count($locationHeaders) > 0 ? $locationHeaders[count($locationHeaders) - 1] : 'unknown';
            $originalParsed = self::parseHttpUrl($url);

            if ($originalParsed === null || $location === 'unknown') {
                return new Response((string)$body, $statusCode, $responseHeaders);
            }

            if (preg_match('#^[a-z][a-z0-9+\-.]*://#i', $location) === 0) {
                $location = self::resolveReference($originalParsed, $location);
            }

            $redirectParsed = self::parseHttpUrl($location);

            if ($redirectParsed === null) {
                throw new \HttpException(sprintf(
                    'Refusing to follow invalid redirect to %s for %s (SSRF protection: unsupported redirect target)',
                    $location,
                    $url
                ));
            }

            if (self::isSameEffectiveDomain($originalParsed['host'], $redirectParsed['host']) === true) {
                $redirectCount = (int)($config['_redirect_count'] ?? 0);
                if ($redirectCount >= 5) {
                    throw new \HttpException(sprintf(
                        'Too many redirects for %s (SSRF protection: redirect loop detected)',
                        $url
                    ));
                }

                $config['_redirect_count'] = $redirectCount + 1;
                $config['_deadline'] = $deadline;
                return $this->request($location, $config);
            }

            throw new \HttpException(sprintf(
                'Refusing to follow redirect to %s for %s (SSRF protection: cross-domain redirects are disabled to prevent bypasses)',
                $location,
                $url
            ));
        }

        return new Response((string)$body, $statusCode, $responseHeaders);
    }
}

final class Request
{
    /**
     * @var array<string, mixed>
     */
    private array $get;

    /**
     * @var array<string, mixed>
     */
    private array $server;

    /**
     * @var array<string, mixed>
     */
    private array $attributes;

    private function __construct()
    {
    }

    /**
     * Creates a Request from PHP superglobals.
     */
    public static function fromGlobals(): self
    {
        $self = new self();
        $self->get = $_GET;
        $self->server = $_SERVER;
        $self->attributes = [];
        return $self;
    }

    /**
     * Creates a Request from CLI arguments.
     *
     * @param array<int|string, mixed> $cliArgs
     */
    public static function fromCli(array $cliArgs): self
    {
        $self = new self();
        $get = [];
        foreach ($cliArgs as $key => $value) {
            // Note: PHP coerces numeric-string keys back to integers, so positional
            // CLI args end up with int keys. Lookups via get('0') still match because
            // PHP coerces numeric string offsets, so this is transparent to callers.
            $get[(string) $key] = $value;
        }
        $self->get = $get;
        $self->server = [];
        $self->attributes = [];
        return $self;
    }

    /**
     * Gets a GET parameter value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->get[$key] ?? $default;
    }

    /**
     * Gets a SERVER parameter value.
     */
    public function server(string $key, ?string $default = null): ?string
    {
        $value = $this->server[$key] ?? $default;
        return is_string($value) === true ? $value : $default;
    }

    /**
     * Returns a new Request with an added attribute.
     */
    public function withAttribute(string $name, mixed $value = true): self
    {
        $clone = clone $this;
        $clone->attributes[$name] = $value;
        return $clone;
    }

    /**
     * Gets an attribute value.
     */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Returns all GET parameters as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->get;
    }
}

final class Response
{
    public const STATUS_CODES = [
        // Informational
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        103 => 'Early Hints',

        // Success
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-Status',
        208 => 'Already Reported',
        226 => 'IM Used',

        // Redirection
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        306 => '(Unused)',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',

        // Client errors
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Content Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed',
        418 => "I'm a teapot",
        421 => 'Misdirected Request',
        422 => 'Unprocessable Content',
        423 => 'Locked',
        424 => 'Failed Dependency',
        425 => 'Too Early',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        451 => 'Unavailable For Legal Reasons',

        // Server errors
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        510 => 'Not Extended',
        511 => 'Network Authentication Required',
    ];

    private string $body;
    private int $code;

    /**
     * @var array<string, list<string>>
     */
    private array $headers;

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(string $body = '', int $code = 200, array $headers = [])
    {
        $this->body = $body;
        $this->code = $code;
        $this->headers = [];

        foreach ($headers as $name => $value) {
            $normalizedName = mb_strtolower($name);
            if (isset($this->headers[$normalizedName]) === false) {
                $this->headers[$normalizedName] = [];
            }
            if (is_string($value) === true) {
                $this->headers[$normalizedName][] = $value;
            } else {
                foreach ($value as $v) {
                    $this->headers[$normalizedName][] = $v;
                }
            }
        }

        // Responses built from HTML templates (error/exception pages) never
        // carried an explicit content-type. Without it the client has to
        // guess: browsers sniff the body and feed readers such as FreshRSS
        // may treat the error page as a malformed feed instead of showing
        // the user the details page. Sniff here once, centrally, so every
        // code path (actions, middlewares, cached copies) benefits.
        if ($this->getHeader('content-type') === null) {
            $sniffed = self::sniffContentType($body);
            if ($sniffed !== null) {
                $this->headers[mb_strtolower($sniffed[0])] = [$sniffed[1]];
            }
        }
    }

    /**
     * Detects the media type of a response body by its leading markup.
     *
     * Only looks at the first non-whitespace bytes, so plain-text error
     * messages keep their default handling (no header added).
     *
     * @return array{0: string, 1: string}|null [header name, header value]
     */
    private static function sniffContentType(string $body): ?array
    {
        $trimmed = ltrim($body, " \t\r\n\0\x0B");
        if ($trimmed === '') {
            return null;
        }

        if (
            str_starts_with($trimmed, '<!DOCTYPE html>') === true
            || str_starts_with($trimmed, '<html') === true
            || str_starts_with($trimmed, '<head') === true
            || str_starts_with($trimmed, '<div class="error"') === true
        ) {
            return ['content-type', 'text/html; charset=UTF-8'];
        }

        if (str_starts_with($trimmed, '<?xml') === true) {
            // XML-based feed formats (RSS/Atom/MRSS)
            return ['content-type', 'application/rss+xml; charset=UTF-8'];
        }

        return null;
    }

    /**
     * Gets the response body.
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Gets the HTTP status code.
     */
    public function getCode(): int
    {
        return $this->code;
    }

    /**
     * Gets the HTTP status line text.
     */
    public function getStatusLine(): string
    {
        return self::STATUS_CODES[$this->code] ?? '';
    }

    /**
     * Gets all response headers.
     *
     * @return array<string, list<string>>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Gets a specific header value.
     *
     * @template A of bool
     * @param A $all
     * @return (A is true ? list<string>|null : string|null)
     */
    public function getHeader(string $name, bool $all = false): string|array|null
    {
        $name = mb_strtolower($name);
        $header = $this->headers[$name] ?? null;
        if ($header === null) {
            return null;
        }
        if ($all === true) {
            return $header;
        }
        $last = end($header);
        return is_string($last) === true ? $last : null;
    }

    /**
     * Returns a new Response with a modified header.
     */
    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[mb_strtolower($name)] = [$value];
        return $clone;
    }

    /**
     * Returns a new Response without the given header.
     */
    public function withoutHeader(string $name): self
    {
        $clone = clone $this;
        unset($clone->headers[mb_strtolower($name)]);
        return $clone;
    }

    /**
     * Returns a new Response with a modified body.
     */
    public function withBody(string $body): self
    {
        $clone = clone $this;
        $clone->body = $body;
        return $clone;
    }

    /**
     * Sends the response to the client.
     */
    public function send(): void
    {
        http_response_code($this->code);
        foreach ($this->headers as $name => $values) {
            foreach ($values as $value) {
                header(sprintf('%s: %s', $name, $value));
            }
        }
        echo $this->body;
    }
}
