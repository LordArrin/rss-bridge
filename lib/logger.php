<?php

/**
 * Logger module.
 *
 * This file contains multiple classes by design (single-module approach).
 * PSR-1 single-class-per-file rule is intentionally disabled for this file
 * as per project's phpcs.xml configuration.
 *
 * @phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses
 * @phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols
 * @phpcs:disable Generic.Files.OneClassPerFile.MultipleFound
 * @phpcs:disable Generic.Files.OneInterfacePerFile.MultipleFound
 * @phpcs:disable Generic.Files.OneTraitPerFile.MultipleFound
 */

declare(strict_types=1);

namespace RSSBridge\Logger;

use RSSBridge\Utils\Json;
use RSSBridge\Utils\Url;

interface Logger
{
    public const DEBUG = 10;
    public const INFO = 20;
    public const WARNING = 30;
    public const ERROR = 40;

    public const LEVEL_NAMES = [
        self::DEBUG => 'DEBUG',
        self::INFO => 'INFO',
        self::WARNING => 'WARNING',
        self::ERROR => 'ERROR',
    ];

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void;
}

final class LogFilter
{
    /**
     * @param array<string, mixed> $record
     */
    public static function shouldLog(array $record): bool
    {
        $context = $record['context'] ?? null;
        if (is_array($context) === false || isset($context['e']) === false) {
            return true;
        }

        $e = $context['e'];

        if ($e instanceof \Throwable === false) {
            return true;
        }

        if ($e instanceof \RateLimitException === true) {
            return false;
        }

        $ignoredMessages = [
            'Format name invalid',
            'Unknown format given',
            'Unable to find',
        ];

        foreach ($ignoredMessages as $ignoredMessage) {
            if (str_starts_with($e->getMessage(), $ignoredMessage) === true) {
                return false;
            }
        }

        return true;
    }
}

final class LogFormatter
{
    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public static function formatRecord(array $record): array
    {
        $context = $record['context'] ?? [];
        if (is_array($context) === true && isset($context['e']) === true && $context['e'] instanceof \Throwable) {
            /** @var \Throwable $e */
            $e = $context['e'];
            unset($context['e']);

            $context['type'] = $e::class;
            $context['code'] = $e->getCode();
            $context['message'] = Url::sanitizeRoot($e->getMessage());
            $context['file'] = Url::sanitizeRoot($e->getFile());
            $context['line'] = $e->getLine();
            $context['url'] = Url::getCurrentUrl();
            $context['trace'] = Url::traceToCallPoints(Url::traceFromException($e));
            $record['context'] = $context;
        }

        return $record;
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function encodeContext(array $context): string
    {
        if ($context === []) {
            return '';
        }

        try {
            return Json::encode($context);
        } catch (\JsonException) {
            // If JSON encoding fails, try to remove potentially problematic payload
            $context['message'] = null;
            try {
                return Json::encode($context);
            } catch (\JsonException) {
                return '{}';
            }
        }
    }

    /**
     * Narrows a mixed record context into a string-keyed array.
     *
     * @return array<string, mixed>
     */
    public static function normalizeContext(mixed $context): array
    {
        if (is_array($context) === false) {
            return [];
        }

        $normalized = [];
        foreach ($context as $key => $value) {
            if (is_string($key) === true) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * Reads a mixed record field as string (non-scalar values become '').
     */
    public static function stringValue(mixed $value): string
    {
        if (is_string($value) === true) {
            return $value;
        }
        if (is_int($value) === true || is_float($value) === true) {
            return (string) $value;
        }
        if (is_bool($value) === true) {
            return $value === true ? '1' : '';
        }
        return '';
    }

    /**
     * Reads a mixed record field as int.
     */
    public static function intValue(mixed $value, int $default = 0): int
    {
        if (is_int($value) === true) {
            return $value;
        }
        if (is_float($value) === true) {
            return (int) $value;
        }
        if (is_string($value) === true && is_numeric($value) === true) {
            return (int) $value;
        }
        return $default;
    }
}

final class SimpleLogger implements Logger
{
    private string $name;

    /** @var list<callable(array<string, mixed>): void> */
    private array $handlers;

    /**
     * @param list<callable(array<string, mixed>): void> $handlers
     */
    public function __construct(string $name, array $handlers = [])
    {
        $this->name = $name;
        $this->handlers = $handlers;
    }

    /**
     * @param callable(array<string, mixed>): void $fn
     */
    public function addHandler(callable $fn): void
    {
        $this->handlers[] = $fn;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log(self::DEBUG, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->log(self::INFO, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log(self::WARNING, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->log(self::ERROR, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(int $level, string $message, array $context = []): void
    {
        $record = [
            'name' => $this->name,
            'created_at' => Url::now(),
            'level' => $level,
            'level_name' => self::LEVEL_NAMES[$level],
            'message' => $message,
            'context' => $context,
        ];

        foreach ($this->handlers as $handler) {
            $handler($record);
        }
    }
}

final class StreamHandler
{
    private string $stream;
    private int $level;

    public function __construct(string $stream, int $level = Logger::DEBUG)
    {
        $this->stream = $stream;
        $this->level = $level;
    }

    /**
     * @param array<string, mixed> $record
     */
    public function __invoke(array $record): void
    {
        $level = LogFormatter::intValue($record['level'] ?? 0);
        if ($level < $this->level) {
            return;
        }

        if (LogFilter::shouldLog($record) === false) {
            return;
        }

        $record = LogFormatter::formatRecord($record);
        $context = LogFormatter::encodeContext(LogFormatter::normalizeContext($record['context'] ?? null));

        $createdAt = $record['created_at'] ?? null;
        if ($createdAt instanceof \DateTimeInterface === false) {
            return;
        }

        $text = sprintf(
            "[%s] %s.%s %s %s\n",
            $createdAt->format('Y-m-d H:i:s'),
            LogFormatter::stringValue($record['name'] ?? ''),
            LogFormatter::stringValue($record['level_name'] ?? ''),
            LogFormatter::stringValue($record['message'] ?? ''),
            $context
        );

        file_put_contents($this->stream, $text, FILE_APPEND | LOCK_EX);
    }
}

final class ErrorLogHandler
{
    private int $level;

    public function __construct(int $level = Logger::DEBUG)
    {
        $this->level = $level;
    }

    /**
     * @param array<string, mixed> $record
     */
    public function __invoke(array $record): void
    {
        $level = LogFormatter::intValue($record['level'] ?? 0);
        if ($level < $this->level) {
            return;
        }

        if (LogFilter::shouldLog($record) === false) {
            return;
        }

        $record = LogFormatter::formatRecord($record);
        $context = LogFormatter::encodeContext(LogFormatter::normalizeContext($record['context'] ?? null));

        $createdAt = $record['created_at'] ?? null;
        if ($createdAt instanceof \DateTimeInterface === false) {
            return;
        }

        $text = sprintf(
            '[%s] %s.%s %s %s',
            $createdAt->format('Y-m-d H:i:s'),
            LogFormatter::stringValue($record['name'] ?? ''),
            LogFormatter::stringValue($record['level_name'] ?? ''),
            LogFormatter::stringValue($record['message'] ?? ''),
            $context
        );

        error_log($text);
    }
}

final class NullLogger implements Logger
{
    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $message, array $context = []): void
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
    }
}
