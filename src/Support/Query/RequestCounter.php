<?php

declare(strict_types=1);

namespace Spectra\Support\Query;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Spectra\Data\RowCount;
use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;

/**
 * How many requests a list's filters keep, without COUNT(*) walking them all.
 *
 * Filters the daily stats also hold are summed from them, which is exact. Any
 * other filter counts up to a limit, and the result says it stopped there: the
 * pager only needs to know there is more.
 */
class RequestCounter
{
    /** Where a count that can't use the daily stats stops. */
    public const LIMIT = 10000;

    /** The filters and search that only individual calls can answer. */
    private const CALL_FILTERS = [
        'search', 'capability', 'finish_reason', 'has_tool_calls', 'is_reasoning', 'reasoning_effort', 'trace_id',
        'conversation_id', 'prompt_name', 'prompt_version', 'error_fingerprint', 'tag', 'tags',
    ];

    /**
     * Count the rows of a query, whose filters came from the request.
     *
     * @param  Builder<SpectraRequest>  $query
     */
    public function count(Builder $query, Request $request, DateRange $dateRange): RowCount
    {
        if (! $request->anyFilled(self::CALL_FILTERS) && app(RollupSchema::class)->covers($dateRange)) {
            return new RowCount(rows: $this->sumDailyStats($request, $dateRange), isExact: true);
        }

        $counted = (new SpectraRequest)->getConnection()->query()
            ->fromSub($query->clone()->reorder()->toBase()->selectRaw('1 as counted')->limit(self::LIMIT + 1), 'counted_rows')
            ->count();

        return new RowCount(rows: min($counted, self::LIMIT), isExact: $counted <= self::LIMIT);
    }

    /**
     * Add up the daily stats the request's filters keep.
     */
    protected function sumDailyStats(Request $request, DateRange $dateRange): int
    {
        $stats = RequestAggregateQuery::fromRollups($dateRange);

        if ($request->anyFilled(['trackable_type', 'trackable_id'])) {
            $stats->withTrackable();
        }

        $column = match ($request->input('status')) {
            'success' => 'successful_count',
            'error' => 'failed_count',
            default => 'request_count',
        };

        return (int) $stats->toQuery()
            ->when($request->filled('provider'), fn (Builder $query) => $query->where('provider', $request->input('provider')))
            ->when($request->filled('model'), fn (Builder $query) => $query->where('model', 'like', "%{$request->input('model')}%"))
            ->when($request->filled('model_type'), fn (Builder $query) => $query->where('model_type', $request->input('model_type')))
            ->when($request->filled('trackable_type'), fn (Builder $query) => $query->where('trackable_type', $request->input('trackable_type')))
            ->when($request->filled('trackable_id'), fn (Builder $query) => $query->where('trackable_id', $request->input('trackable_id')))
            ->sum($column);
    }
}
