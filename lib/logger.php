<?php

/**
 * Logger module containing all logging-related classes.
 *
 * This file contains multiple classes, interface and trait by design
 * (single-module approach). PSR-1 single-class-per-file rule is
 * intentionally disabled for this file.
 *
 * @phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses
 * @phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols
 * @phpcs:disable Generic.Files.OneClassPerFile.MultipleFound
 * @phpcs:disable Generic.Files.OneInterfacePerFile.MultipleFound
 * @phpcs:disable Generic.Files.OneTraitPerFile.MultipleFound
 */

declare(strict_types=1);

namespace RSSBridge\Logger;

use RSSBridge\Exceptions\RateLimitException;
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

    public function debug(string $message, array $context = []): void;
    public function info(string $message, array $context = []): void;
    public function warning(string $message, array $context = []): void;
    public function error(string $message, array $context = []): void;
}

trait FormatsLogRecords
{
    private function formatRecord(array $record): array
    {
        if (isset($record['context']['e']) === true) {
            /** @var \Throwable $e */
            $e = $record['context']['e'];
            unset($record['context']['e']);
            $record['context']['type'] = get_class($e);
            $record['context']['code'] = $e->getCode();
            $record['context']['message'] = Url::sanitizeRoot($e->getMessage());
            $record['context']['file'] = Url::sanitizeRoot($e->getFile());
            $record['context']['line'] = $e->getLine();
            $record['context']['url'] = Url::getCurrentUrl();
            $record['context']['trace'] = Url::traceToCallPoints(Url::traceFromException($e));
        }
        return $record;
    }

    private function encodeContext(array $record): string
    {
        if ($record['context'] === []) {
            return '';
        }
        try {
            return \Json::encode($record['context']);
        } catch (\JsonException $e) {
            $record['context']['message'] = null;
            return \Json::encode($record['context']);
        }
    }
}

final class SimpleLogger implements Logger
{
    private string $name;
    private array $handlers;

    /**
     * @param callable[] $handlers
     */
    public function __construct(string $name, array $handlers = [])
    {
        $this->name = $name;
        $this->handlers = $handlers;
    }

    public function addHandler(callable $fn): void
    {
        $this->handlers[] = $fn;
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log(self::DEBUG, $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log(self::INFO, $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log(self::WARNING, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log(self::ERROR, $message, $context);
    }

    private function log(int $level, string $message, array $context = []): void
    {
        if (isset($context['e']) === true) {
            /** @var \Throwable $e */
            $e = $context['e'];

            if ($e instanceof RateLimitException) {
                return;
            }
            $ignoredMessages = [
                'Format name invalid',
                'Unknown format given',
                'Unable to find',
            ];
            foreach ($ignoredMessages as $ignoredMessage) {
                if (str_starts_with($e->getMessage(), $ignoredMessage) === true) {
                    return;
                }
            }
        }

        foreach ($this->handlers as $handler) {
            $handler([
                'name' => $this->name,
                'created_at' => Url::now(),
                'level' => $level,
                'level_name' => self::LEVEL_NAMES[$level],
                'message' => $message,
                'context' => $context,
            ]);
        }
    }
}

final class StreamHandler
{
    use FormatsLogRecords;

    private string $stream;
    private int $level;

    public function __construct(string $stream, int $level = Logger::DEBUG)
    {
        $this->stream = $stream;
        $this->level = $level;
    }

    public function __invoke(array $record): void
    {
        if ($record['level'] < $this->level) {
            return;
        }
        $record = $this->formatRecord($record);
        $context = $this->encodeContext($record);
        $text = sprintf(
            "[%s] %s.%s %s %s\n",
            $record['created_at']->format('Y-m-d H:i:s'),
            $record['name'],
            $record['level_name'],
            $record['message'],
            $context
        );
        file_put_contents($this->stream, $text, FILE_APPEND);
    }
}

final class ErrorLogHandler
{
    use FormatsLogRecords;

    private int $level;

    public function __construct(int $level = Logger::DEBUG)
    {
        $this->level = $level;
    }

    public function __invoke(array $record): void
    {
        if ($record['level'] < $this->level) {
            return;
        }
        $record = $this->formatRecord($record);
        $context = $this->encodeContext($record);
        $text = sprintf(
            '[%s] %s.%s %s %s',
            $record['created_at']->format('Y-m-d H:i:s'),
            $record['name'],
            $record['level_name'],
            $record['message'],
            $context
        );
        error_log($text);
    }
}

final class NullLogger implements Logger
{
    public function debug(string $message, array $context = []): void
    {
    }

    public function info(string $message, array $context = []): void
    {
    }

    public function warning(string $message, array $context = []): void
    {
    }

    public function error(string $message, array $context = []): void
    {
    }
}

// backward compatibility
class_alias(\RSSBridge\Logger\Logger::class, 'Logger');
class_alias(\RSSBridge\Logger\SimpleLogger::class, 'SimpleLogger');
class_alias(\RSSBridge\Logger\StreamHandler::class, 'StreamHandler');
class_alias(\RSSBridge\Logger\ErrorLogHandler::class, 'ErrorLogHandler');
class_alias(\RSSBridge\Logger\NullLogger::class, 'NullLogger');
