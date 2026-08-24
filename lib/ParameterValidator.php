<?php

declare(strict_types=1);

namespace RSSBridge;

class ParameterValidator
{
    /**
     * Validate and sanitize user inputs against configured bridge parameters (contexts)
     */
    public function validateInput(array &$input, array $parameters): array
    {
        $errors = [];

        foreach ($input as $name => $value) {
            $registered = false;
            foreach ($parameters as $contextName => $contextParameters) {
                if (!array_key_exists($name, $contextParameters)) {
                    continue;
                }
                $registered = true;
                if (!isset($contextParameters[$name]['type'])) {
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
                        $input[$name] = $this->validateListValue($value, $contextParameters[$name]['values']);
                        break;
                    default:
                    case 'text':
                        if (isset($contextParameters[$name]['pattern'])) {
                            $input[$name] = $this->validateTextValue($value, $contextParameters[$name]['pattern']);
                        } else {
                            $input[$name] = $this->validateTextValue($value);
                        }
                        break;
                }

                if (
                    is_null($input[$name])
                    && isset($contextParameters[$name]['required'])
                    && $contextParameters[$name]['required']
                ) {
                    $errors[] = ['name' => $name, 'reason' => 'Parameter is invalid!'];
                }
            }

            if (!$registered) {
                $errors[] = ['name' => $name, 'reason' => 'Parameter is not registered!'];
            }
        }

        return $errors;
    }

    /**
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
            if (array_key_exists('global', $parameters)) {
                $notInContext = array_diff_key($notInContext, $parameters['global']);
            }
            if (count($notInContext) > 0) {
                continue;
            }

            // Check if all parameters of the context are satisfied
            foreach ($contextParameters as $id => $properties) {
                if (!empty($input[$id])) {
                    $queriedContexts[$contextName] = true;
                } elseif (
                    isset($properties['type'])
                    && ($properties['type'] === 'checkbox' || $properties['type'] === 'list')
                ) {
                    continue;
                } elseif (isset($properties['required']) && $properties['required'] === true) {
                    $queriedContexts[$contextName] = false;
                    break;
                }
            }
        }

        // Abort if one of the globally required parameters is not satisfied
        if (
            array_key_exists('global', $parameters)
            && $queriedContexts['global'] === false
        ) {
            return null;
        }
        unset($queriedContexts['global']);

        switch (array_sum($queriedContexts)) {
            case 0:
                // Found no match, is there a context without parameters?
                if (isset($input['context'])) {
                    return (string)$input['context'];
                }
                foreach ($queriedContexts as $context2 => $queried) {
                    if (is_null($queried)) {
                        return (string)$context2;
                    }
                }
                return null;
            case 1:
                // Found unique match
                return array_search(true, $queriedContexts);
            default:
                return false;
        }
    }

    private function validateTextValue(mixed $value, ?string $pattern = null): ?string
    {
        if (is_null($pattern)) {
            // No filtering taking place
            $filteredValue = filter_var($value, FILTER_DEFAULT);
        } else {
            $filteredValue = filter_var((string)$value, FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/^' . $pattern . '$/']]);
        }
        if ($filteredValue === false) {
            return null;
        }
        return (string)$filteredValue;
    }

    private function validateNumberValue(mixed $value): ?int
    {
        $filteredValue = filter_var($value, FILTER_VALIDATE_INT);
        if ($filteredValue === false) {
            return null;
        }
        return (int)$filteredValue;
    }

    private function validateCheckboxValue(mixed $value): ?bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private function validateListValue(mixed $value, array $expectedValues): ?string
    {
        $filteredValue = filter_var($value, FILTER_DEFAULT);
        if ($filteredValue === false) {
            return null;
        }
        if (!in_array($filteredValue, $expectedValues, true)) {
            // Check sub-values?
            foreach ($expectedValues as $subName => $subValue) {
                if (is_array($subValue) && in_array($filteredValue, $subValue, true)) {
                    return (string)$filteredValue;
                }
            }
            return null;
        }
        return (string)$filteredValue;
    }
}
