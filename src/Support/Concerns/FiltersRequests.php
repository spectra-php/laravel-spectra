<?php

declare(strict_types=1);

namespace Spectra\Support\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Pricing\PricingLookup;

/**
 * The filters the requests screen sends (provider, model, status, tags,
 * trace, conversation, trackable, ...), applied to a SpectraRequest query. Shared by the
 * requests list and the traces list, which keeps the traces that hold a
 * matching call. Search is left to each screen: they search different
 * columns.
 */
trait FiltersRequests
{
    /** Every query parameter applyRequestFilters() reads. */
    protected const REQUEST_FILTER_KEYS = [
        'provider', 'model', 'status', 'model_type', 'capability', 'finish_reason', 'has_tool_calls',
        'is_reasoning', 'reasoning_effort', 'trace_id', 'conversation_id', 'prompt_name', 'prompt_version', 'error_fingerprint', 'trackable_type', 'trackable_id', 'tag', 'tags',
    ];

    /**
     * Determine if the request sets any of the filters applyRequestFilters() reads.
     */
    protected function hasRequestFilters(Request $request): bool
    {
        return $request->anyFilled(self::REQUEST_FILTER_KEYS);
    }

    /**
     * Apply the requests list filters from the dashboard query string.
     *
     * @param  Builder<SpectraRequest>  $query
     */
    protected function applyRequestFilters(Builder $query, Request $request, PricingLookup $pricingLookup): void
    {
        if ($request->filled('provider')) {
            $query->where('provider', $request->input('provider'));
        }

        if ($request->filled('model')) {
            $query->where('model', 'like', "%{$request->input('model')}%");
        }

        if ($request->filled('status')) {
            $status = $request->input('status');
            if ($status === 'success') {
                $query->whereBetween('status_code', [200, 299]);
            } elseif ($status === 'error') {
                $query->failed();
            }
        }

        if ($request->filled('model_type')) {
            $query->where('model_type', $request->input('model_type'));
        }

        if ($request->filled('capability')) {
            $modelNames = match ($request->input('capability')) {
                'text' => $pricingLookup->canGenerateText(),
                'images' => $pricingLookup->canGenerateImages(),
                'video' => $pricingLookup->canGenerateVideo(),
                'audio' => $pricingLookup->canGenerateAudio(),
                default => [],
            };
            $query->whereIn('model', $modelNames);
        }

        if ($request->filled('finish_reason')) {
            $query->where('finish_reason', $request->input('finish_reason'));
        }

        if ($request->filled('has_tool_calls')) {
            $query->where('has_tool_calls', $request->boolean('has_tool_calls'));
        }

        if ($request->filled('is_reasoning')) {
            $query->where('is_reasoning', $request->boolean('is_reasoning'));
        }

        if ($request->filled('reasoning_effort')) {
            $query->where('reasoning_effort', $request->input('reasoning_effort'));
        }

        if ($request->filled('trace_id')) {
            $query->forTrace((string) $request->input('trace_id'));
        }

        if ($request->filled('conversation_id')) {
            $query->where('conversation_id', $request->input('conversation_id'));
        }

        if ($request->filled('prompt_name')) {
            $query->where('prompt_name', $request->input('prompt_name'));
        }

        if ($request->filled('prompt_version')) {
            $query->where('prompt_version', $request->input('prompt_version'));
        }

        if ($request->filled('error_fingerprint')) {
            $query->where('error_fingerprint', $request->input('error_fingerprint'));
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
