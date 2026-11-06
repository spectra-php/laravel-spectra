<?php

declare(strict_types=1);

namespace Spectra\Actions\Dashboards;

use Illuminate\Support\Collection;
use Spectra\Data\DashboardRequest;
use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;

/**
 * A trackable's latest calls for a shared dashboard. The columns are a fixed
 * list, so a prompt, a response, metadata or a trace can never reach a viewer.
 */
class GetDashboardRecentRequests
{
    /**
     * Get the trackable's latest calls in the date range, newest first.
     *
     * @return Collection<int, DashboardRequest>
     */
    public function __invoke(string $type, string $id, DateRange $dateRange): Collection
    {
        return SpectraRequest::query()
            ->select([
                'provider', 'model', 'model_type', 'prompt_tokens', 'completion_tokens',
                'image_count', 'video_count', 'input_characters', 'duration_seconds',
                'status_code', 'error_type', 'created_at',
            ])
            ->where('trackable_type', $type)
            ->where('trackable_id', $id)
            ->tap(fn ($query) => $dateRange->apply($query))
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(fn (SpectraRequest $request) => new DashboardRequest(
                provider: $request->provider,
                provider_display_name: $request->provider_display_name,
                model: $request->model,
                model_type: $request->model_type,
                prompt_tokens: $request->prompt_tokens,
                completion_tokens: $request->completion_tokens,
                image_count: $request->image_count ?? 0,
                video_count: $request->video_count ?? 0,
                input_characters: $request->input_characters ?? 0,
                duration_seconds: $request->duration_seconds ?? 0.0,
                status_code: $request->status_code,
                failed: $request->isFailed(),
                created_at_human: $request->created_at_human,
            ))
            ->values();
    }
}
