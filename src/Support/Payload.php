<?php

declare(strict_types=1);

namespace Spectra\Support;

/**
 * Reads a number from provider JSON, where a usage field can be missing, a
 * numeric string or, when a provider changes its shape, an array or a word.
 *
 * A plain cast gets those wrong in ways that reach the bill: `(int) [1]` is
 * 1 and `(int) 'n/a'` is 0 only by luck. Under `strict_types` the value
 * must be cast before it reaches a typed property, so every payload number
 * goes through here and anything that isn't numeric becomes the default.
 */
class Payload
{
    /**
     * Get a payload value as an integer, or the default when it is not numeric.
     */
    public static function int(mixed $value, int $default = 0): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Get a payload value as a float, or the default when it is not numeric.
     */
    public static function float(mixed $value, float $default = 0.0): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }
}
