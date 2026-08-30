<?php

declare(strict_types=1);

namespace RSSBridge;

class Container implements \ArrayAccess
{
    private array $values = [];
    private array $resolved = [];

    public function offsetSet($offset, $value): void
    {
        $this->values[$offset] = $value;
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        if (isset($this->values[$offset]) === false) {
            throw new \Exception(sprintf('Unknown container key: "%s"', $offset));
        }
        if (isset($this->resolved[$offset]) === false) {
            $this->resolved[$offset] = $this->values[$offset]($this);
        }
        return $this->resolved[$offset];
    }

    public function offsetExists($offset): bool
    {
        return isset($this->values[$offset]) === true;
    }

    public function offsetUnset($offset): void
    {
    }
}
