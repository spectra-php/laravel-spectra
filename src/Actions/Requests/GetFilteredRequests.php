<?php

namespace Spectra\Actions\Requests;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;
use Spectra\Support\Pricing\PricingLookup;

class GetFilteredRequests
{
    public function __construct(
        private readonly PricingLookup $pricingLookup,
    ) {}

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, SpectraRequest>
     */
    public function __invoke(Request $request, DateRange $dateRange): LengthAwarePaginator
    {
        $sortable = ['created_at', 'total_tokens', 'total_cost_in_cents', 'latency_ms'];
        $sortBy = in_array($request->input('sort_by'), $sortable) ? $request->input('sort_by') : 'created_at';
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
            ->orderBy($sortBy, $sortDir);
        $dateRange->apply($query);

        $this->applyFilters($query, $request);

        return $query->paginate($request->input('per_page', 25));
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<SpectraRequest>  $query
     */
    private function applyFilters($query, Request $request): void
    {
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('response', 'like', "%{$search}%");
        }

        if ($request->filled('provider')) {
            $query->where('provider', $request->input('provider'));
        }

        if ($request->filled('model')) {
            $query->where('model', 'like', '%'.$request->input('model').'%');
        }

        if ($request->filled('status')) {
            $status = $request->input('status');
            if ($status === 'success') {
                $query->whereBetween('status_code', [200, 299]);
            } elseif ($status === 'error') {
                $query->where(function ($q) {
                    $q->where('status_code', '>=', 400)
                        ->orWhereNull('status_code');
                });
            }
        }

        if ($request->filled('model_type')) {
            $query->where('model_type', $request->input('model_type'));
        }

        if ($request->filled('capability')) {
            $modelNames = match ($request->input('capability')) {
                'text' => $this->pricingLookup->canGenerateText(),
                'images' => $this->pricingLookup->canGenerateImages(),
                'video' => $this->pricingLookup->canGenerateVideo(),
                'audio' => $this->pricingLookup->canGenerateAudio(),
                default => [],
            };
            $query->whereIn('model', $modelNames);
        }

        if ($request->filled('finish_reason')) {
            $query->where('finish_reason', $request->input('finish_reason'));
        }

        if ($request->filled('has_tool_calls')) {
            $query->where('has_tool_calls', filter_var($request->input('has_tool_calls'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('is_reasoning')) {
            $query->where('is_reasoning', filter_var($request->input('is_reasoning'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('reasoning_effort')) {
            $query->where('reasoning_effort', $request->input('reasoning_effort'));
        }

        if ($request->filled('trace_id')) {
            $query->where('trace_id', $request->input('trace_id'));
        }

        if ($request->filled('trackable_type')) {
            $query->where('trackable_type', $request->input('trackable_type'));
        }

        if ($request->filled('trackable_id')) {
            $query->where('trackable_id', $request->input('trackable_id'));
        }

        if ($request->filled('tag')) {
            $query->withTag($request->input('tag'));
        }

        if ($request->filled('tags')) {
            $tags = is_array($request->input('tags'))
                ? $request->input('tags')
                : explode(',', $request->input('tags'));
            $query->withAnyTags($tags);
        }
    }
}
