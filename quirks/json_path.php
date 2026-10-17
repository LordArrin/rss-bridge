<?php

declare(strict_types=1);

/**
 * Safely traverse a nested object/array structure by a sequence of keys.
 *
 * Returns null if any step in the path is missing or the intermediate
 * value is not an object or array. This is useful for accessing deeply
 * nested JSON-decoded data where the structure is not guaranteed.
 *
 * @param mixed $value The root object or array to traverse
 * @param string ...$path Sequence of property names or array keys
 * @return mixed The value at the end of the path, or null if not found
 */
function read_json_path(mixed $value, string ...$path): mixed
{
    foreach ($path as $key) {
        if (is_object($value) === true) {
            $properties = get_object_vars($value);
            if (array_key_exists($key, $properties) === false) {
                return null;
            }
            $value = $properties[$key];
        } elseif (is_array($value) === true) {
            if (array_key_exists($key, $value) === false) {
                return null;
            }
            $value = $value[$key];
        } else {
            return null;
        }
    }

    return $value;
}
