<?php

declare(strict_types=1);

namespace Spectra\Actions\Requests;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Concerns\FiltersRequests;
use Spectra\Support\DateRange;
use Spectra\Support\Pricing\PricingLookup;

/**
 * The page of requests the requests screen shows: its filters, search and
 * sort applied to a light column list (never the request/response payloads).
 */
class GetFilteredRequests
{
    use FiltersRequests;

    /**
     * Create a new get filtered requests action instance.
     */
    public function __construct(
        private readonly PricingLookup $pricingLookup,
    ) {}

    /**
     * Get the sorted page of requests for the filters, search and date range.
     *
     * @return LengthAwarePaginator<int, SpectraRequest>
     */
    public function __invoke(Request $request, DateRange $dateRange): LengthAwarePaginator
    {
        $sortable = ['created_at', 'total_tokens', 'total_cost_in_cents', 'latency_ms'];
        $sortBy = in_array($request->input('sort_by'), $sortable, true) ? $request->input('sort_by') : 'created_at';
        $sortDir = $request->input('sort_dir') === 'asc' ? 'asc' : 'desc';

        $query = SpectraRequest::query()
            ->select([
                'id', 'batch_id', 'trace_id', 'response_id',
                'provider', 'model', 'snapshot', 'model_type', 'endpoint',
                'trackable_type', 'trackable_id',
                'prompt_tokens', 'completion_tokens', 'reasoning_tokens',
                'duration_seconds', 'input_characters', 'image_count', 'video_count',
                'prompt_cost', 'completion_cost', 'total_cost_in_cents', 'pricing_tier',
                'latency_ms', 'time_to_first_token_ms', 'tokens_per_second',
                'is_reasoning', 'reasoning_effort', 'is_streaming',
                'finish_reason', 'has_tool_calls', 'tool_call_counts',
                'status_code', 'metadata',
                'created_at', 'completed_at', 'expires_at',
            ])
            ->with('tags')
            // total_tokens is an appended attribute, not a column.
            ->when(
                $sortBy === 'total_tokens',
                fn (Builder $query) => $query->orderByRaw("(prompt_tokens + completion_tokens) {$sortDir}"),
                fn (Builder $query) => $query->orderBy($sortBy, $sortDir),
            );
        $dateRange->apply($query);

        $this->applyFilters($query, $request);

        $perPage = min(max((int) $request->input('per_page', 25), 1), 100);

        return $query->paginate($perPage);
    }

    /**
     * Apply the model and id search and the request filters to the query.
     *
     * @param  Builder<SpectraRequest>  $query
     */
    private function applyFilters($query, Request $request): void
    {
        if ($request->filled('search')) {
            $search = $request->input('search');

            // Search model names and exact ids — deliberately NOT the
            // request/response longtext columns, which cannot use an index
            // and turn every keystroke into a full-table scan at volume.
            // id and trace_id are native uuid columns on PostgreSQL, which
            // errors on any other string, so only compare them with a UUID.
            $query->where(function (Builder $q) use ($search) {
                $q->where('model', 'like', "%{$search}%")
                    ->orWhere('response_id', $search)
                    ->when(Str::isUuid($search), fn (Builder $uuid) => $uuid
                        ->orWhere('id', $search)
                        ->orWhere('trace_id', $search));
            });
        }

        $this->applyRequestFilters($query, $request, $this->pricingLookup);
    }
}
