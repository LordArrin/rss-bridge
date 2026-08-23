<?php

declare(strict_types=1);

/**
 * JSON encoder/decoder with sane defaults.
 * Based on https://github.com/nette/utils/blob/master/src/Utils/Json.php
 */
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