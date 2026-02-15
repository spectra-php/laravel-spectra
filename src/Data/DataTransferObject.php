<?php

namespace Spectra\Data;

use ArrayAccess;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use JsonSerializable;
use ReflectionClass;
use ReflectionProperty;

/**
 * @implements \ArrayAccess<string, mixed>
 * @implements \Illuminate\Contracts\Support\Arrayable<string, mixed>
 */
abstract readonly class DataTransferObject implements Arrayable, ArrayAccess, Jsonable, JsonSerializable
{
    public function toArray(): array
    {
        $properties = (new ReflectionClass($this))
            ->getProperties(ReflectionProperty::IS_PUBLIC);

        $array = [];

        foreach ($properties as $property) {
            $value = $property->getValue($this);

            if ($value instanceof DataTransferObject || $value instanceof \Illuminate\Support\Collection) {
                $value = $value->toArray();
            }

            $array[$property->getName()] = $value;
        }

        return $array;
    }

    public function toJson($options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options) ?: '';
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->toArray());
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->toArray()[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        // DTOs are readonly
    }

    public function offsetUnset(mixed $offset): void
    {
        // DTOs are readonly
    }
}
