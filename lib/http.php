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

/**
 * Screens hostnames/IPs against private and reserved ranges (SSRF protection).
 * Resolution results are cached per-process; the vetted IP is pinned via
 * CURLOPT_RESOLVE afterwards to close the DNS-rebinding window.
 */
final class IpResolver
{
    /** Loopback, RFC 1918, link-local (cloud metadata), CGNAT, reserved + IPv6 equivalents. */
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

    /** @var array<string, array{time: int, ips: string[]}> */
    private static array $cache = [];

    private const CACHE_TTL = 60;

    /** @param string[]|string|null $blockedCidrs */
    public function __construct(array|string|null $blockedCidrs = null)
    {
        if ($blockedCidrs === null) {
            $this->blockedCidrs = self::DEFAULT_BLOCKED_CIDRS;
            return;
        }
        if (is_string($blockedCidrs) === true) {
            $blockedCidrs = explode(',', $blockedCidrs);
        }
        $normalized = [];
        foreach ($blockedCidrs as $cidr) {
            $cidr = trim((string)$cidr);
            if ($cidr !== '' && self::isValidCidr($cidr) === true) {
                $normalized[] = $cidr;
            }
        }
        $this->blockedCidrs = $normalized;
    }

    /**
     * Resolves a hostname to its IP addresses (A + AAAA records). Literal IPs
     * pass through unchanged; empty list when the name does not resolve.
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
        if ($cached !== null && (time() - $cached['time']) < self::CACHE_TTL) {
            return $cached['ips'];
        }

        $ips = gethostbynamel($host);
        $ips = is_array($ips) ? $ips : [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6']) === true && filter_var($record['ipv6'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                $ips[] = $record['ipv6'];
            }
        }

        $ips = array_values(array_unique($ips));
        self::$cache[$host] = ['time' => time(), 'ips' => $ips];
        return $ips;
    }

    public function isAllowed(string $ip): bool
    {
        return $this->isBlocked($ip) === false;
    }

    public function isBlocked(string $ip): bool
    {
        // Anything that is not a valid IP is treated as hostile input.
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true;
        }
        foreach ($this->blockedCidrs as $cidr) {
            if (self::matches($ip, $cidr) === true) {
                return true;
            }
        }
        return false;
    }

    /**
     * Bitwise CIDR match. Handles IPv4/IPv6 and unwraps IPv4-mapped IPv6
     * (::ffff:a.b.c.d) so the IPv4 policy applies to those as well.
     */
    public static function matches(string $ip, string $cidr): bool
    {
        [$block, $bitsStr] = str_contains($cidr, '/') ? explode('/', $cidr, 2) : [$cidr, null];
        $ipBin = inet_pton($ip);
        $blockBin = inet_pton($block);
        if ($ipBin === false || $blockBin === false) {
            return false;
        }
        // Without an explicit prefix length, require an exact same-family match.
        $bits = $bitsStr === null ? strlen($blockBin) * 8 : (int)$bitsStr;

        $ipBin = self::unwrapV4Mapped($ipBin);
        $blockBin = self::unwrapV4Mapped($blockBin);

        if (strlen($ipBin) !== strlen($blockBin)) {
            return false; // different families never match
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
        [$ip, $bits] = str_contains($cidr, '/') ? explode('/', $cidr, 2) : [$cidr, null];
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
    /** Fallback numeric value of CURLOPT_OPENSOCKETFUNCTION for exotic builds. */
    private const OPEN_SOCKET_OPT = 20049;

    /** Fallback numeric value of CURLOPT_RESOLVE. */
    private const RESOLVE_OPT = 10203; // CURLOPT_RESOLVE

    /**
     * The curl-impersonate extension option used to select/disable a
     * spoofed browser profile per handle. Defined by the patched libcurl
     * shipped in the Docker image (lexiforest/curl-impersonate) when PHP's
     * cURL extension is built against it; the numeric fallback matches the
     * library's CURLOPTIMPERSONATE enum value.
     */
    private const IMPERSONATE_OPT = 10240; // CURLOPT_IMPERSONATE

    /**
     * Resolves the real CURLOPT_IMPERSONATE constant when available.
     */
    private static function impersonateOption(): int
    {
        return defined('CURLOPT_IMPERSONATE') === true ? (int)constant('CURLOPT_IMPERSONATE') : self::IMPERSONATE_OPT;
    }

    /**
     * Resolves the real CURLOPT_RESOLVE constant when available.
     */
    private static function resolveOption(): int
    {
        return defined('CURLOPT_RESOLVE') === true ? (int)constant('CURLOPT_RESOLVE') : self::RESOLVE_OPT;
    }

    /**
     * Resolves the real CURLOPT_OPENSOCKETFUNCTION constant when available.
     */
    private static function openSocketOption(): int
    {
        return defined('CURLOPT_OPENSOCKETFUNCTION') === true ? (int)constant('CURLOPT_OPENSOCKETFUNCTION') : self::OPEN_SOCKET_OPT;
    }

    /**
     * Returns the curl-impersonate target (e.g. "chrome150") that is in
     * effect for this process, or null when running against plain libcurl.
     *
     * How this works (verified against lexiforest/curl-impersonate):
     * the patched library calls curl_easy_impersonate() automatically from
     * curl_easy_init()/curl_easy_reset() whenever the CURL_IMPERSONATE
     * environment variable is set. That single call sets the full browser
     * fingerprint AND a base header list that includes the real Chrome
     * User-Agent and sec-ch-ua / Accept-* client-hint headers.
     *
     * Individual overrides from bridges (a custom User-Agent header, an
     * extra Accept, forcing HTTP/1.1 for a specific site) are honoured as
     * is: curl merges app headers over the profile defaults one-by-one,
     * so only the explicitly overridden element changes while the rest of
     * the profile keeps working. This method only reports whether the
     * global profile machinery is available at all.
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
     * Detects whether the bridge opted out of impersonation for this request
     * by passing CURLOPT_IMPERSONATE => '' (or 'none'), which resets the
     * handle to a vanilla curl configuration.
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

    /**
     * The shared IP screening policy (private/reserved ranges). Built once
     * per process from the [http] config section.
     */
    private static ?IpResolver $ipResolver = null;

    private static function ipResolver(): IpResolver
    {
        if (self::$ipResolver instanceof IpResolver === false) {
            // Configuration may be absent in bare unit-test contexts; fall
            // back to the secure default list then.
            $blocked = null;
            if (class_exists(\RSSBridge\Configuration::class) === true) {
                $blocked = \RSSBridge\Configuration::getConfig('http', 'blocked_ip_ranges');
            }
            self::$ipResolver = new IpResolver($blocked);
        }
        return self::$ipResolver;
    }

    /**
     * Splits an absolute URL into lowercase host (+ port) and the default
     * remote port, or returns null when the URL is not http(s).
     *
     * @return array{host: string, port: int}|null
     */
    private static function parseHttpUrl(string $url): ?array
    {
        $parts = parse_url($url);
        if ($parts === false || isset($parts['host']) === false || isset($parts['scheme']) === false) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if (in_array($scheme, ['http', 'https'], true) === false) {
            return null;
        }
        $host = strtolower($parts['host']);
        // Strip the surrounding brackets of IPv6 literals ([::1]).
        if (str_starts_with($host, '[') === true && str_ends_with($host, ']') === true) {
            $host = substr($host, 1, -1);
        }
        return ['host' => $host, 'port' => (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80))];
    }

    /**
     * SSRF check used inside the connect callback below. Returns false for
     * literal hosts that are blocked and for any hostname we cannot resolve;
     * hostnames are allowed to pass so libcurl can perform its own lookup
     * (and produce the proper error) when screening is unavailable.
     */
    private static function isConnectAddressAllowed(string $host): bool
    {
        $resolver = self::ipResolver();
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $resolver->isAllowed($host);
        }
        $ips = $resolver->resolve($host);
        if ($ips === []) {
            return true;
        }
        foreach ($ips as $ip) {
            if ($resolver->isAllowed($ip) === true) {
                return true;
            }
        }
        return false;
    }

    /**
     * CURLOPT_OPENSOCKETFUNCTION callback: screens the IP address that
     * libcurl is about to connect to. This closes the DNS-rebinding window
     * entirely — even if a short-TTL record flips between our primary
     * resolution and the connect, the socket is only opened to vetted
     * addresses. Returning CURL_SOCKET_BAD aborts the connection.
     *
     * @param \CurlHandle $handle
     * @param int $socketType
     * @param array{address: string, port: int, ...} $addressInfo
     */
    private static function guardSocket($handle, int $socketType, array $addressInfo)
    {
        if (self::isSsrfCheckEnabled() === false) {
            return null; // let libcurl create the socket itself
        }
        if (self::isConnectAddressAllowed($addressInfo['address'] ?? '') === false) {
            throw new HttpException(sprintf(
                'Access to %s (%s) is blocked: the address falls into a private or reserved range (SSRF protection)',
                $addressInfo['address'] ?? '?',
                $addressInfo['hostname'] ?? '?'
            ));
        }
        return null;
    }

    /** Whether SSRF screening is enabled (config [http] ssrf_check, default on). */
    private static function isSsrfCheckEnabled(): bool
    {
        if (class_exists(\RSSBridge\Configuration::class) === false) {
            return true;
        }
        return \RSSBridge\Configuration::getConfig('http', 'ssrf_check') !== false;
    }

    /**
     * Resolves a possibly relative Location header against the URL it came
     * from. Returns null for non-http(s) targets, which are refused.
     */
    private static function resolveRedirectLocation(string $location, string $fromUrl): ?string
    {
        $location = trim($location);
        if ($location === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }
        // Relative to the issuing URL per RFC 3986.
        $base = parse_url($fromUrl);
        if ($base === false || isset($base['scheme']) === false || isset($base['host']) === false) {
            return null;
        }
        $origin = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
        if (str_starts_with($location, '//') === true) {
            return $base['scheme'] . ':' . $location;
        }
        if (str_starts_with($location, '/') === true) {
            return $origin . $location;
        }
        $dirPath = '/';
        if (isset($base['path']) === true) {
            $pos = strrpos($base['path'], '/');
            $dirPath = $pos === false ? '/' : substr($base['path'], 0, $pos + 1);
        }
        return $origin . $dirPath . $location;
    }

    public function request(string $url, array $config = []): Response
    {
        // SSRF protection: the target host is resolved up front and refused
        // when it only points at private/reserved ranges; every connect is
        // additionally screened in the open-socket callback (see below),
        // which also covers redirect hops and DNS rebinding.
        $ssrfCheck = $config['ssrf_check'] ?? null;
        if ($ssrfCheck === null) {
            $ssrfCheck = self::isSsrfCheckEnabled();
        }
        if ($ssrfCheck === true) {
            $parsed = self::parseHttpUrl($url);
            if ($parsed !== null && $parsed['host'] !== '' && self::isConnectAddressAllowed($parsed['host']) === false) {
                throw new HttpException(sprintf(
                    'Access to %s is blocked: the host resolves to a private or reserved IP range (SSRF protection)',
                    $parsed['host']
                ));
            }
        }

        // An explicit per-request opt-out (CURLOPT_IMPERSONATE => '') also
        // means "no global profile": strip the env var before curl_init()
        // so the patched library does not auto-apply the profile to this
        // handle, and restore it afterwards (putenv affects the whole
        // process; php-fpm workers handle one request at a time).
        $optOutRequested = self::isOptOut($config['curl_options'] ?? []);
        $savedEnv = null;
        if ($optOutRequested === true && getenv('CURL_IMPERSONATE') !== false) {
            $savedEnv = (string)getenv('CURL_IMPERSONATE');
            putenv('CURL_IMPERSONATE');
        }
        return self::$ipResolver;
    }

    /**
     * Splits an absolute URL into a lowercase host, or returns null when the
     * URL is not http(s).
     */
    private static function parseHttpHost(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || isset($parts['host']) === false || isset($parts['scheme']) === false) {
            return null;
        }
        if (in_array(strtolower($parts['scheme']), ['http', 'https'], true) === false) {
            return null;
        }
        $host = strtolower($parts['host']);
        // Strip the surrounding brackets of IPv6 literals ([::1]).
        if (str_starts_with($host, '[') === true && str_ends_with($host, ']') === true) {
            $host = substr($host, 1, -1);
        }
        return $host;
    }

    /**
     * Screens a host or literal IP against the blocked ranges. An unresolved
     * hostname passes so libcurl performs its own lookup and reports the
     * proper error; the connect callback below still screens the real IP.
     */
    private static function isHostAllowed(string $host): bool
    {
        $resolver = self::ipResolver();
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $resolver->isAllowed($host);
        }
        $ips = $resolver->resolve($host);
        if ($ips === []) {
            return true;
        }
        foreach ($ips as $ip) {
            if ($resolver->isAllowed($ip) === true) {
                return true;
            }
        }
        return false;
    }

    /**
     * CURLOPT_OPENSOCKETFUNCTION callback: screens the exact IP that libcurl
     * is about to connect to (every redirect hop included), which closes the
     * DNS-rebinding window without extra lookups. Returning null lets
     * libcurl create the socket itself; blocked addresses abort the connect.
     *
     * @param \CurlHandle $handle
     * @param int $socketType
     * @param array{address: string, port: int, ...} $addressInfo
     */
    private static function guardSocket($handle, int $socketType, array $addressInfo)
    {
        if (self::isSsrfCheckEnabled() === false) {
            return null;
        }
        $address = $addressInfo['address'] ?? '';
        if (self::isHostAllowed($address) === false) {
            throw new HttpException(sprintf(
                'Access to %s (%s) is blocked: the address falls into a private or reserved range (SSRF protection)',
                $address,
                $addressInfo['hostname'] ?? '?'
            ));
        }
        return null;
    }

    /** Whether SSRF screening is enabled (config [http] ssrf_check, default on). */
    private static function isSsrfCheckEnabled(): bool
    {
        if (class_exists(\RSSBridge\Configuration::class) === false) {
            return true;
        }
        return \RSSBridge\Configuration::getConfig('http', 'ssrf_check') !== false;
    }

    public function request(string $url, array $config = []): Response
    {
        $defaultConfig = [
            'useragent'             => null,
            'timeout'               => 5,
            'connect_timeout'       => null,
            'headers'               => [],
            'curl_options'          => [],
            'if_not_modified_since' => null,
            'retries'               => 2,
            'max_filesize'          => null,
            'max_redirections'      => 5,
            // Per-request SSRF override; null means "use the global config".
            'ssrf_check'            => null,
        ];

        $config = array_merge($defaultConfig, $config);

        // SSRF protection: refuse upfront when the host only points at
        // blocked ranges; every connect is screened again in guardSocket.
        $ssrfCheck = $config['ssrf_check'];
        if ($ssrfCheck === null) {
            $ssrfCheck = self::isSsrfCheckEnabled();
        }
        if ($ssrfCheck === true) {
            $host = self::parseHttpHost($url);
            if ($host !== null && self::isHostAllowed($host) === false) {
                throw new HttpException(sprintf(
                    'Access to %s is blocked: the host resolves to a private or reserved IP range (SSRF protection)',
                    $host
                ));
            }
        }

        // A per-request opt-out (CURLOPT_IMPERSONATE => '') also means "no
        // global profile": unset the env var before curl_init() so the
        // patched library does not apply the profile to this handle, and
        // restore it right before the handle is released (see below).
        $optOutRequested = self::isOptOut($config['curl_options']);
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

        // Also disable the profile directly on the handle: an empty
        // CURLOPT_IMPERSONATE resets it to a vanilla curl configuration,
        // which keeps opted-out requests vanilla after curl_reset() too.
        if ($optOutRequested === true) {
            $config['curl_options'][self::impersonateOption()] = '';
        }

        // Wall-clock budget for the whole attempt loop. Without it a single
        // hanging upstream (black-holed TCP connect, stalled TLS handshake)
        // blocks an fpm worker for timeout*(retries+1)+backoff seconds and
        // starves the worker pool, making unrelated feeds time out as well.
        $deadline = microtime(true) + ((int)$config['timeout'] * (1 + (int)$config['retries'])) * 1.35;

        $httpHeaders = [];
        foreach ($config['headers'] as $name => $value) {
            $httpHeaders[] = sprintf('%s: %s', $name, $value);
        }

        // With an active impersonate profile the patched library already
        // applies the browser fingerprint and default headers; libcurl
        // merges bridge headers over them one-by-one, so no filtering is
        // needed here. Only apply the configured deployment User-Agent
        // when no profile is in effect (plain libcurl) and it is non-empty.
        $impersonate = self::getImpersonateTarget();

        $curlOptions = [
            CURLOPT_HEADER          => false,
            CURLOPT_HTTPHEADER      => $httpHeaders,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => $config['max_redirections'],
            CURLOPT_TIMEOUT         => $config['timeout'],
            CURLOPT_CONNECTTIMEOUT  => $config['connect_timeout'] ?? min(10, (int)($config['timeout'] / 2)),
            CURLOPT_NOSIGNAL        => true,
            CURLOPT_ENCODING        => '',
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];

        // User-Agent handling: CURLOPT_USERAGENT is a *global* override that
        // would replace the profile UA on every single request, which is not
        // a bridge-level block but deployment config. When an impersonate
        // profile is active we leave it to curl-impersonate entirely; bridges
        // that need their own UA set the 'User-Agent' header explicitly —
        // libcurl merges that over the profile default one-by-one without
        // touching the rest of the fingerprint. Only when no profile is in
        // effect (plain libcurl, dev/tests) do we apply the configured UA,
        // and only if one was actually configured — never send an empty or
        // made-up User-Agent.
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

        // DNS-rebinding protection: screen the exact IP address libcurl is
        // about to connect to (including every redirect hop) in a connect
        // callback. Returning null lets libcurl create the socket itself;
        // blocked addresses abort the connection. Bridges may still set
        // their own CURLOPT_RESOLVE entries via curl_options above.
        if ($ssrfCheck === true) {
            $curlOptions[self::openSocketOption()] = [self::class, 'guardSocket'];
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
        // The URL currently being executed; moves along the Location chain
        // when redirects are followed manually (SSRF re-screening).
        $currentUrl = $url;
        $redirectsLeft = (int)$config['max_redirections'];

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            // Never start an attempt we cannot finish within the budget.
            if ($attempt > 1 && (microtime(true) + $config['timeout']) > $deadline) {
                break;
            }

            // Reset per-attempt state so header parsing of a retried or
            // redirected exchange starts from scratch.
            $responseHeaders = [];
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, $headerCallback);

            while (true) {
                $body = curl_exec($ch);
                if ($body === false) {
                    break;
                }
                $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

                // Manual redirect handling with SSRF re-screening: every hop
                // is verified and its IP pinned before libcurl connects.
                if (
                    $manualRedirects === true
                    && $statusCode >= 300 && $statusCode < 400
                    && in_array($statusCode, [304, 305], true) === false
                ) {
                    $location = $responseHeaders['location'][count($responseHeaders['location']) - 1] ?? null;
                    if ($location === null || $redirectsLeft <= 0) {
                        break; // No Location header or the hop budget is spent: return the 3xx as-is.
                    }
                    $target = self::resolveRedirectLocation($location, $currentUrl);
                    if ($target === null) {
                        throw new HttpException(sprintf(
                            'Refusing to follow redirect to a non-http(s) target for %s (SSRF protection)',
                            $currentUrl
                        ));
                    }
                    $redirectsLeft--;
                    // Resolve + screen the hop, then pin its vetted IP.
                    $this->screenAndPin($target);
                    $pinsOpt = self::resolveOption();
                    if (isset($curlOptions[$pinsOpt]) === true) {
                        $curlOptions[$pinsOpt] = array_values($this->pins);
                    }
                    $responseHeaders = [];
                    $currentUrl = $target;
                    curl_setopt($ch, CURLOPT_URL, $currentUrl);
                    continue;
                }
                break;
            }

            if ($body !== false) {
                break;
            }
            $lastError = curl_error($ch);
            $lastErrno = curl_errno($ch);
            // Do not retry on errors that will certainly repeat: TLS/config
            // problems, malformed URLs, unresolvable hosts and operation
            // timeouts (a dead upstream does not recover in 500ms — retrying
            // only multiplies the time the fpm worker is blocked).
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
                // Exponential backoff with jitter, capped by the deadline.
                $delayUs = min(
                    (int)((2 ** ($attempt - 1)) * 300_000) + mt_rand(0, 200_000),
                    max(0, (int)(($deadline - microtime(true)) * 1_000_000))
                );
                if ($delayUs <= 0) {
                    break;
                }
                usleep($delayUs);

                curl_reset($ch);
                curl_setopt($ch, CURLOPT_URL, $currentUrl);
                if (curl_setopt_array($ch, $curlOptions) === false) {
                    throw new HttpException('Failed to set cURL options: tried to set an illegal curl option');
                }
                curl_setopt($ch, CURLOPT_HEADERFUNCTION, $headerCallback);
            }
        }

        $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        // Restore the global profile env after an opted-out request so the
        // next request in this worker impersonates again (see curl_init()).
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

// backward compatibility
class_alias(\RSSBridge\Http\HttpException::class, 'HttpException');
class_alias(\RSSBridge\Http\CloudFlareException::class, 'CloudFlareException');
class_alias(\RSSBridge\Http\HttpClient::class, 'HttpClient');
class_alias(\RSSBridge\Http\IpResolver::class, 'IpResolver');
class_alias(\RSSBridge\Http\CurlHttpClient::class, 'CurlHttpClient');
class_alias(\RSSBridge\Http\Request::class, 'Request');
class_alias(\RSSBridge\Http\Response::class, 'Response');
