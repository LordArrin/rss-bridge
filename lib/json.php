<?php

declare(strict_types=1);

namespace RSSBridge\Utils;

/**
 * JSON encoder/decoder with sane defaults.
 * Based on https://github.com/nette/utils/blob/master/src/Utils/Json.php
 */
final class Json
{
    public static function encode(mixed $value, bool $pretty = true, bool $asciiSafe = false): string
    {
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES;
        if ($asciiSafe === false) {
            $flags |= JSON_UNESCAPED_UNICODE;
        }
        if ($pretty === true) {
            $flags |= JSON_PRETTY_PRINT;
        }
        return json_encode($value, $flags);
    }

    public static function decode(string $json, bool $assoc = true): mixed
    {
        return json_decode($json, $assoc, 512, JSON_THROW_ON_ERROR);
    }
}

// Backward compatibility: keep the global \Json name working for legacy
// bridges and callers. Do not use it in new code (reference
// RSSBridge\Utils\Json instead). Note: this alias is registered only when
// the classmap-optimized autoloader does not resolve the global "Json"
// symbol to a real class (e.g. phpunit or phpcs' own Json classes).
if (!class_exists('Json', false)) {
    class_alias(RSSBridge\Utils\Json::class, 'Json');
}
