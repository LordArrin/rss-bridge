<?php

/**
 * Exceptions and helper throw functions for RSSBridge.
 *
 * Modern definitions live in the RSSBridge\Exceptions namespace. The
 * global-namespace aliases and throw*() helpers below are a deliberate
 * compatibility layer (facade) kept for the ~150 legacy bridges that
 * reference them unqualified; do not use them in new code.
 *
 * This file contains multiple classes and functions by design
 * (single-module approach). PSR-1 single-class-per-file rule is
 * intentionally disabled for this file.
 *
 * @phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses
 * @phpcs:disable Generic.Files.OneClassPerFile.MultipleFound
 * @phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols
 */

declare(strict_types=1);

namespace RSSBridge\Exceptions {
    /**
     * Mostly thrown by bridges to indicate user failure.
     * Will only be logged as debug log record.
     */
    class ClientException extends \Exception
    {
    }

    /**
     * Thrown when rate limit is exceeded.
     */
    class RateLimitException extends \Exception
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
}

namespace {
    // --- Compatibility layer -------------------------------------------
    // Aliases so legacy code (bridges, actions, middlewares) can keep
    // referencing the exception classes in the global namespace.
    class_alias(\RSSBridge\Exceptions\ClientException::class, 'ClientException');
    class_alias(\RSSBridge\Exceptions\RateLimitException::class, 'RateLimitException');

    // Thin wrappers around the namespaced helpers for legacy callers.
    // They return void instead of "never": a wrapper that calls a
    // never-returning function cannot itself be declared "never", but
    // control still never returns to the caller.
    function throwClientException(string $message = ''): void
    {
        \RSSBridge\Exceptions\throwClientException($message);
    }

    function throwServerException(string $message = ''): void
    {
        \RSSBridge\Exceptions\throwServerException($message);
    }

    function throwRateLimitException(string $message = ''): void
    {
        \RSSBridge\Exceptions\throwRateLimitException($message);
    }
}
