<?php

declare(strict_types=1);

namespace Spectra\Support\Query;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Latency percentiles by the nearest-rank method, the same on every database:
 * SQLite and MySQL have no percentile function, so each percentile is one
 * ordered lookup at its rank (`ORDER BY column LIMIT 1 OFFSET rank`).
 */
class Percentiles
{
    /**
     * Get the given percentiles of a column over the query's rows, keyed
     * "p50", "p95", ...; null when no row has a value.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<int, int>  $percentiles
     * @return array<string, int|null>
     */
    public function of(Builder $query, string $column, array $percentiles = [50, 95, 99]): array
    {
        $values = $query->clone()->whereNotNull($column);
        $count = $values->clone()->count();
        $result = [];

        foreach ($percentiles as $percentile) {
            if ($count === 0) {
                $result["p{$percentile}"] = null;

                continue;
            }

            $rank = max(0, (int) ceil($percentile / 100 * $count) - 1);
            $value = $values->clone()->reorder()->orderBy($column)->offset($rank)->limit(1)->value($column);

            $result["p{$percentile}"] = is_numeric($value) ? (int) round((float) $value) : null;
        }

        return $result;
    }

    /**
     * Get the given percentiles of values already read, by the same rank.
     *
     * @param  array<int, int|float|string>  $values
     * @param  array<int, int>  $percentiles
     * @return array<string, int|null>
     */
    public function ofValues(array $values, array $percentiles = [50, 95, 99]): array
    {
        sort($values, SORT_NUMERIC);
        $count = count($values);
        $result = [];

        foreach ($percentiles as $percentile) {
            $result["p{$percentile}"] = $count === 0
                ? null
                : (int) round((float) $values[max(0, (int) ceil($percentile / 100 * $count) - 1)]);
        }

        return $result;
    }
}
