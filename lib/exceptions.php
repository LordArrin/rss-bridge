<?php

/**
 * Exceptions and helper throw functions for RSSBridge.
 *
 * This file contains multiple classes and functions by design
 * (single-module approach). PSR-1 single-class-per-file rule is
 * intentionally disabled for this file.
 *
 * @phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses
 * @phpcs:disable Generic.Files.OneClassPerFile.MultipleFound
 */

declare(strict_types=1);

namespace RSSBridge\Exceptions;

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
