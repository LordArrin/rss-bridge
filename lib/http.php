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
     * Fallback User-Agent used when none is configured and no
     * impersonation profile is active.
     */
    public const DEFAULT_USERAGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36';

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
     * Consequence: if application code then calls CURLOPT_USERAGENT or
     * overrides Accept/Accept-Language via CURLOPT_HTTPHEADER, it UNDOES
     * part of the impersonation and the fingerprint becomes inconsistent
     * (Chrome TLS/H2 fingerprint + mismatched UA/client-hints), which
     * Cloudflare-class WAFs often answer by stalling the connection —
     * exactly the "cURL error 28 ... 0 bytes received" symptom.
     *
     * When a profile is active we therefore leave those options untouched.
     */
    public static function getImpersonateTarget(): ?string
    {
        $target = getenv('CURL_IMPERSONATE');
        if (is_string($target) === true && $target !== '') {
            return $target;
        }
        return null;
    }

    public function request(string $url, array $config = []): Response
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new HttpException('Failed to initialize cURL');
        }

        $defaultConfig = [
            'useragent'             => self::DEFAULT_USERAGENT,
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

        // curl-impersonate (see getImpersonateTarget()): when the library is
        // the impersonate build and CURL_IMPERSONATE is set, every handle
        // created by curl_init()/curl_reset() already carries the full Chrome
        // fingerprint plus a "base header" list (User-Agent, sec-ch-ua,
        // Accept, Sec-Fetch-*, Accept-Encoding, Accept-Language). libcurl's
        // Curl_http_merge_headers() lets app headers override those one by
        // one, so overriding only *some* of them (e.g. forcing HTTP/1.1 or a
        // custom UA) produces an inconsistent fingerprint that modern WAFs
        // answer by stalling the connection -> "cURL error 28 ... 0 bytes".
        $impersonate = self::getImpersonateTarget();
        if ($impersonate !== null) {
            // Keep the profile's own Accept/Accept-Language client hints.
            $browserHeaders = ['accept', 'accept-language'];
            $httpHeaders = array_values(array_filter(
                $httpHeaders,
                fn(string $h): bool => !in_array(
                    strtolower(trim(explode(':', $h, 2)[0])),
                    $browserHeaders,
                    true
                )
            ));

            // Never let a bridge downgrade HTTP/2 back to 1.x while keeping
            // the Chrome TLS/H2 settings — unless it explicitly opts out via
            // the impersonate extension option CURLOPT_IMPERSONATE(0).
            $disableOpt = defined('CURLOPT_IMPERSONATE') === true ? (int)constant('CURLOPT_IMPERSONATE') : -1;
            $keepHttpVersion = !array_key_exists($disableOpt, $config['curl_options']);
            if ($keepHttpVersion === true) {
                unset($config['curl_options'][CURLOPT_HTTP_VERSION]);
            }
        }

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

        // User-Agent handling:
        // - With an active curl-impersonate profile and no explicit UA in the
        //   config, send nothing: Curl_http_merge_headers() substitutes the
        //   profile's own Chrome UA, keeping it consistent with sec-ch-ua
        //   client hints and the TLS/H2 fingerprint. A mismatched pair
        //   (Chrome fingerprint + "curl/8.x" or stale custom UA) is a common
        //   trigger for WAF black-holing -> cURL error 28, 0 bytes received.
        // - On plain libcurl (dev/tests), fall back to the configured UA or
        //   DEFAULT_USERAGENT; never send an empty "User-Agent:" header.
        if ($config['useragent'] !== null && $config['useragent'] !== '') {
            $curlOptions[CURLOPT_USERAGENT] = $config['useragent'];
        } elseif ($impersonate === null) {
            $curlOptions[CURLOPT_USERAGENT] = self::DEFAULT_USERAGENT;
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
