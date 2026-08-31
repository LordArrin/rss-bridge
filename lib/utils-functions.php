<?php

declare(strict_types=1);

/**
 * Global function wrappers for RSSBridge utilities.
 *
 * These functions provide backward compatibility for legacy code
 * that expects global functions instead of static class methods.
 */

function get_home_page_url(): string
{
    return \RSSBridge\Utils\Url::getHomePageUrl();
}

function get_current_url(): string
{
    return \RSSBridge\Utils\Url::getCurrentUrl();
}

function create_sane_exception_message(\Throwable $e): string
{
    return \RSSBridge\Utils\Url::createSaneExceptionMessage($e);
}

function render_github_url(string $file, int $line, string $revision = 'master'): string
{
    return \RSSBridge\Utils\Url::renderGithubUrl($file, $line, $revision);
}

function trace_from_exception(\Throwable $e): array
{
    return \RSSBridge\Utils\Url::traceFromException($e);
}

function trace_to_call_points(array $trace): array
{
    return \RSSBridge\Utils\Url::traceToCallPoints($trace);
}

function frame_to_call_point(array $frame): string
{
    return \RSSBridge\Utils\Url::frameToCallPoint($frame);
}

function sanitize_root(string $filePath): string
{
    return \RSSBridge\Utils\Url::sanitizeRoot($filePath);
}

function _sanitize_path_name(string $s, string $pathName): string
{
    return \RSSBridge\Utils\Url::sanitizePathName($s, $pathName);
}

function is_html(string $text): bool
{
    return \RSSBridge\Utils\Url::isHtml($text);
}

function parse_mime_type(string $url): string
{
    return \RSSBridge\Utils\Url::parseMimeType($url);
}

function format_bytes(int $bytes, int $precision = 2): string
{
    return \RSSBridge\Utils\Url::formatBytes($bytes, $precision);
}

function now(): \DateTimeImmutable
{
    return \RSSBridge\Utils\Url::now();
}

function create_random_string(int $bytes = 16): string
{
    return \RSSBridge\Utils\Url::createRandomString($bytes);
}
