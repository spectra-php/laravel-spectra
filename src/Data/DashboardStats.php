<?php

declare(strict_types=1);

namespace Spectra\Data;

/**
 * The headline numbers on a shared dashboard. The cost is null on a dashboard
 * that hides costs. The trend is the requests per day, for the card's sparkline,
 * and the cost per model type is null on a dashboard that hides costs.
 */
readonly class DashboardStats extends DataTransferObject
{
    /**
     * Create a new shared stats instance.
     *
     * @param  array<int, int>  $request_trend
     * @param  array<int, array{model_type: string, label: string, count: int, avg_latency: float}>  $latency_by_model_type
     * @param  array<string, float>|null  $cost_by_model_type
     */
    public function __construct(
        public int $total_requests,
        public int $total_tokens,
        public int $total_images,
        public int $total_videos,
        public int $total_tts_characters,
        public float $stt_duration_seconds,
        public float $avg_latency,
        public ?float $total_cost,
        public array $request_trend,
        public array $latency_by_model_type,
        public ?array $cost_by_model_type,
    ) {}
}
