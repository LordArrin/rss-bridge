<?php

declare(strict_types=1);

/**
 * Mostly thrown by bridges to indicate user failure.
 * Will only be logged as debug log record.
 */
final class ClientException extends \Exception
{
}

/**
 * Thrown when rate limit is exceeded.
 */
final class RateLimitException extends \Exception
{
}

function throwClientException(string $message = ''): never
{
    throw new ClientException($message, 400);
}

function throwServerException(string $message = ''): never
{
    throw new \Exception($message, 500);
}

function throwRateLimitException(string $message = ''): never
{
    throw new RateLimitException($message);
}

/**
 * @deprecated Use throwClientException() instead.
 */
function returnClientError(string $message = ''): never
{
    trigger_error(
        'returnClientError() is deprecated, use throwClientException() instead',
        E_USER_DEPRECATED
    );
    throwClientException($message);
}

/**
 * @deprecated Use throwServerException() instead.
 */
function returnServerError(string $message = ''): never
{
    trigger_error(
        'returnServerError() is deprecated, use throwServerException() instead',
        E_USER_DEPRECATED
    );
    throwServerException($message);
}