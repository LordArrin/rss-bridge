<?php

declare(strict_types=1);

namespace RSSBridge;

class Container implements \ArrayAccess
{
    private array $values = [];
    private array $resolved = [];

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->values[$offset] = $value;
    }

    public function offsetGet(mixed $offset): mixed
    {
        if (isset($this->values[$offset]) === false) {
            throw new \Exception(sprintf('Unknown container key: "%s"', $offset));
        }
        if (isset($this->resolved[$offset]) === false) {
            $this->resolved[$offset] = $this->values[$offset]($this);
        }
        return $this->resolved[$offset];
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->values[$offset]) === true;
    }

    public function offsetUnset(mixed $offset): void
    {
    }
}
