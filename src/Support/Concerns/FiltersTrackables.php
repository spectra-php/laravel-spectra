<?php

declare(strict_types=1);

namespace Spectra\Support\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Spectra\Models\SpectraRequest;

/**
 * The filters the trackables screen sends (provider, model, status, tags,
 * trace, search, ...), applied to a SpectraRequest query. Shared by the
 * trackables list, its summary and its latency breakdown so all three
 * describe the same rows.
 */
trait FiltersTrackables
{
    /**
     * Apply the trackables list filters from the dashboard query string.
     *
     * @param  Builder<SpectraRequest>  $query
     */
    protected function applyTrackableFilters(Builder $query, Request $request): void
    {
        if ($request->filled('trackable_type')) {
            $query->where('trackable_type', $request->input('trackable_type'));
        }

        // A list of types (the users screen's); an empty list matches nothing.
        if (is_array($request->input('trackable_types'))) {
            $query->whereIn('trackable_type', $request->input('trackable_types'));
        }

        if ($request->filled('provider')) {
            $query->where('provider', $request->input('provider'));
        }

        if ($request->filled('model')) {
            $query->where('model', 'like', "%{$request->input('model')}%");
        }

        if ($request->filled('status')) {
            $status = $request->input('status');
            if ($status === 'success') {
                $query->successful();
            } elseif ($status === 'error') {
                $query->failed();
            }
        }

        if ($request->filled('model_type')) {
            $query->where('model_type', $request->input('model_type'));
        }

        if ($request->filled('finish_reason')) {
            $query->where('finish_reason', $request->input('finish_reason'));
        }

        if ($request->filled('has_tool_calls')) {
            $query->where('has_tool_calls', $request->boolean('has_tool_calls'));
        }

        if ($request->filled('trace_id')) {
            $query->forTrace((string) $request->input('trace_id'));
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

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('trackable_id', 'like', "%{$search}%");
        }
    }
}
