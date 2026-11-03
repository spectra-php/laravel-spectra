<?php

declare(strict_types=1);

namespace Spectra\Actions\Trackables;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RequestAggregateQuery;

/**
 * The page of trackables (users, teams, ...) the trackables screen lists:
 * one row per (trackable_type, trackable_id) with its usage, cost and
 * latency, filtered and sorted as the screen asks.
 */
class GetFilteredTrackables
{
    /**
     * @return LengthAwarePaginator<int, SpectraRequest>
     */
    public function __invoke(Request $request, DateRange $dateRange): LengthAwarePaginator
    {
        // The sort keys the screen sends, mapped to the aggregate they order by.
        $sortColumn = match ($request->input('sort_by')) {
            'requests' => 'requests',
            'tokens' => 'tokens_sum',
            default => 'cost',
        };
        $sortDirection = $request->input('sort_dir') === 'asc' ? 'asc' : 'desc';
        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        return RequestAggregateQuery::make($dateRange)
            ->withTrackable()
            ->filteredBy($request)
            ->groupByColumns('trackable_type', 'trackable_id')
            ->countAs('requests')
            ->tokenSum()
            ->mediaSums()
            ->successfulCost()
            ->successfulLatency('latency_avg')
            ->orderBy($sortColumn, $sortDirection)
            // A stable tiebreaker so equal rows don't move between pages.
            ->orderBy('trackable_type')
            ->orderBy('trackable_id')
            ->paginate($perPage);
    }
}
