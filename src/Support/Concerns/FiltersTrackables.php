<?php

namespace Spectra\Support\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Spectra\Models\SpectraRequest;

trait FiltersTrackables
{
    /**
     * @param  Builder<SpectraRequest>  $query
     */
    protected function applyTrackableFilters(Builder $query, Request $request): void
    {
        if ($request->filled('type')) {
            $query->where('trackable_type', $request->input('type'));
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

        if ($request->filled('finish_reason')) {
            $query->where('finish_reason', $request->input('finish_reason'));
        }

        if ($request->filled('has_tool_calls')) {
            $query->where('has_tool_calls', filter_var($request->input('has_tool_calls'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('trace_id')) {
            $query->where('trace_id', $request->input('trace_id'));
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
