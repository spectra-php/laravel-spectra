<?php

declare(strict_types=1);

namespace Spectra\Support\Query;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;

/**
 * Traces and conversations of a range too large to add up on every page load.
 *
 * Grouping every call of a long range by its trace reads all of them, for each
 * page, its count and the totals above it. Past `dashboard.exact_row_limit`
 * calls, the lists walk the latest calls instead: the groups they name are the
 * page, and a sample of them scales up to a count that is marked as an estimate.
 */
class RecentGroups
{
    /** Calls read per group wanted, as most groups hold a few. */
    private const CALLS_PER_GROUP = 4;

    /** The most calls one page reads, which ends how deep the list goes. */
    private const MAX_CALLS = 50000;

    /** The calls a count and the totals above a list scale up from. */
    private const SAMPLE_CALLS = 2000;

    /**
     * Determine if the range holds too many calls to add up. Without the daily
     * stats there is no cheap way to tell, so the answer is no.
     */
    public function isLarge(DateRange $dateRange): bool
    {
        $range = RequestAggregateQuery::fromRollups($dateRange);

        if (! $range->isOnRollups()) {
            return false;
        }

        return ($range->countAs('calls')->first()->calls ?? 0) > (int) config('spectra.dashboard.exact_row_limit', 50000);
    }

    /**
     * Get the ids of one page of groups, newest first.
     *
     * @param  Builder<SpectraRequest>  $calls  a query of the calls the list's filters keep
     * @return array<int, string>
     */
    public function page(Builder $calls, string $column, int $page, int $perPage): array
    {
        return array_slice($this->latestIds($calls, $column, min($page * $perPage * self::CALLS_PER_GROUP, self::MAX_CALLS))->all(), ($page - 1) * $perPage, $perPage);
    }

    /**
     * Get the groups of the latest sample of calls, and how many they scale up
     * to out of the calls of the range. A sample that ran out of calls covers
     * all of them, so its count is exact.
     *
     * @param  Builder<SpectraRequest>  $calls  a query of the calls the list's filters keep
     * @return array{ids: array<int, string>, estimate: int}
     */
    public function sample(Builder $calls, string $column, int $callsInRange): array
    {
        $values = $calls->clone()->reorder()->latest()->limit(self::SAMPLE_CALLS)->pluck($column);
        $ids = $values->map(fn (mixed $id) => (string) $id)->unique()->values()->all();

        if ($values->count() < self::SAMPLE_CALLS) {
            return ['ids' => $ids, 'estimate' => count($ids)];
        }

        return ['ids' => $ids, 'estimate' => (int) round($callsInRange * count($ids) / $values->count())];
    }

    /**
     * Get the distinct ids of the latest calls, newest first.
     *
     * @param  Builder<SpectraRequest>  $calls
     * @return Collection<int, string>
     */
    protected function latestIds(Builder $calls, string $column, int $limit): Collection
    {
        return $calls->clone()->reorder()->latest()->limit($limit)->pluck($column)
            ->map(fn (mixed $id) => (string) $id)
            ->unique()
            ->values();
    }
}
