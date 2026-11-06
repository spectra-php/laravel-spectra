<?php

declare(strict_types=1);

namespace Spectra\Actions\Requests;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Spectra\Data\RowPage;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Concerns\FiltersRequests;
use Spectra\Support\DateRange;
use Spectra\Support\Pricing\PricingLookup;
use Spectra\Support\Query\RequestAggregateQuery;
use Spectra\Support\Query\RequestCounter;
use Spectra\Support\Query\RollupSchema;

/**
 * The page of requests the requests screen shows: its filters, search and
 * sort applied to a light column list.
 */
class GetFilteredRequests
{
    use FiltersRequests;

    /** The columns a list row reads: never the request/response payloads. */
    private const LIST_COLUMNS = [
        'id', 'batch_id', 'trace_id', 'response_id',
        'provider', 'model', 'snapshot', 'model_type', 'endpoint',
        'trackable_type', 'trackable_id',
        'prompt_tokens', 'completion_tokens', 'reasoning_tokens',
        'duration_seconds', 'input_characters', 'image_count', 'video_count',
        'prompt_cost', 'completion_cost', 'total_cost_in_cents', 'pricing_tier',
        'latency_ms', 'time_to_first_token_ms', 'tokens_per_second',
        'is_reasoning', 'reasoning_effort', 'is_streaming',
        'finish_reason', 'has_tool_calls', 'tool_call_counts',
        'status_code', 'error_type', 'metadata',
        'created_at', 'completed_at', 'expires_at',
    ];

    /**
     * Create a new get filtered requests action instance.
     */
    public function __construct(
        private readonly PricingLookup $pricingLookup,
        private readonly RequestCounter $counter,
        private readonly RollupSchema $rollups,
    ) {}

    /**
     * Get the sorted page of requests for the filters, search and date range.
     *
     * The page's ids come from the index alone, so a deep page doesn't read
     * every row before it, and the count comes from the daily stats or stops
     * at a limit. A `cursor` from the previous page reads the next one without
     * an offset at all.
     */
    public function __invoke(Request $request, DateRange $dateRange): RowPage
    {
        $sortable = ['created_at', 'total_tokens', 'total_cost_in_cents', 'latency_ms'];
        $sortBy = in_array($request->input('sort_by'), $sortable, true) ? $request->input('sort_by') : 'created_at';
        $sortDirection = $request->input('sort_dir') === 'asc' ? 'asc' : 'desc';
        $perPage = min(max((int) $request->input('per_page', 25), 1), 100);
        $page = max((int) $request->input('page', 1), 1);

        $query = SpectraRequest::query();
        $dateRange->apply($query);
        $this->applyFilters($query, $request, $dateRange);

        $count = $this->counter->count($query, $request, $dateRange);

        // Sorting by anything but time has no index that also holds the date
        // range, so past a limit it sorts the latest calls and says so.
        $sortedWithinLatest = null;

        $sortWindow = (int) config('spectra.dashboard.exact_row_limit', 50000);

        if ($sortBy !== 'created_at' && (! $count->isExact || $count->rows > $sortWindow)) {
            $oldest = $query->clone()->latest()->orderByDesc('id')->offset($sortWindow - 1)->limit(1)->first(['created_at', 'id']);

            if ($oldest !== null) {
                $query->where(fn (Builder $latest) => $latest
                    ->where('created_at', '>', $oldest->getRawOriginal('created_at'))
                    ->orWhere(fn (Builder $tie) => $tie->where('created_at', $oldest->getRawOriginal('created_at'))->where('id', '>=', $oldest->getKey())));
                $sortedWithinLatest = $sortWindow;
            }
        }

        // total_tokens is an appended attribute, not a column.
        $ordered = $query->clone()
            ->when(
                $sortBy === 'total_tokens',
                fn (Builder $sorted) => $sorted->orderByRaw($sortDirection === 'asc' ? '(prompt_tokens + completion_tokens) asc' : '(prompt_tokens + completion_tokens) desc'),
                fn (Builder $sorted) => $sorted->orderBy($sortBy, $sortDirection),
            )
            // created_at is stored to the second: without this, calls of the same
            // second could move between pages.
            ->orderBy('id', $sortDirection);

        $cursor = $sortBy === 'created_at' ? $this->parseCursor($request->input('cursor')) : null;

        if ($cursor !== null) {
            [$cursorAt, $cursorId] = $cursor;
            $comparison = $sortDirection === 'asc' ? '>' : '<';

            // The plain bound lets the index start at the cursor; the OR alone can't.
            $ordered->where('created_at', $sortDirection === 'asc' ? '>=' : '<=', $cursorAt);
            $ordered->where(fn (Builder $after) => $after
                ->where('created_at', $comparison, $cursorAt)
                ->orWhere(fn (Builder $tie) => $tie->where('created_at', $cursorAt)->where('id', $comparison, $cursorId)));
        } else {
            $ordered->offset(($page - 1) * $perPage);
        }

        $ids = $ordered->limit($perPage)->pluck('id')->all();

        $rows = SpectraRequest::query()
            ->select(self::LIST_COLUMNS)
            ->with('tags')
            ->whereKey($ids)
            ->get()
            ->sortBy(fn (SpectraRequest $row) => array_search($row->getKey(), $ids, true))
            ->values();

        $offset = ($page - 1) * $perPage;
        // A total that stopped at the limit must not end the pager on a full page.
        $total = max($count->rows, $offset + $rows->count() + ($rows->count() === $perPage && ! $count->isExact ? 1 : 0));
        $last = $rows->last();

        return new RowPage(
            paginator: new LengthAwarePaginator($rows, $total, $perPage, $page),
            totalIsEstimate: ! $count->isExact,
            nextCursor: $sortBy === 'created_at' && $last instanceof SpectraRequest && $rows->count() === $perPage
                ? base64_encode($last->getRawOriginal('created_at').'|'.$last->getKey())
                : null,
            sortedWithinLatest: $sortedWithinLatest,
        );
    }

    /**
     * Read a cursor from the previous page: when its last row was made, and its id.
     *
     * @return array{0: string, 1: string}|null
     */
    private function parseCursor(mixed $cursor): ?array
    {
        if (! is_string($cursor) || blank($cursor)) {
            return null;
        }

        $parts = explode('|', (string) base64_decode($cursor, true), 2);

        if (count($parts) !== 2 || ! Str::isUuid($parts[1]) || strtotime($parts[0]) === false) {
            return null;
        }

        return [$parts[0], $parts[1]];
    }

    /**
     * Apply the model and id search and the request filters to the query.
     *
     * @param  Builder<SpectraRequest>  $query
     */
    private function applyFilters($query, Request $request, DateRange $dateRange): void
    {
        if ($request->filled('search')) {
            $search = $request->input('search');

            // Search model names and exact ids — deliberately NOT the
            // request/response longtext columns, which cannot use an index
            // and turn every keystroke into a full-table scan at volume.
            // id is a native uuid column on PostgreSQL, which errors on any
            // other string, so it is only compared with a UUID; trace_id
            // too while it is still one (see SpectraRequest::hasUuidTraceIdColumn()).
            $isUuid = Str::isUuid($search);
            $matchesTraceId = $isUuid || ! (new SpectraRequest)->hasUuidTraceIdColumn();

            // A substring can't use an index, so the daily stats name the models
            // it matches and the index on model finds their calls; a search that
            // matches no model then needs no walk through the calls at all.
            $models = $this->rollups->covers($dateRange)
                ? $this->findModels((string) $search, $dateRange)
                : null;

            $query->where(function (Builder $searchQuery) use ($search, $models, $isUuid, $matchesTraceId) {
                $searchQuery
                    ->when($models === null, fn (Builder $byName) => $byName->where('model', 'like', "%{$search}%"))
                    ->when($models !== null && $models !== [], fn (Builder $byName) => $byName->whereIn('model', $models))
                    ->orWhere('response_id', $search)
                    ->when($isUuid, fn (Builder $uuidQuery) => $uuidQuery->orWhere('id', $search))
                    ->when($matchesTraceId, fn (Builder $traceQuery) => $traceQuery->orWhere('trace_id', $search));
            });
        }

        $this->applyRequestFilters($query, $request, $this->pricingLookup);
    }

    /**
     * Get the models of the period whose name contains the search text.
     *
     * @return array<int, string>
     */
    private function findModels(string $search, DateRange $dateRange): array
    {
        return RequestAggregateQuery::fromRollups($dateRange)
            ->toQuery()
            ->toBase()
            ->where('model', 'like', "%{$search}%")
            ->distinct()
            ->pluck('model')
            ->map(fn (mixed $model) => (string) $model)
            ->all();
    }
}
