<?php

declare(strict_types=1);

namespace Spectra\Actions\Traces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;

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
    ) {}

    /**
     * Get the sorted page of trace totals for the filters and date range.
     *
     * @return LengthAwarePaginator<int, SpectraRequest>
     */
    public function __invoke(Request $request, DateRange $dateRange): LengthAwarePaginator
    {
        $sortBy = self::SORTABLE[(string) $request->input('sort_by')] ?? 'first_started_at';
        $sortDirection = $request->input('sort_dir') === 'asc' ? 'asc' : 'desc';
        $perPage = min(max((int) $request->input('per_page', 25), 1), 100);

        return ($this->buildTraceQuery)($request, $dateRange)
            ->orderBy($sortBy, $sortDirection)
            ->orderBy('trace_id')
            ->paginate($perPage);
    }
}
