<?php

/**
 * HTTP client module containing request/response handling.
 *
 * This file contains multiple classes and an interface by design
 * (single-module approach). PSR-1 single-class-per-file rule is
 * intentionally disabled for this file.
 *
 * @phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses
 * @phpcs:disable Generic.Files.OneClassPerFile.MultipleFound
 * @phpcs:disable Generic.Files.OneInterfacePerFile.MultipleFound
 * @phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols
 */

declare(strict_types=1);

namespace RSSBridge\Http;

class HttpException extends \Exception
{
    public ?Response $response;

    public function __construct(string $message = '', int $statusCode = 0, ?Response $response = null)
    {
        parent::__construct($message, $statusCode);
        $this->response = $response ?? new Response('', 0);
    }

    public static function fromResponse(Response $response, string $url): HttpException
    {
        $message = sprintf(
            '%s resulted in %s %s',
            $url,
            $response->getCode(),
            $response->getStatusLine()
        );
        if (CloudFlareException::isCloudFlareResponse($response) === true) {
            return new CloudFlareException($message, $response->getCode(), $response);
        }
        return new HttpException(trim($message), $response->getCode(), $response);
    }
}

final class CloudFlareException extends HttpException
{
    public static function isCloudFlareResponse(Response $response): bool
    {
        $cloudflareTitles = [
            '<title>Just a moment...',
            '<title>Please Wait...',
            '<title>Attention Required!',
            '<title>Security | Glassdoor',
            '<title>Access denied</title>',
        ];
        $body = $response->getBody();
        foreach ($cloudflareTitles as $title) {
            if (str_contains($body, $title) === true) {
                return true;
            }
        }
        return false;
    }
}

interface HttpClient
{
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

    /** @var string[] */
    private array $blockedCidrs;

    /** @var string[] */
    private array $allowedCidrs;

    /** @var array<string, array{time: int, ips: string[]}> */
    private static array $cache = [];

    private const CACHE_TTL = 60;

    /**
     * Shorter TTL for failed lookups: a rebinding attacker who temporarily
     * makes DNS unavailable must not be able to force repeated uncached
     * re-resolutions (which would widen the TOCTOU window and amplify load).
     */
    private const NEGATIVE_CACHE_TTL = 10;

    /**
     * @param string[]|string|null $blockedCidrs
     * @param string[]|string|null $allowedCidrs
     */
    public function __construct(array|string|null $blockedCidrs = null, array|string|null $allowedCidrs = null)
    {
        $mergedBlocked = self::DEFAULT_BLOCKED_CIDRS;

        if ($blockedCidrs !== null) {
            if (is_string($blockedCidrs) === true) {
                $blockedCidrs = explode(',', $blockedCidrs);
            }
            foreach ($blockedCidrs as $cidr) {
                $cidr = trim((string)$cidr);
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
                $cidr = trim((string)$cidr);
                $normalizedCidr = self::normalizeToCidr($cidr);
                if ($normalizedCidr !== '' && self::isValidCidr($normalizedCidr) === true) {
                    $mergedAllowed[] = $normalizedCidr;
                }
            }
        }

        $this->allowedCidrs = array_values(array_unique($mergedAllowed));
    }

    /**
     * Converts a single IP address to a CIDR notation (/32 for IPv4, /128 for IPv6).
     * If the input is already a CIDR, returns it unchanged.
     */
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
     * Resolves a hostname to its IP addresses through the process-wide DNS
     * cache.
     *
     * The cache is the foundation of the DNS-rebinding protection: every
     * outbound request pins the connection (via CURLOPT_RESOLVE) to exactly
     * the IPs vetted here, so the "resolve -> check -> connect" sequence uses
     * one single immutable resolution result. Flushing an entry (see
     * clearCache()) forces a fresh lookup on the next request.
     *
     * @return string[]
     */
    public function resolve(string $host): array
    {
        $host = strtolower(trim($host, '.'));
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
        return $ips;
    }

    /**
     * Drops cached DNS answers for one host (or all hosts when omitted).
     * The next resolve() performs a fresh network lookup.
     */
    public static function clearCache(?string $host = null): void
    {
        if ($host === null) {
            self::$cache = [];
            return;
        }
        unset(self::$cache[strtolower(trim($host, '.'))]);
    }

    public function isAllowed(string $ip): bool
    {
        return $this->isBlocked($ip) === false;
    }

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

    public function isWhitelisted(string $ip): bool
    {
        foreach ($this->allowedCidrs as $cidr) {
            if (self::matches($ip, $cidr) === true) {
                return true;
            }
        }
        return false;
    }

    public static function matches(string $ip, string $cidr): bool
    {
        [$block, $bitsStr] = str_contains($cidr, '/') === true ? explode('/', $cidr, 2) : [$cidr, null];
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

    private static function unwrapV4Mapped(string $bin): string
    {
        if (strlen($bin) === 16 && substr($bin, 0, 10) === str_repeat("\x00", 10) && substr($bin, 10, 2) === "\xff\xff") {
            return substr($bin, 12);
        }
        return $bin;
    }

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

    private static function impersonateOption(): int
    {
        return defined('CURLOPT_IMPERSONATE') === true ? (int)constant('CURLOPT_IMPERSONATE') : self::IMPERSONATE_OPT;
    }

    private static function resolveOption(): int
    {
        return defined('CURLOPT_RESOLVE') === true ? (int)constant('CURLOPT_RESOLVE') : self::RESOLVE_OPT;
    }

    public static function getImpersonateTarget(): ?string
    {
        $target = getenv('CURL_IMPERSONATE');
        if (is_string($target) === true && $target !== '') {
            return $target;
        }
        return null;
    }

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

    private static function ipResolver(): IpResolver
    {
        if (self::$ipResolver instanceof IpResolver === false) {
            $blocked = null;
            $allowed = null;
            if (class_exists(\RSSBridge\Configuration::class) === true) {
                $blocked = \RSSBridge\Configuration::getConfig('http', 'blocked_ip_ranges');
                $allowed = \RSSBridge\Configuration::getConfig('http', 'allowed_ip_ranges');
            }
            self::$ipResolver = new IpResolver($blocked, $allowed);
        }
        return self::$ipResolver;
    }

    private static function isSsrfCheckEnabled(): bool
    {
        if (class_exists(\RSSBridge\Configuration::class) === false) {
            return true;
        }
        return \RSSBridge\Configuration::getConfig('http', 'ssrf_check') !== false;
    }

    /**
     * Parses an absolute URL into its security-relevant components.
     *
     * Returns null for anything that is not a plain http/https URL with a
     * usable host (unparseable input, missing scheme/host, foreign schemes
     * such as file:// or gopher://, empty or malformed hosts). The returned
     * host is normalized so that two spellings of the same resource always
     * compare equal in the SSRF redirect check:
     *  - lowercased (DNS hostnames are case-insensitive);
     *  - IPv6 brackets removed ("[::1]" -> "::1");
     *  - trailing root dots removed ("example.com." == "example.com"), while
     *    a leading dot is preserved because it pins cookies to an exact host
     *    and must stay distinguishable from the bare name.
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
            $host = substr($host, 1, -1); // literal IPv6 without brackets
        }
        if ($host !== '' && str_starts_with($host, '.') === false) {
            $host = rtrim($host, '.'); // strip FQDN root dot(s), keep ".exact" hosts
        }
        if ($host === '') {
            return null;
        }

        return [
            'host' => $host,
            'port' => (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80)),
            'scheme' => $scheme,
            'path' => $parts['path'] ?? '/',
        ];
    }

    /**
     * Builds the CURLOPT_RESOLVE entries that pin a hostname to its
     * DNS-cached, SSRF-vetted IP addresses.
     *
     * DNS-rebinding protection works like this:
     *  1. The hostname is resolved exactly once through IpResolver::resolve(),
     *     which caches every answer for CACHE_TTL seconds.
     *  2. All resolved IPs are screened against the blocked CIDR ranges; the
     *     request is refused when no vetted address remains.
     *  3. Every vetted address is handed to cURL via CURLOPT_RESOLVE, so the
     *     connection reuses the cached result instead of triggering a second
     *     lookup. An attacker-controlled TTL-0 record can therefore no longer
     *     flip the name from a public IP to an internal one between the check
     *     and the connect ("check TOCTOU" rebinding).
     *  4. Because several A/AAAA records may be valid at once, all allowed IPs
     *     are pinned (host:port:ip1,ip2,...) rather than only the first one,
     *     preserving Happy-Eyeballs-style fallback without losing protection.
     *
     * Entries use the "+host:port:ip" form so previously cached connections
     * bound to a stale (possibly rebound) address are flushed from the shared
     * handle before the new pinning takes effect.
     *
     * @return string[] Zero or more CURLOPT_RESOLVE entry strings.
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

        // Literal IP addresses need no resolution step: validate and let cURL
        // connect directly.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (self::ipResolver()->isBlocked($host) === true) {
                throw new HttpException(sprintf(
                    'Access to %s is blocked: the IP falls into a private or reserved range (SSRF protection)',
                    $host
                ));
            }
            return [];
        }

        // Single resolution point fed by the DNS cache (IpResolver::$cache).
        $ips = self::ipResolver()->resolve($host);
        if ($ips === []) {
            // Nothing could be resolved; leave it to cURL to report the error.
            return [];
        }

        $allowedIps = [];
        foreach ($ips as $ip) {
            if (self::ipResolver()->isAllowed($ip) === true) {
                $allowedIps[] = $ip;
            }
        }

        if ($allowedIps === []) {
            throw new HttpException(sprintf(
                'Access to %s is blocked: the host resolves only to private or reserved IP ranges (SSRF protection)',
                $host
            ));
        }

        // Pin the exact vetted set in the order returned by the resolver so a
        // later, unchecked re-resolution cannot influence the connection.
        return [sprintf('+%s:%d:%s', $host, $port, implode(',', $allowedIps))];
    }

    /**
     * Checks if two hosts belong to the same effective domain tree.
     * This allows safe canonical redirects (e.g., www.example.com <-> example.com)
     * while still blocking actual cross-domain SSRF bypasses.
     *
     * @param string $host1 The original host.
     * @param string $host2 The redirect target host.
     * @return bool True if they are the same domain or subdomain of each other.
     */
    private static function isSameEffectiveDomain(string $host1, string $host2): bool
    {
        if ($host1 === $host2) {
            return true;
        }

        // Allow redirects between a domain and its subdomains.
        // e.g., 'www.djmag.com' ends with '.djmag.com' -> True (Safe)
        // e.g., 'evil.com' ends with '.djmag.com' -> False (Blocked)
        if (str_ends_with($host1, '.' . $host2) === true || str_ends_with($host2, '.' . $host1) === true) {
            return true;
        }

        return false;
    }

    public function request(string $url, array $config = []): Response
    {
        $ssrfCheck = $config['ssrf_check'] ?? null;
        if ($ssrfCheck === null) {
            $ssrfCheck = self::isSsrfCheckEnabled();
        }

        $resolveEntries = $this->buildResolveEntries($url, $ssrfCheck === true);

        $optOutRequested = self::isOptOut($config['curl_options'] ?? []);
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
            throw new HttpException('Failed to initialize cURL');
        }

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
            'retries'               => 2,
            'max_filesize'          => null,
            'max_redirections'      => 0,
            'ssrf_check'            => null,
        ];

        $config = array_merge($defaultConfig, $config);

        // Overall time budget for this request (all attempts and backoff
        // delays included). Every individual attempt is then capped by the
        // time remaining at the start of that attempt ("deadline-aware"
        // timeout), so retries can never push the total runtime past the
        // deadline and get the PHP-FPM worker force-killed mid-response,
        // which would produce truncated/incorrect output.
        $budget = ((int)$config['timeout'] * (1 + (int)$config['retries'])) * 1.35;

        // If a PHP max_execution_time is configured (typical in FPM setups),
        // clamp the budget to it minus a small safety margin so the client
        // finishes gracefully before PHP aborts the script.
        $maxExec = (int)ini_get('max_execution_time');
        if ($maxExec > 0) {
            $budget = min($budget, max(1.0, $maxExec - 2.0));
        }

        // Preserve an already-propagated deadline across internal redirects.
        $deadline = $config['_deadline'] ?? (microtime(true) + $budget);

        $httpHeaders = [];
        foreach ($config['headers'] as $name => $value) {
            $httpHeaders[] = sprintf('%s: %s', $name, $value);
        }

        $impersonate = self::getImpersonateTarget();

        $curlOptions = [
            CURLOPT_HEADER          => false,
            CURLOPT_HTTPHEADER      => $httpHeaders,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_MAXREDIRS       => 0,
            CURLOPT_TIMEOUT         => $config['timeout'],
            CURLOPT_CONNECTTIMEOUT  => $config['connect_timeout'] ?? min(10, (int)($config['timeout'] / 2)),
            CURLOPT_NOSIGNAL        => true,
            CURLOPT_ENCODING        => '',
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];

        $configuredUa = $config['useragent'] ?? null;
        if (
            ($configuredUa !== null && $configuredUa !== '') === true
            && ($impersonate === null || $optOutRequested === true)
        ) {
            $curlOptions[CURLOPT_USERAGENT] = $configuredUa;
        }

        if ($config['if_not_modified_since'] !== null) {
            $curlOptions[CURLOPT_TIMEVALUE] = $config['if_not_modified_since'];
            $curlOptions[CURLOPT_TIMECONDITION] = CURL_TIMECOND_IFMODSINCE;
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

        foreach ($config['curl_options'] as $option => $value) {
            $curlOptions[$option] = $value;
        }

        if ($resolveEntries !== []) {
            $curlOptions[self::resolveOption()] = $resolveEntries;
        }

        if (curl_setopt_array($ch, $curlOptions) === false) {
            throw new HttpException('Failed to set cURL options: tried to set an illegal curl option');
        }

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

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            // Deadline-aware timeout: never let a single attempt run longer
            // than the time left until the overall deadline. This keeps the
            // whole request (including retries and backoff delays) inside the
            // budget instead of being cut off by PHP-FPM's hard timeout, which
            // would return truncated or otherwise incorrect responses.
            $remaining = $deadline - microtime(true);
            if ($remaining < 1) {
                if ($attempt === 1) {
                    throw new HttpException(sprintf(
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

            if ($attempt < $maxAttempts) {
                // Back off only as long as the deadline allows; if the delay
                // would consume nearly all of the remaining budget, stop
                // retrying instead of starting an attempt that is doomed to
                // be truncated.
                $remainingUs = (int)(($deadline - microtime(true)) * 1_000_000);
                $delayUs = min(
                    (2 ** ($attempt - 1)) * 300_000 + mt_rand(0, 200_000),
                    max(0, $remainingUs - 1_000_000)
                );
                if ($remainingUs < 1_000_000 || $delayUs <= 0) {
                    break;
                }
                usleep($delayUs);

                curl_reset($ch);
                curl_setopt($ch, CURLOPT_URL, $url);
                if (curl_setopt_array($ch, $curlOptions) === false) {
                    throw new HttpException('Failed to set cURL options: tried to set an illegal curl option');
                }
                // Re-pin on every attempt: the DNS cache entry (and with it the
                // vetted IP set) may have been refreshed between retries.
                if ($resolveEntries !== []) {
                    curl_setopt($ch, self::resolveOption(), $resolveEntries);
                }
                curl_setopt($ch, CURLOPT_HEADERFUNCTION, $headerCallback);
            }
        }

        $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($savedEnv !== null) {
            putenv('CURL_IMPERSONATE=' . $savedEnv);
            $savedEnv = null;
        }

        if ($body === false) {
            throw new HttpException(sprintf(
                'cURL error %d: %s (see https://curl.se/libcurl/c/libcurl-errors.html) for %s',
                $lastErrno,
                $lastError,
                $url
            ));
        }

        if ($statusCode >= 300 && $statusCode < 400 && $statusCode !== 304) {
            $location = $responseHeaders['location'][count($responseHeaders['location']) - 1] ?? 'unknown';
            $originalParsed = self::parseHttpUrl($url);

            // Normalize relative redirects to absolute URLs so parseHttpUrl can evaluate them securely
            if ($originalParsed !== null && preg_match('#^[a-z][a-z0-9+\-.]*://#i', $location) === 0) {
                $scheme = $originalParsed['scheme'];
                $host = $originalParsed['host'];
                $port = $originalParsed['port'];

                if (str_starts_with($location, '/') === true) {
                    $path = $location;
                } else {
                    $basePath = dirname($originalParsed['path']);
                    if ($basePath === '.') {
                        $basePath = '/';
                    }
                    $path = rtrim($basePath, '/') . '/' . ltrim($location, '/');
                }

                $location = ($port === 80 || $port === 443) ? sprintf('%s://%s%s', $scheme, $host, $path) : sprintf('%s://%s:%d%s', $scheme, $host, $port, $path);
            }

            $redirectParsed = self::parseHttpUrl($location);

            // FIX: Use isSameEffectiveDomain to allow www <-> non-www redirects safely
            if (
                $originalParsed !== null
                && $redirectParsed !== null
                && self::isSameEffectiveDomain($originalParsed['host'], $redirectParsed['host']) === true
            ) {
                $redirectCount = $config['_redirect_count'] ?? 0;
                if ($redirectCount >= 5) {
                    throw new HttpException(sprintf(
                        'Too many redirects for %s (SSRF protection: redirect loop detected)',
                        $url
                    ));
                }

                $config['_redirect_count'] = $redirectCount + 1;
                // Propagate the deadline so a chain of internal redirects
                // shares one single time budget (deadline-aware across hops).
                $config['_deadline'] = $deadline;
                return $this->request($location, $config);
            }

            throw new HttpException(sprintf(
                'Refusing to follow redirect to %s for %s (SSRF protection: cross-domain redirects are disabled to prevent bypasses)',
                $location,
                $url
            ));
        }

        return new Response($body, $statusCode, $responseHeaders);
    }
}

final class Request
{
    private array $get;
    private array $server;
    private array $attributes;

    private function __construct()
    {
    }

    public static function fromGlobals(): self
    {
        $self = new self();
        $self->get = $_GET;
        $self->server = $_SERVER;
        $self->attributes = [];
        return $self;
    }

    public static function fromCli(array $cliArgs): self
    {
        $self = new self();
        $self->get = $cliArgs;
        return $self;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->get[$key] ?? $default;
    }

    public function server(string $key, ?string $default = null): ?string
    {
        return $this->server[$key] ?? $default;
    }

    public function withAttribute(string $name, mixed $value = true): self
    {
        $clone = clone $this;
        $clone->attributes[$name] = $value;
        return $clone;
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function toArray(): array
    {
        return $this->get;
    }
}

final class Response
{
    public const STATUS_CODES = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
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
        413 => 'Request Entity Too Large',
        414 => 'Request-URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Requested Range Not Satisfiable',
        417 => 'Expectation Failed',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
    ];

    private string $body;
    private int $code;
    private array $headers;

    public function __construct(string $body = '', int $code = 200, array $headers = [])
    {
        $this->body = $body;
        $this->code = $code;
        $this->headers = [];

        foreach ($headers as $name => $value) {
            $name = mb_strtolower((string)$name);
            if (isset($this->headers[$name]) === false) {
                $this->headers[$name] = [];
            }
            if (is_string($value) === true) {
                $this->headers[$name][] = $value;
            } elseif (is_array($value) === true) {
                $this->headers[$name] = $value;
            }
        }
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getCode(): int
    {
        return $this->code;
    }

    public function getStatusLine(): string
    {
        return self::STATUS_CODES[$this->code] ?? '';
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

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

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[mb_strtolower($name)] = [$value];
        return $clone;
    }

    public function withBody(string $body): self
    {
        $clone = clone $this;
        $clone->body = $body;
        return $clone;
    }

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

class_alias(\RSSBridge\Http\HttpException::class, 'HttpException');
class_alias(\RSSBridge\Http\CloudFlareException::class, 'CloudFlareException');
class_alias(\RSSBridge\Http\HttpClient::class, 'HttpClient');
class_alias(\RSSBridge\Http\IpResolver::class, 'IpResolver');
class_alias(\RSSBridge\Http\CurlHttpClient::class, 'CurlHttpClient');
class_alias(\RSSBridge\Http\Request::class, 'Request');
class_alias(\RSSBridge\Http\Response::class, 'Response');
