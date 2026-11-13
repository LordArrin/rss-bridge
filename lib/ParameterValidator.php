<?php

declare(strict_types=1);

namespace RSSBridge;

class ParameterValidator
{
    /**
     * Validate and sanitize user inputs against configured bridge parameters (contexts)
     *
     * @param array<string, mixed> $input
     * @param array<string, array<string, array<string, mixed>>> $parameters
     * @return list<array{name: string, reason: string}>
     */
    public function validateInput(array &$input, array $parameters): array
    {
        $errors = [];

        foreach ($input as $name => $value) {
            $registered = false;
            foreach ($parameters as $contextName => $contextParameters) {
                if (array_key_exists($name, $contextParameters) === false) {
                    continue;
                }
                $registered = true;
                if (isset($contextParameters[$name]['type']) === false) {
                    // Default type is text
                    $contextParameters[$name]['type'] = 'text';
                }

                switch ($contextParameters[$name]['type']) {
                    case 'number':
                        $input[$name] = $this->validateNumberValue($value);
                        break;
                    case 'checkbox':
                        $input[$name] = $this->validateCheckboxValue($value);
                        break;
                    case 'list':
                        $expectedValues = $contextParameters[$name]['values'] ?? [];
                        $input[$name] = $this->validateListValue($value, is_array($expectedValues) === true ? $expectedValues : []);
                        break;
                    default:
                    case 'text':
                        if (isset($contextParameters[$name]['pattern']) === true) {
                            $input[$name] = $this->validateTextValue($value, $contextParameters[$name]['pattern']);
                        } else {
                            $input[$name] = $this->validateTextValue($value);
                        }
                        break;
                }

                if (
                    $input[$name] === null
                    && isset($contextParameters[$name]['required']) === true
                    && $contextParameters[$name]['required'] === true
                ) {
                    $errors[] = ['name' => $name, 'reason' => 'Parameter is invalid!'];
                }
            }

            if ($registered === false) {
                $errors[] = ['name' => $name, 'reason' => 'Parameter is not registered!'];
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, array<string, mixed>> $parameters
     * @return string|int|false|null
     */
    public function getQueriedContext(array $input, array $parameters): string|int|false|null
    {
        $queriedContexts = [];

        // Detect matching context
        foreach ($parameters as $contextName => $contextParameters) {
            $queriedContexts[$contextName] = null;

            // Ensure all user data exist in the current context
            $notInContext = array_diff_key($input, $contextParameters);
            if (array_key_exists('global', $parameters) === true) {
                $notInContext = array_diff_key($notInContext, $parameters['global']);
            }
            if (count($notInContext) > 0) {
                continue;
            }

            // Check if all parameters of the context are satisfied
            foreach ($contextParameters as $id => $properties) {
                if (($input[$id] ?? '') !== '') {
                    $queriedContexts[$contextName] = true;
                } elseif (
                    isset($properties['type']) === true
                    && ($properties['type'] === 'checkbox' || $properties['type'] === 'list')
                ) {
                    continue;
                } elseif (isset($properties['required']) === true && $properties['required'] === true) {
                    $queriedContexts[$contextName] = false;
                    break;
                }
            }
        }

        // Abort if one of the globally required parameters is not satisfied
        if (
            array_key_exists('global', $parameters) === true
            && $queriedContexts['global'] === false
        ) {
            return null;
        }
        unset($queriedContexts['global']);

        // Count matches explicitly to avoid array_search type narrowing issues
        $matchCount = 0;
        $matchedContext = null;
        foreach ($queriedContexts as $contextName => $queried) {
            if ($queried === true) {
                $matchCount++;
                $matchedContext = $contextName;
            }
        }

        switch ($matchCount) {
            case 0:
                // Found no match, is there a context without parameters?
                if (isset($input['context']) === true) {
                    return (string) $input['context'];
                }
                foreach ($queriedContexts as $contextName => $queried) {
                    if ($queried === null) {
                        return $contextName;
                    }
                }
                return null;
            case 1:
                // Found unique match
                return $matchedContext;
            default:
                return false;
        }
    }

    private function validateTextValue(mixed $value, ?string $pattern = null): ?string
    {
        if ($pattern === null) {
            $filteredValue = filter_var($value, FILTER_UNSAFE_RAW);
        } else {
            $filteredValue = filter_var((string) $value, FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/^' . $pattern . '$/']]);
        }
        return $filteredValue === false ? null : $filteredValue;
    }

    private function validateNumberValue(mixed $value): ?int
    {
        $filteredValue = filter_var($value, FILTER_VALIDATE_INT);
        return $filteredValue === false ? null : $filteredValue;
    }

    private function validateCheckboxValue(mixed $value): ?bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * @param array<int|string, mixed> $expectedValues
     */
    private function validateListValue(mixed $value, array $expectedValues): ?string
    {
        if (is_array($value) === true) {
            return null;
        }

        $filteredValue = filter_var($value, FILTER_UNSAFE_RAW);
        if ($filteredValue === false) {
            return null;
        }

        $allowed = [];
        array_walk_recursive($expectedValues, static function (mixed $item) use (&$allowed): void {
            if (is_string($item) === true || is_int($item) === true || is_float($item) === true) {
                $allowed[] = (string) $item;
            }
        });

        if (in_array($filteredValue, $allowed, true) === true) {
            return $filteredValue;
        }

        return null;
    }
}
