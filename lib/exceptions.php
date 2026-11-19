<?php

/**
 * Exceptions and helper throw functions for RSSBridge.
 *
 * All exception classes and helper functions live in the global namespace
 * for simplicity and ease of use across bridges and core code.
 *
 * This file contains multiple classes and functions by design
 * (single-module approach). PSR-1 rules are intentionally disabled.
 *
 * @phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses
 * @phpcs:disable Generic.Files.OneClassPerFile.MultipleFound
 * @phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols
 */

declare(strict_types=1);

use RSSBridge\Http\Response;

/**
 * Base class for HTTP-related exceptions.
 *
 * Carries the upstream status code, the upstream Response (when one exists)
 * and a Retry-After hint. The hint is a SERVER-SIDE backoff input only: it is
 * consumed by DisplayAction to arm the CacheMiddleware backoff marker and is
 * never forwarded to the feed reader as a Retry-After header.
 */
class HttpException extends \Exception
{
    public ?Response $response;

    /**
     * Delay hint parsed from the upstream Retry-After header (seconds).
     * Used exclusively to size the server-side backoff window.
     */
    public ?int $retryAfter = null;

    public function __construct(string $message = '', int $statusCode = 0, ?Response $response = null)
    {
        parent::__construct($message, $statusCode);
        $this->response = $response ?? new Response('', 0);
        $this->retryAfter = self::parseRetryAfter($this->response->getHeader('retry-after'));
    }

    /**
     * Parses a Retry-After header value per RFC 9110 section 10.2.3.
     *
     * Accepts either delta-seconds ("120") or an HTTP-date, and clamps the
     * result to [1, 86400]. Anything unusable yields null so the caller picks
     * its own backoff floor instead of honouring a bogus upstream value.
     * Past or "now" dates mean "retry immediately", which would defeat the
     * backoff and hammer the upstream, so they are rejected as well. A leading
     * minus is rejected up front because relative-date spellings such as
     * "-2 days ago" could otherwise resolve to a future timestamp.
     */
    public static function parseRetryAfter(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if ($value[0] === '-') {
            return null;
        }
        if (ctype_digit($value) === true) {
            $seconds = (int) $value;
            return $seconds > 0 ? min(86400, $seconds) : null;
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }
        $delay = $timestamp - time();
        if ($delay < 1) {
            return null;
        }
        return min(86400, $delay);
    }

    /**
     * Creates an HttpException from an HTTP response.
     * Automatically detects Cloudflare protection and returns CloudFlareException.
     */
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

/**
 * Thrown when Cloudflare protection is detected.
 *
 * Always mapped to a per-feed 502 by httpStatusFromException(), regardless of
 * the status Cloudflare itself returned (403, 503, ...), so a challenge page
 * never turns into a domain-wide cooldown in the reader.
 */
final class CloudFlareException extends HttpException
{
    /**
     * Checks if the response indicates Cloudflare protection.
     *
     * The title list is a heuristic and includes one generic "Access denied"
     * page; a false positive only changes the exception class, not the
     * resulting 502, so it is safe to keep the broad match.
     */
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

/**
 * Mostly thrown by bridges to indicate user failure (4xx errors).
 * Will only be logged as debug log record.
 */
class ClientException extends HttpException
{
    public function __construct(string $message = '', int $statusCode = 400, ?Response $response = null)
    {
        parent::__construct($message, $statusCode, $response);
    }
}

/**
 * Thrown when server-side failure occurs (5xx errors).
 */
class ServerException extends HttpException
{
    public function __construct(string $message = '', int $statusCode = 500, ?Response $response = null)
    {
        parent::__construct($message, $statusCode, $response);
    }
}

/**
 * Thrown when the upstream rate limit is exceeded (upstream HTTP 429).
 *
 * The 429 status describes the UPSTREAM, not this instance: it is translated
 * to a per-feed 502 before anything is sent to the reader. retryAfter only
 * sizes the server-side backoff window.
 */
class RateLimitException extends ClientException
{
    public function __construct(string $message = '', int $retryAfter = 3600, ?Response $response = null)
    {
        parent::__construct($message, 429, $response);
        $this->retryAfter = max(0, min(86400, $retryAfter));
    }
}

/**
 * Helper function to throw ClientException.
 */
function throwClientException(string $message = '', int $code = 400): never
{
    throw new ClientException($message, $code);
}

/**
 * Helper function to throw ServerException.
 */
function throwServerException(string $message = '', int $code = 500): never
{
    throw new ServerException($message, $code);
}

/**
 * Helper function to throw RateLimitException.
 */
function throwRateLimitException(string $message = '', int $retryAfter = 3600): never
{
    throw new RateLimitException($message, $retryAfter);
}

/**
 * Thrown when the service (or an upstream dependency) is temporarily
 * unavailable and the request may succeed again later.
 *
 * The 503 code is internal bookkeeping only. httpStatusFromException() maps
 * it to a per-feed 502, because FreshRSS turns a real 503 into a domain-wide
 * Retry-After cooldown that would freeze every feed on this instance.
 * retryAfter sizes the server-side backoff window; it is never emitted as a
 * header. Whole-instance unavailability is signalled by MaintenanceMiddleware
 * instead, which is the only component allowed to answer 503.
 */
class ServiceUnavailableException extends ServerException
{
    public function __construct(string $message = '', int $retryAfter = 600, ?Response $response = null)
    {
        parent::__construct($message, 503, $response);
        $this->retryAfter = max(0, min(86400, $retryAfter));
    }
}

/**
 * Helper function to throw ServiceUnavailableException.
 */
function throwServiceUnavailableException(string $message = '', int $retryAfter = 600): never
{
    throw new ServiceUnavailableException($message, $retryAfter);
}

/**
 * Maps a bridge/upstream exception onto a per-feed response status.
 *
 * Per-feed failures NEVER use 429/503 and NEVER carry Retry-After: FreshRSS
 * applies a domain-wide cooldown to those codes, which would freeze every
 * feed served by this instance because of one broken feed. Upstream
 * politeness is enforced server-side by the backoff marker instead, which is
 * why the header array is always empty and kept only for API compatibility.
 *
 *  - rate limiting / Cloudflare / temporary unavailability -> 502
 *  - upstream 429                                          -> 502
 *  - explicit 408/504, request-deadline exhaustion,
 *    libcurl CURLE_OPERATION_TIMEDOUT (28)                 -> 504
 *  - other upstream 5xx and transport failures             -> 502
 *  - libcurl local/configuration errors (1, 2, 3, 4)       -> 500
 *  - upstream 4xx (except 429)                             -> passthrough
 *  - anything unexpected / local config error (code 0)     -> 500
 *
 * An explicit HTTP status always wins; the message-based libcurl heuristic
 * is only consulted for code 0, which is what CurlHttpClient throws for
 * transport failures. Classifying by errno instead of by the bare presence
 * of "cURL error" keeps DNS, TLS and malformed-URL failures from being
 * reported as gateway timeouts.
 *
 * @return array{0: int, 1: array<string, string>} Status code + headers
 */
function httpStatusFromException(\Throwable $e): array
{
    if ($e instanceof \RateLimitException) {
        return [502, []];
    }

    if ($e instanceof \CloudFlareException) {
        return [502, []];
    }

    if ($e instanceof \ServiceUnavailableException) {
        return [502, []];
    }

    if ($e instanceof \HttpException) {
        $code = $e->getCode();

        if ($code === 429) {
            return [502, []];
        }

        if ($code === 408 || $code === 504) {
            return [504, []];
        }

        if ($code >= 400 && $code <= 499) {
            return [$code, []];
        }

        if ($code >= 500 && $code <= 599) {
            return [502, []];
        }

        $message = $e->getMessage();

        if (str_contains($message, 'no time left within the request deadline') === true) {
            return [504, []];
        }

        if (preg_match('/cURL error (\d+)/', $message, $matches) === 1) {
            $errno = (int) $matches[1];
            if ($errno === 28) {
                return [504, []];
            }
            if (in_array($errno, [1, 2, 3, 4], true) === true) {
                return [500, []];
            }
            return [502, []];
        }

        if (str_contains($message, 'cURL error') === true) {
            return [502, []];
        }

        return [500, []];
    }

    return [500, []];
}
