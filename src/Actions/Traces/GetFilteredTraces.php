<?php

declare(strict_types=1);

namespace Spectra\Actions\Traces;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Spectra\Data\RowPage;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RecentGroups;
use Spectra\Support\Query\RequestAggregateQuery;
use Spectra\Support\Query\RequestCounter;

/**
 * The page of traces the traces screen shows, sorted by start, cost,
 * duration, calls, failures or tokens.
 */
class GetFilteredTraces
{
    /** Sort keys the screen sends, mapped to the totals' column aliases. */
    private const SORTABLE = [
        'started_at' => 'first_started_at',
        'cost' => 'cost',
        'duration' => 'duration_ms',
        'requests' => 'request_count',
        'failed' => 'failed_count',
        'tokens' => 'tokens',
    ];

    /**
     * Create a new get filtered traces action instance.
     */
    public function __construct(
        private readonly BuildTraceQuery $buildTraceQuery,
        private readonly RecentGroups $recentGroups,
        private readonly RequestCounter $counter,
    ) {}

    /**
     * Get the sorted page of the list for the filters and date range.
     */
    public function __invoke(Request $request, DateRange $dateRange): RowPage
    {
        $sortBy = self::SORTABLE[(string) $request->input('sort_by')] ?? 'first_started_at';
        $sortDirection = $request->input('sort_dir') === 'asc' ? 'asc' : 'desc';
        $perPage = min(max((int) $request->input('per_page', 25), 1), 100);
        $page = max((int) $request->input('page', 1), 1);

        if ($this->recentGroups->isLarge($dateRange)) {
            return $this->getLatestPage($request, $dateRange, $sortBy, $sortDirection, $perPage, $page);
        }

        return new RowPage(paginator: ($this->buildTraceQuery)($request, $dateRange)
            ->orderBy($sortBy, $sortDirection)
            ->orderBy('trace_id')
            ->paginate($perPage));
    }

    /**
     * Get the page of a range too large to add up: the groups of the latest
     * calls, with the page itself in the requested order, and an estimated total.
     *
     * @param  'asc'|'desc'  $sortDirection
     */
    private function getLatestPage(Request $request, DateRange $dateRange, string $sortBy, string $sortDirection, int $perPage, int $page): RowPage
    {
        $calls = ($this->buildTraceQuery)->matchingTraceIds($request, $dateRange);
        $ids = $this->recentGroups->page($calls, 'trace_id', $page, $perPage);
        $count = $this->counter->count($calls, $request, $dateRange);
        $estimate = $this->recentGroups->sample($calls, 'trace_id', $count->rows)['estimate'];

        $rows = $ids === []
            ? new Collection
            : RequestAggregateQuery::make($dateRange)
                ->forTraces($ids)
                ->groupByColumns('trace_id')
                ->callTotals()
                ->orderBy($sortBy, $sortDirection)
                ->get();

        $offset = ($page - 1) * $perPage;

        return new RowPage(
            paginator: new LengthAwarePaginator($rows, max($estimate, $offset + $rows->count() + ($rows->count() === $perPage ? 1 : 0)), $perPage, $page),
            totalIsEstimate: true,
        );
    }
}
