<?php

declare(strict_types=1);

namespace Spectra\Support\Casts;

use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;

/**
 * A date cast that keeps milliseconds.
 *
 * Eloquent's `datetime` cast formats with the connection's date format,
 * which is to the second on every driver, so it would drop the milliseconds
 * a `dateTime($column, 3)` column can hold.
 *
 * @implements CastsAttributes<CarbonInterface, DateTimeInterface|string>
 */
class PreciseDateTime implements CastsAttributes
{
    protected const FORMAT = 'Y-m-d H:i:s.v';

    /**
     * Cast the stored value to a date instance that keeps its microseconds.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonInterface
    {
        if (blank($value)) {
            return null;
        }

        return $value instanceof DateTimeInterface ? Date::instance($value) : Date::parse((string) $value);
    }

    /**
     * Prepare the date for storage in the microsecond-precision format.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if (blank($value)) {
            return null;
        }

        $date = $value instanceof DateTimeInterface ? Date::instance($value) : Date::parse($value);

        return $date->format(self::FORMAT);
    }
}
