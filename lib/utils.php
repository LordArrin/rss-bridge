<?php

declare(strict_types=1);

namespace RSSBridge\Utils;

final class Url
{
    private const MIME_TYPES = [
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

    public static function getHomePageUrl(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        if (($pos = strpos($uri, '?')) !== false) {
            $uri = substr($uri, 0, $pos);
        }
        return self::getBaseUrl() . $uri;
    }

    public static function getCurrentUrl(): string
    {
        return self::getBaseUrl() . ($_SERVER['REQUEST_URI'] ?? '/');
    }

    private static function getBaseUrl(): string
    {
        $https = $_SERVER['HTTPS'] ?? '';
        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        if ($proto !== '') {
            $https = ($proto === 'https') ? 'on' : '';
        }
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $scheme = ($https === 'on') ? 'https' : 'http';
        return sprintf('%s://%s', $scheme, $host);
    }

    public static function createSaneExceptionMessage(\Throwable $e): string
    {
        return sprintf(
            '%s: %s in %s line %s',
            get_class($e),
            self::sanitizeRoot($e->getMessage()),
            self::sanitizeRoot($e->getFile()),
            $e->getLine()
        );
    }

    public static function renderGithubUrl(string $file, int $line, string $revision = 'master'): string
    {
        return sprintf(
            'https://github.com/LordArrin/rss-bridge/blob/%s/%s#L%d',
            $revision,
            $file,
            $line
        );
    }

    public static function traceFromException(\Throwable $e): array
    {
        $frames = array_reverse($e->getTrace());
        $frames[] = [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
        $trace = [];
        foreach ($frames as $frame) {
            $trace[] = [
                'file'     => self::sanitizeRoot($frame['file'] ?? ''),
                'line'     => $frame['line'] ?? null,
                'class'    => $frame['class'] ?? null,
                'type'     => $frame['type'] ?? null,
                'function' => $frame['function'] ?? null,
            ];
        }
        return $trace;
    }

    public static function traceToCallPoints(array $trace): array
    {
        return array_map(fn(array $frame) => self::frameToCallPoint($frame), $trace);
    }

    public static function frameToCallPoint(array $frame): string
    {
        if (empty($frame['class']) === false) {
            return sprintf(
                '%s(%s): %s%s%s()',
                $frame['file'],
                $frame['line'],
                $frame['class'],
                $frame['type'],
                $frame['function'],
            );
        }
        if (empty($frame['function']) === false) {
            return sprintf(
                '%s(%s): %s()',
                $frame['file'],
                $frame['line'],
                $frame['function'],
            );
        }
        return sprintf('%s(%s)', $frame['file'], $frame['line']);
    }

    public static function sanitizeRoot(string $filePath): string
    {
        $root = dirname(__DIR__);
        return self::sanitizePathName($filePath, $root);
    }

    private static function sanitizePathName(string $s, string $pathName): string
    {
        return str_replace([$pathName . '/', $pathName], '', $s);
    }

    public static function isHtml(string $text): bool
    {
        return strlen(strip_tags($text)) !== strlen($text);
    }

    public static function parseMimeType(string $url): string
    {
        $mime = self::MIME_TYPES;

        $openBasedir = ini_get('open_basedir');
        if (empty($openBasedir) === true && is_readable('/etc/mime.types') === true) {
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

        $cleanUrl = $url;
        if (($qpos = strpos($cleanUrl, '?')) !== false) {
            $cleanUrl = substr($cleanUrl, 0, $qpos);
        }
        if (($hpos = strpos($cleanUrl, '#')) !== false) {
            $cleanUrl = substr($cleanUrl, 0, $hpos);
        }

        $ext = strtolower(pathinfo($cleanUrl, PATHINFO_EXTENSION));
        if ($ext !== '' && isset($mime[$ext]) === true) {
            return $mime[$ext];
        }

        return 'application/octet-stream';
    }

    public static function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = $bytes > 0 ? (int)floor(log($bytes, 1024)) : 0;
        $pow = min($pow, count($units) - 1);
        $bytes /= 1024 ** $pow;
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }

    public static function createRandomString(int $bytes = 16): string
    {
        return bin2hex(random_bytes($bytes));
    }
}
