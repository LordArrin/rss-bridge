<?php

// Based on https://github.com/nette/utils/blob/master/src/Utils/Json.php
final class Json
{
    public static function encode(mixed $value, bool $pretty = true, bool $asciiSafe = false): string
    {
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES;
        if (!$asciiSafe) {
            $flags |= JSON_UNESCAPED_UNICODE;
        }
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        return json_encode($value, $flags);
    }

    public static function decode(string $json, bool $assoc = true): mixed
    {
        return json_decode($json, $assoc, 512, JSON_THROW_ON_ERROR);
    }
}

/**
 * Get the home page URL e.g. 'https://example.com/' or 'https://example.com/bridge/'
 */
function get_home_page_url(): string
{
    $https = $_SERVER['HTTPS'] ?? '';
    // Support reverse-proxy setups (Nginx, Traefik, Caddy, etc.)
    if (($proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') !== '') {
        $https = ($proto === 'https') ? 'on' : '';
    }
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    if (($pos = strpos($uri, '?')) !== false) {
        $uri = substr($uri, 0, $pos);
    }
    $scheme = ($https === 'on') ? 'https' : 'http';
    return "$scheme://$host$uri";
}

/**
 * Get the full current URL e.g. 'http://example.com/?action=display&bridge=FooBridge'
 */
function get_current_url(): string
{
    $https = $_SERVER['HTTPS'] ?? '';
    if (($proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') !== '') {
        $https = ($proto === 'https') ? 'on' : '';
    }
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $scheme = ($https === 'on') ? 'https' : 'http';
    return "$scheme://$host$uri";
}

function create_sane_exception_message(\Throwable $e): string
{
    return sprintf(
        '%s: %s in %s line %s',
        get_class($e),
        sanitize_root($e->getMessage()),
        sanitize_root($e->getFile()),
        $e->getLine()
    );
}

/**
 * Returns e.g. https://github.com/LordArrin/rss-bridge/blob/master/bridges/AO3Bridge.php#L8
 */
function render_github_url(string $file, int $line, string $revision = 'master'): string
{
    return sprintf(
        'https://github.com/LordArrin/rss-bridge/blob/%s/%s#L%d',
        $revision,
        $file,
        $line
    );
}

function trace_from_exception(\Throwable $e): array
{
    $frames = array_reverse($e->getTrace());
    $frames[] = [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ];
    $trace = [];
    foreach ($frames as $frame) {
        $trace[] = [
            'file'     => sanitize_root($frame['file'] ?? ''),
            'line'     => $frame['line'] ?? null,
            'class'    => $frame['class'] ?? null,
            'type'     => $frame['type'] ?? null,
            'function' => $frame['function'] ?? null,
        ];
    }
    return $trace;
}

function trace_to_call_points(array $trace): array
{
    return array_map(fn($frame) => frame_to_call_point($frame), $trace);
}

function frame_to_call_point(array $frame): string
{
    if (!empty($frame['class'])) {
        return sprintf(
            '%s(%s): %s%s%s()',
            $frame['file'],
            $frame['line'],
            $frame['class'],
            $frame['type'],
            $frame['function'],
        );
    }
    if (!empty($frame['function'])) {
        return sprintf(
            '%s(%s): %s()',
            $frame['file'],
            $frame['line'],
            $frame['function'],
        );
    }
    return sprintf('%s(%s)', $frame['file'], $frame['line']);
}

/**
 * Trim path prefix for privacy/security reasons.
 *
 * Example: "/home/user/rss-bridge/index.php" => "index.php"
 */
function sanitize_root(string $filePath): string
{
    $root = dirname(__DIR__);
    return _sanitize_path_name($filePath, $root);
}

function _sanitize_path_name(string $s, string $pathName): string
{
    return str_replace([$pathName . '/', $pathName], '', $s);
}

/**
 * This is buggy because strip_tags() removes a lot that isn't HTML.
 */
function is_html(string $text): bool
{
    return strlen(strip_tags($text)) !== strlen($text);
}

/**
 * Determines the MIME type from a URL/Path file extension.
 *
 * Remarks:
 * - The built-in functions mime_content_type() and fileinfo require fetching remote contents.
 * - A caller can hint for a MIME type by appending #.ext to the URL (i.e. #.image).
 *
 * Based on https://stackoverflow.com/a/1147952
 */
function parse_mime_type(string $url): string
{
    static $mime = null;

    if ($mime === null) {
        // Default values, overridden by /etc/mime.types when present
        $mime = [
            'jpg'   => 'image/jpeg',
            'jpeg'  => 'image/jpeg',
            'gif'   => 'image/gif',
            'png'   => 'image/png',
            'webp'  => 'image/webp',
            'avif'  => 'image/avif',
            'svg'   => 'image/svg+xml',
            'image' => 'image/*',
            'mp3'   => 'audio/mpeg',
            'mp4'   => 'video/mp4',
            'webm'  => 'video/webm',
            'pdf'   => 'application/pdf',
            'json'  => 'application/json',
            'xml'   => 'application/xml',
            'rss'   => 'application/rss+xml',
            'atom'  => 'application/atom+xml',
            'html'  => 'text/html',
            'htm'   => 'text/html',
            'css'   => 'text/css',
            'js'    => 'application/javascript',
            'txt'   => 'text/plain',
        ];

        $openBasedir = ini_get('open_basedir');
        if (!$openBasedir && @is_readable('/etc/mime.types')) {
            $file = fopen('/etc/mime.types', 'r');
            if ($file !== false) {
                while (($line = fgets($file)) !== false) {
                    $line = trim(preg_replace('/#.*/', '', $line));
                    if ($line === '') {
                        continue;
                    }
                    $parts = preg_split('/\s+/', $line);
                    if (count($parts) < 2) {
                        continue;
                    }
                    $type = array_shift($parts);
                    foreach ($parts as $part) {
                        $mime[$part] = $type;
                    }
                }
                fclose($file);
            }
        }
    }

    // Strip query string and fragment
    $cleanUrl = $url;
    if (($qpos = strpos($cleanUrl, '?')) !== false) {
        $cleanUrl = substr($cleanUrl, 0, $qpos);
    }
    if (($hpos = strpos($cleanUrl, '#')) !== false) {
        $cleanUrl = substr($cleanUrl, 0, $hpos);
    }

    $ext = strtolower(pathinfo($cleanUrl, PATHINFO_EXTENSION));
    if ($ext !== '' && isset($mime[$ext])) {
        return $mime[$ext];
    }

    return 'application/octet-stream';
}

/**
 * Format bytes into human-readable string.
 * https://stackoverflow.com/a/2510459
 */
function format_bytes(int $bytes, int $precision = 2): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = $bytes > 0 ? (int)floor(log($bytes, 1024)) : 0;
    $pow = min($pow, count($units) - 1);
    $bytes /= 1024 ** $pow;
    return round($bytes, $precision) . ' ' . $units[$pow];
}

function now(): \DateTimeImmutable
{
    return new \DateTimeImmutable();
}

/**
 * Generate a cryptographically secure random hex string.
 */
function create_random_string(int $bytes = 16): string
{
    return bin2hex(random_bytes($bytes));
}

/**
 * Mostly thrown by bridges to indicate user failure.
 * Will only be logged as debug log record.
 */
final class ClientException extends \Exception
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
