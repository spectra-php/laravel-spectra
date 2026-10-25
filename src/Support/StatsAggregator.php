<?php

declare(strict_types=1);

namespace Spectra\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Spectra\Models\SpectraDailyStat;
use Spectra\Models\SpectraRequest;

/**
 * Keeps the pre-aggregated spectra_daily_stats rows in step with
 * spectra_requests: one global row and, when the request has a trackable, one
 * row for that trackable, per day / provider / model / model type.
 */
class StatsAggregator
{
    public function recordRequest(SpectraRequest $request): void
    {
        $this->apply($request, $this->amounts($request), $request->latency_ms);
    }

    /**
     * Add only what changed when an existing request row was updated (e.g. an
     * async job polled again, or a queued job that finished).
     */
    public function recordChange(SpectraRequest $current, SpectraRequest $previous): void
    {
        $now = $this->amounts($current);
        $before = $this->amounts($previous);

        $delta = [];
        foreach ($now as $column => $value) {
            $change = $value - ($before[$column] ?? 0);

            // Usage only grows as a job progresses; a smaller number means a
            // poll returned less detail, not that usage went away. Only the
            // success/failure split may move in both directions.
            $delta[$column] = in_array($column, ['successful_count', 'failed_count'], true) ? $change : max(0, $change);
        }

        // Still one request; only its outcome may have changed.
        $delta['request_count'] = 0;

        $latency = $current->latency_ms !== null && $current->latency_ms !== $previous->latency_ms
            ? $current->latency_ms
            : null;

        $this->apply($current, $delta, $latency);
    }

    /**
     * @return array<string, int|float>
     */
    protected function amounts(SpectraRequest $request): array
    {
        $successful = $request->isSuccessful();
        $promptTokens = (int) ($request->prompt_tokens ?? 0);
        $completionTokens = (int) ($request->completion_tokens ?? 0);

        return [
            'request_count' => 1,
            'successful_count' => $successful ? 1 : 0,
            'failed_count' => $successful ? 0 : 1,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $promptTokens + $completionTokens,
            'total_cached_tokens' => (int) ($request->cached_tokens ?? 0),
            'total_cache_write_tokens' => (int) ($request->cache_write_tokens ?? 0),
            'total_reasoning_tokens' => (int) ($request->reasoning_tokens ?? 0),
            'total_images' => (int) ($request->image_count ?? 0),
            'total_videos' => (int) ($request->video_count ?? 0),
            'total_pages' => (int) ($request->page_count ?? 0),
            'total_duration_seconds' => (float) ($request->duration_seconds ?? 0),
            'total_input_characters' => (int) ($request->input_characters ?? 0),
            'total_cost_in_cents' => (float) ($request->total_cost_in_cents ?? 0),
            'total_latency_ms' => (int) ($request->latency_ms ?? 0),
        ];
    }

    /**
     * @param  array<string, int|float>  $amounts
     */
    protected function apply(SpectraRequest $request, array $amounts, ?int $latencyMs): void
    {
        $date = $request->created_at?->format('Y-m-d') ?? now()->format('Y-m-d');

        $rows = [[null, null]];

        if ($request->trackable_type && $request->trackable_id !== null) {
            $rows[] = [$request->trackable_type, $request->trackable_id];
        }

        foreach ($rows as [$trackableType, $trackableId]) {
            SpectraDailyStat::accumulate(
                date: $date,
                provider: $request->provider ?? 'unknown',
                model: $request->model,
                modelType: $request->model_type,
                trackableType: $trackableType,
                trackableId: $trackableId,
                increments: $amounts,
                latencyMs: $latencyMs,
            );
        }
    }

    /**
     * Useful for backfilling or fixing stat inconsistencies.
     */
    public function rebuild(?CarbonInterface $startDate = null, ?CarbonInterface $endDate = null): int
    {
        $query = SpectraDailyStat::query();

        if ($startDate) {
            $query->where('date', '>=', $startDate->format('Y-m-d'));
        }

        if ($endDate) {
            $query->where('date', '<=', $endDate->format('Y-m-d'));
        }

        $query->delete();

        $requestQuery = SpectraRequest::query();

        if ($startDate) {
            $requestQuery->where('created_at', '>=', $startDate->copy()->startOfDay());
        }

        if ($endDate) {
            $requestQuery->where('created_at', '<=', $endDate->copy()->endOfDay());
        }

        $count = 0;
        $requestQuery->orderBy('id')->chunk(1000, function ($requests) use (&$count) {
            foreach ($requests as $request) {
                $this->recordRequest($request);
                $count++;
            }
        });

        return $count;
    }

    public function rebuildForDate(CarbonInterface|string $date): int
    {
        $date = $date instanceof CarbonInterface ? $date : Carbon::parse($date);

        return $this->rebuild($date->copy()->startOfDay(), $date->copy()->endOfDay());
    }

    /**
     * Rebuild stats for yesterday (useful for scheduled task).
     */
    public function rebuildYesterday(): int
    {
        return $this->rebuildForDate(now()->subDay());
    }
}
