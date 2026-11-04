<?php

declare(strict_types=1);

namespace Spectra\Data;

use ArrayAccess;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Support\Collection;
use JsonSerializable;
use ReflectionClass;
use ReflectionProperty;

/**
 * Base class for every Spectra DTO: readonly public properties that turn
 * into an array or JSON with their names as keys (nested DTOs and
 * collections included), plus read-only array access so callers can treat a
 * DTO like the array it replaced.
 *
 * @implements ArrayAccess<string, mixed>
 * @implements Arrayable<string, mixed>
 */
abstract readonly class DataTransferObject implements Arrayable, ArrayAccess, Jsonable, JsonSerializable
{
    /**
     * Get the public properties as an array, with nested DTOs and collections as arrays.
     */
    public function toArray(): array
    {
        $properties = (new ReflectionClass($this))
            ->getProperties(ReflectionProperty::IS_PUBLIC);

        $array = [];

        foreach ($properties as $property) {
            $value = $property->getValue($this);

            if ($value instanceof DataTransferObject || $value instanceof Collection) {
                $value = $value->toArray();
            }

            $array[$property->getName()] = $value;
        }

        return $array;
    }

    /**
     * Get the DTO as a JSON string.
     */
    public function toJson($options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options) ?: '';
    }

    /**
     * Get the data to serialise to JSON.
     *
     * @return array<array-key, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Determine if the DTO has a property with the given name.
     */
    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->toArray());
    }

    /**
     * Get the value of the property with the given name, or null.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->toArray()[$offset] ?? null;
    }

    /**
     * Ignore the write, because DTOs are readonly.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        // DTOs are readonly
    }

    /**
     * Ignore the unset, because DTOs are readonly.
     */
    public function offsetUnset(mixed $offset): void
    {
        // DTOs are readonly
    }
}
