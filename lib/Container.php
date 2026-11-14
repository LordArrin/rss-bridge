<?php

declare(strict_types=1);

namespace RSSBridge;

/**
 * @implements \ArrayAccess<string, mixed>
 */
class Container implements \ArrayAccess
{
    /**
     * Registered entries: either a factory callable(Container): mixed,
     * or a plain value that is returned as-is.
     *
     * @var array<string, mixed>
     */
    private array $values = [];

    /**
     * @var array<string, mixed>
     */
    private array $resolved = [];

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (is_string($offset) === false) {
            throw new \InvalidArgumentException('Container key must be a string');
        }
        $this->values[$offset] = $value;
    }

    public function offsetGet(mixed $offset): mixed
    {
        if (is_string($offset) === false) {
            throw new \InvalidArgumentException('Container key must be a string');
        }
        if (isset($this->values[$offset]) === false) {
            throw new \Exception(sprintf('Unknown container key: "%s"', $offset));
        }

        // array_key_exists (not isset) so factories returning null are cached too.
        if (array_key_exists($offset, $this->resolved) === false) {
            $factory = $this->values[$offset];
            $this->resolved[$offset] = is_callable($factory) === true ? $factory($this) : $factory;
        }

        return $this->resolved[$offset];
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_string($offset) && isset($this->values[$offset]);
    }

    public function offsetUnset(mixed $offset): void
    {
        if (is_string($offset) === true) {
            unset($this->values[$offset], $this->resolved[$offset]);
        }
    }
}
