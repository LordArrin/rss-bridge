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
 * Contains status code, optional Response object, and Retry-After hint.
 */
class HttpException extends \Exception
{
    public ?Response $response;

    /**
     * Delay hint parsed from the upstream Retry-After header (seconds).
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
     */
    public static function parseRetryAfter(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (ctype_digit($value) === true) {
            return max(0, min(86400, (int) $value));
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }
        return max(0, min(86400, $timestamp - time()));
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
 */
final class CloudFlareException extends HttpException
{
    /**
     * Checks if the response indicates Cloudflare protection.
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
 * Thrown when rate limit is exceeded (HTTP 429).
 */
class RateLimitException extends ClientException
{
    /**
     * @param int|null $retryAfter Delay hint in seconds. Null means "derive
     * it from the effective cache timeout" (1/10 of it, see
     * defaultRetryAfterSeconds()); an explicit upstream Retry-After hint
     * parsed from the response always takes precedence over that default.
     */
    public function __construct(string $message = '', ?int $retryAfter = null, ?Response $response = null)
    {
        parent::__construct($message, 429, $response);
        // An explicit upstream Retry-After header wins over the caller's
        // value and over the derived default.
        if ($this->retryAfter === null) {
            $this->retryAfter = $retryAfter;
        }
    }

    /**
     * Resolves the final retry delay once the effective cache timeout of
     * the request is known (bridge CACHE_TIMEOUT or ?_cache_timeout).
     */
    public function resolveRetryAfter(?int $effectiveCacheTimeout = null): void
    {
        if (($this->response->getHeader('retry-after')) === null && $this->retryAfter === null) {
            $this->retryAfter = defaultRetryAfterSeconds($effectiveCacheTimeout);
        }
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
function throwRateLimitException(string $message = '', ?int $retryAfter = null): never
{
    throw new RateLimitException($message, $retryAfter);
}

/**
 * Thrown when the service (or an upstream dependency) is temporarily
 * unavailable and the request may succeed again later (HTTP 503).
 *
 * FreshRSS treats 503 as a temporary error and retries the feed on the
 * next polling cycle, so bridges and core code should prefer this over
 * generic 5xx errors for transient failures.
 */
class ServiceUnavailableException extends ServerException
{
    /**
     * @param int|null $retryAfter Delay hint in seconds. Null means "derive
     * it from the effective cache timeout" (1/10 of it, see
     * defaultRetryAfterSeconds()); an explicit upstream Retry-After hint
     * parsed from the response always takes precedence over that default.
     */
    public function __construct(string $message = '', ?int $retryAfter = null, ?Response $response = null)
    {
        parent::__construct($message, 503, $response);
        if ($this->retryAfter === null && $retryAfter !== null) {
            $this->retryAfter = max(0, min(86400, $retryAfter));
        }
    }

    /**
     * Resolves the final retry delay once the effective cache timeout of
     * the request is known (bridge CACHE_TIMEOUT or ?_cache_timeout).
     */
    public function resolveRetryAfter(?int $effectiveCacheTimeout = null): void
    {
        if (($this->response->getHeader('retry-after')) === null && $this->retryAfter === null) {
            $this->retryAfter = defaultRetryAfterSeconds($effectiveCacheTimeout);
        }
    }
}

/**
 * Helper function to throw ServiceUnavailableException.
 */
function throwServiceUnavailableException(string $message = '', ?int $retryAfter = null): never
{
    throw new ServiceUnavailableException($message, $retryAfter);
}

/**
 * Default Retry-After delay (seconds) for transient failures.
 *
 * Computed as one tenth of the effective cache timeout so that feed
 * readers such as FreshRSS retry shortly after the bridge's own cached
 * entry would have expired anyway: the reader comes back exactly when
 * the bridge is likely to have fresh data again. Falls back to the
 * default bridge CACHE_TIMEOUT when no per-request value is available.
 */
function defaultRetryAfterSeconds(?int $effectiveCacheTimeout = null): int
{
    $ttl = $effectiveCacheTimeout;
    if ($ttl === null || $ttl <= 0) {
        $ttl = \BridgeAbstract::CACHE_TIMEOUT;
    }
    return max(1, min(86400, intdiv($ttl, 10)));
}

/**
 * Maps an exception thrown by a bridge (or anywhere downstream) onto a
 * response status code that is meaningful for feed readers such as
 * FreshRSS:
 *
 *  - HTTP 429 Too Many Requests with Retry-After for rate limiting
 *  - HTTP 503 Service Unavailable with Retry-After for temporary problems
 *    (Cloudflare challenges, upstream timeouts, cURL timeouts)
 *  - HTTP 502 Bad Gateway with Retry-After when the upstream itself
 *    answered 502 (upstream 504 is reported as a timeout, see above):
 *    it keeps the 5xx class FreshRSS treats as a retryable error, while
 *    making clear the failure came from upstream rather than from this
 *    bridge instance
 *  - The original status code for other HttpExceptions
 *  - HTTP 500 for everything unexpected
 *
 * Unless an explicit delay was given (by the bridge or parsed from an
 * upstream Retry-After header), the Retry-After hint defaults to one
 * tenth of $effectiveCacheTimeout (the bridge's CACHE_TIMEOUT or the
 * resolved ?_cache_timeout), so readers retry right around the time the
 * cached entry would have expired anyway.
 *
 * Transient failures must never be reported as permanent errors (and in
 * particular never as 304 Not Modified), because FreshRSS would mark the
 * feed as broken instead of retrying it later.
 *
 * @return array{0: int, 1: array<string,string>} Status code + headers
 */
function httpStatusFromException(\Throwable $e, ?int $effectiveCacheTimeout = null): array
{
    // When the effective cache timeout is known, let transient exceptions
    // derive their default Retry-After from it (1/10 of the TTL).
    if ($effectiveCacheTimeout !== null) {
        if ($e instanceof \RateLimitException || $e instanceof \ServiceUnavailableException) {
            $e->resolveRetryAfter($effectiveCacheTimeout);
        }
    }

    if ($e instanceof \RateLimitException) {
        $retryAfter = $e->retryAfter ?? defaultRetryAfterSeconds($effectiveCacheTimeout);
        return [429, ['retry-after' => (string) max(1, min(86400, $retryAfter))]];
    }

    if ($e instanceof \CloudFlareException) {
        // Cloudflare challenges are temporary: ask the reader to come back later.
        $retryAfter = $e->retryAfter ?? defaultRetryAfterSeconds($effectiveCacheTimeout);
        return [503, ['retry-after' => (string) max(1, min(86400, $retryAfter))]];
    }

    if ($e instanceof \ServiceUnavailableException) {
        $retryAfter = $e->retryAfter ?? defaultRetryAfterSeconds($effectiveCacheTimeout);
        return [503, ['retry-after' => (string) max(1, min(86400, $retryAfter))]];
    }

    if ($e instanceof \HttpException) {
        $code = (int) $e->getCode();
        $isTimeout = $code === 0 || $code === 408 || $code === 504
            || str_contains($e->getMessage(), 'cURL error') === true;

        if ($isTimeout === true) {
            // Upstream timeout: a temporary problem, not a permanent failure.
            $retryAfter = $e->retryAfter ?? defaultRetryAfterSeconds($effectiveCacheTimeout);
            return [503, ['retry-after' => (string) max(1, min(86400, $retryAfter))]];
        }

        if (in_array($code, [502], true) === true) {
            // The upstream itself failed. Keep the retryable 5xx class but
            // report it as Bad Gateway with Retry-After, so FreshRSS backs
            // off instead of treating it as a permanent 500.
            $retryAfter = $e->retryAfter ?? defaultRetryAfterSeconds($effectiveCacheTimeout);
            return [502, ['retry-after' => (string) max(1, min(86400, $retryAfter))]];
        }

        if (($code >= 400 && $code <= 599) === true) {
            $headers = [];
            if ($e->retryAfter !== null && in_array($code, [429, 503], true) === true) {
                $headers['retry-after'] = (string) max(1, min(86400, $e->retryAfter));
            }
            return [$code, $headers];
        }

        return [500, []];
    }

    return [500, []];
}
