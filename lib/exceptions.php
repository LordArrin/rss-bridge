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
    public function __construct(string $message = '', int $retryAfter = 3600, ?Response $response = null)
    {
        parent::__construct($message, 429, $response);
        $this->retryAfter = $retryAfter;
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
 * unavailable and the request may succeed again later (HTTP 503).
 *
 * FreshRSS treats 503 as a temporary error and retries the feed on the
 * next polling cycle, so bridges and core code should prefer this over
 * generic 5xx errors for transient failures.
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
 * Maps an exception thrown by a bridge (or anywhere downstream) onto a
 * response status code that is meaningful for feed readers such as
 * FreshRSS:
 *
 *  - HTTP 429 Too Many Requests with Retry-After for rate limiting
 *  - HTTP 503 Service Unavailable with Retry-After for temporary problems
 *    (Cloudflare challenges, upstream timeouts, cURL timeouts)
 *  - The original status code for other HttpExceptions
 *  - HTTP 500 for everything unexpected
 *
 * Transient failures must never be reported as permanent errors (and in
 * particular never as 304 Not Modified), because FreshRSS would mark the
 * feed as broken instead of retrying it later.
 *
 * @return array{0: int, 1: array<string,string>} Status code + headers
 */
function httpStatusFromException(\Throwable $e): array
{
    if ($e instanceof \RateLimitException) {
        $retryAfter = $e->retryAfter ?? 3600;
        return [429, ['retry-after' => (string) max(1, min(86400, (int) $retryAfter))]];
    }

    if ($e instanceof \CloudFlareException) {
        // Cloudflare challenges are temporary: ask the reader to come back later.
        return [503, ['retry-after' => '600']];
    }

    if ($e instanceof \ServiceUnavailableException) {
        $retryAfter = $e->retryAfter ?? 600;
        return [503, ['retry-after' => (string) max(1, min(86400, (int) $retryAfter))]];
    }

    if ($e instanceof \HttpException) {
        $code = (int) $e->getCode();
        $isTimeout = $code === 0 || $code === 408 || $code === 504
            || str_contains($e->getMessage(), 'cURL error') === true;

        if ($isTimeout === true) {
            // Upstream timeout: a temporary problem, not a permanent failure.
            return [503, ['retry-after' => '600']];
        }

        if (($code >= 400 && $code <= 599) === true) {
            $headers = [];
            if ($e->retryAfter !== null && in_array($code, [429, 503], true) === true) {
                $headers['retry-after'] = (string) max(1, min(86400, (int) $e->retryAfter));
            }
            return [$code, $headers];
        }

        return [500, []];
    }

    return [500, []];
}
