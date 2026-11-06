<?php

declare(strict_types=1);

namespace Spectra\Actions\Dashboards;

use Spectra\Data\DashboardStats;
use Spectra\Enums\ModelType;
use Spectra\Support\Dashboards\DashboardScope;
use Spectra\Support\DateRange;
use stdClass;

/**
 * The headline numbers on a shared dashboard: counts and usage over every call,
 * cost and latency over successful ones. Costs are left out on a dashboard that
 * hides them.
 */
class GetDashboardStats
{
    /**
     * Get the trackable's totals, daily trend and per-type figures in the date range.
     */
    public function __invoke(DashboardScope $scope, DateRange $dateRange, bool $showCosts): DashboardStats
    {
        /** @var stdClass $totals */
        $totals = $scope->calls($dateRange)->toBase()
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw(DashboardScope::TOKENS_SQL.' as tokens')
            ->selectRaw('COALESCE(SUM(image_count), 0) as images')
            ->selectRaw('COALESCE(SUM(video_count), 0) as videos')
            ->selectRaw("COALESCE(SUM(CASE WHEN model_type = 'tts' THEN input_characters ELSE 0 END), 0) as tts_characters")
            ->selectRaw("COALESCE(SUM(CASE WHEN model_type = 'stt' THEN duration_seconds ELSE 0 END), 0) as stt_seconds")
            ->selectRaw(DashboardScope::COST_SQL.' as cost')
            ->selectRaw(DashboardScope::LATENCY_SQL.' as latency')
            ->first();

        $trend = $scope->calls($dateRange)->toBase()
            ->selectRaw('DATE(created_at) as day, COUNT(*) as requests')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn (stdClass $row) => (int) $row->requests)
            ->all();

        $byType = $scope->calls($dateRange)->toBase()
            ->select('model_type')
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw(DashboardScope::COST_SQL.' as cost')
            ->selectRaw(DashboardScope::LATENCY_SQL.' as latency')
            ->groupBy('model_type')
            ->get();

        return new DashboardStats(
            total_requests: (int) $totals->requests,
            total_tokens: (int) $totals->tokens,
            total_images: (int) $totals->images,
            total_videos: (int) $totals->videos,
            total_tts_characters: (int) $totals->tts_characters,
            stt_duration_seconds: (float) $totals->stt_seconds,
            avg_latency: (float) ($totals->latency ?? 0),
            total_cost: $showCosts ? (float) $totals->cost : null,
            request_trend: $trend,
            latency_by_model_type: $byType->map(fn (stdClass $row) => [
                'model_type' => (string) ($row->model_type ?? 'unknown'),
                'label' => $this->label($row->model_type),
                'count' => (int) $row->requests,
                'avg_latency' => (float) ($row->latency ?? 0),
            ])->values()->all(),
            cost_by_model_type: $showCosts
                ? $byType->mapWithKeys(fn (stdClass $row) => [(string) ($row->model_type ?? 'unknown') => (float) $row->cost])->all()
                : null,
        );
    }

    /**
     * Get a model type's display label.
     */
    private function label(mixed $type): string
    {
        return is_string($type) && filled($type) ? (ModelType::tryFrom($type)?->label() ?? $type) : 'Unknown';
    }
}
