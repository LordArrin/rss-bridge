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

final class CurlHttpClient implements HttpClient
{
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
     * Detects whether the bridge explicitly opted out of impersonation for
     * this request by passing CURLOPT_IMPERSONATE => '' (or 'none'), which
     * the patched library honours at execution time (curl_easy_setopt with
     * an empty target resets the handle to a vanilla curl configuration).
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

    public function request(string $url, array $config = []): Response
    {
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

        $ch = curl_init($url);
        if ($ch === false) {
            if ($savedEnv !== null) {
                putenv('CURL_IMPERSONATE=' . $savedEnv);
            }
            throw new HttpException('Failed to initialize cURL');
        }

        // With an active global profile the patched library applies the
        // browser fingerprint at handle-creation time (curl_easy_impersonate
        // from curl_easy_init). On retry paths below we call curl_reset(),
        // which re-reads CURL_IMPERSONATE; keep it unset for opted-out
        // requests so retries stay opt-out as well. The env var is restored
        // again right before the handle is released (see below).
        if ($optOutRequested === true) {
            // Belt and braces: also disable the profile directly on this
            // handle. Per lexiforest/curl-impersonate, setting
            // CURLOPT_IMPERSONATE to "" resets the handle to a vanilla curl
            // configuration, overriding anything applied via the env var.
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
            'max_redirections'      => 5,
        ];

        $config = array_merge($defaultConfig, $config);

        // Wall-clock budget for the whole attempt loop. Without it a single
        // hanging upstream (black-holed TCP connect, stalled TLS handshake)
        // blocks an fpm worker for timeout*(retries+1)+backoff seconds and
        // starves the worker pool, making unrelated feeds time out as well.
        $deadline = microtime(true) + ((int)$config['timeout'] * (1 + (int)$config['retries'])) * 1.35;

        $httpHeaders = [];
        foreach ($config['headers'] as $name => $value) {
            $httpHeaders[] = sprintf('%s: %s', $name, $value);
        }

        // curl-impersonate behaviour (see getImpersonateTarget()): when the
        // library is the impersonate build and CURL_IMPERSONATE is set, every
        // handle created by curl_init() already carries the full browser
        // fingerprint plus the profile's default header list. libcurl merges
        // CURLOPT_HTTPHEADER over those defaults one-by-one, so whatever a
        // bridge passes through (a custom User-Agent, an extra Accept, a
        // Referer — e.g. DanbooruBridge or RuStoreBridge) simply replaces the
        // matching profile header while everything else about the spoofed
        // profile stays exactly as curl-impersonate built it. No filtering,
        // no rewriting: anonymization is entirely curl-impersonate's job.
        // A bridge that wants full manual control opts out explicitly with
        // CURLOPT_IMPERSONATE => '' (handled before curl_init above).
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
            // Never start an attempt we cannot finish within the budget.
            if ($attempt > 1 && (microtime(true) + $config['timeout']) > $deadline) {
                break;
            }

            $body = curl_exec($ch);
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
                curl_setopt($ch, CURLOPT_URL, $url);
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

        // Release the connection back to curl's pool instead of hard-closing it.
        if (function_exists('curl_close') === true) {
            /** @phpstan-ignore-next-line deprecated in PHP 8.5, handle is freed on unset */
            curl_close($ch);
        }
        unset($ch);

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
class_alias(\RSSBridge\Http\CurlHttpClient::class, 'CurlHttpClient');
class_alias(\RSSBridge\Http\Request::class, 'Request');
class_alias(\RSSBridge\Http\Response::class, 'Response');
