<?php

namespace Spectra\Support;

use Illuminate\Support\Carbon;
use Spectra\Models\SpectraDailyStat;
use Spectra\Models\SpectraRequest;

class StatsAggregator
{
    public function recordRequest(SpectraRequest $request): void
    {
        $date = $request->created_at?->format('Y-m-d') ?? now()->format('Y-m-d');
        $successful = $request->isSuccessful();

        SpectraDailyStat::record(
            date: $date,
            provider: $request->provider ?? 'unknown',
            model: $request->model,
            trackableType: null,
            trackableId: null,
            successful: $successful,
            promptTokens: $request->prompt_tokens ?? 0,
            completionTokens: $request->completion_tokens ?? 0,
            cost: $request->total_cost_in_cents ?? 0,
            latencyMs: $request->latency_ms,
            modelType: $request->model_type,
            imageCount: $request->image_count ?? 0,
            videoCount: $request->video_count ?? 0,
            durationSeconds: (float) ($request->duration_seconds ?? 0),
            inputCharacters: $request->input_characters ?? 0,
            reasoningTokens: $request->reasoning_tokens ?? 0
        );

        if ($request->trackable_type && $request->trackable_id) {
            SpectraDailyStat::record(
                date: $date,
                provider: $request->provider ?? 'unknown',
                model: $request->model,
                trackableType: $request->trackable_type,
                trackableId: $request->trackable_id,
                successful: $successful,
                promptTokens: $request->prompt_tokens ?? 0,
                completionTokens: $request->completion_tokens ?? 0,
                cost: $request->total_cost_in_cents ?? 0,
                latencyMs: $request->latency_ms,
                modelType: $request->model_type,
                imageCount: $request->image_count ?? 0,
                videoCount: $request->video_count ?? 0,
                durationSeconds: (float) ($request->duration_seconds ?? 0),
                inputCharacters: $request->input_characters ?? 0,
                reasoningTokens: $request->reasoning_tokens ?? 0
            );
        }
    }

    /**
     * Useful for backfilling or fixing stat inconsistencies.
     */
    public function rebuild(?Carbon $startDate = null, ?Carbon $endDate = null): int
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
            $requestQuery->where('created_at', '>=', $startDate->startOfDay());
        }

        if ($endDate) {
            $requestQuery->where('created_at', '<=', $endDate->endOfDay());
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

    public function rebuildForDate(Carbon|string $date): int
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);

        return $this->rebuild($date->copy()->startOfDay(), $date->copy()->endOfDay());
    }

    /**
     * Rebuild stats for yesterday (useful for scheduled task).
     */
    public function rebuildYesterday(): int
    {
        return $this->rebuildForDate(now()->subDay());
    }

    /**
     * @return array<string, mixed>
     */
    public function getGlobalStats(Carbon|string $start, Carbon|string $end): array
    {
        return SpectraDailyStat::getAggregatedStats($start, $end);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Model  $trackable
     * @return array<string, mixed>
     */
    public function getTrackableStats(
        $trackable,
        Carbon|string $start,
        Carbon|string $end
    ): array {
        return SpectraDailyStat::getAggregatedStats($start, $end, trackable: $trackable);
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getDailyBreakdown(
        Carbon|string $start,
        Carbon|string $end,
        ?string $provider = null
    ): array {
        return SpectraDailyStat::getDailyBreakdown($start, $end, $provider);
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getStatsByProvider(Carbon|string $start, Carbon|string $end): array
    {
        return SpectraDailyStat::getStatsByProvider($start, $end);
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getStatsByModel(
        Carbon|string $start,
        Carbon|string $end,
        ?string $provider = null
    ): array {
        return SpectraDailyStat::getStatsByModel($start, $end, $provider);
    }
}
