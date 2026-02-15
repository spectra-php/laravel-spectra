<?php

namespace Spectra\Data\Responses;

use Illuminate\Support\Collection;
use Spectra\Data\DataTransferObject;

readonly class StatsResponse extends DataTransferObject
{
    /**
     * @param  array<string, float>  $cost_by_model_type
     * @param  \Illuminate\Support\Collection<int, mixed>  $top_models
     * @param  \Illuminate\Support\Collection<int, mixed>  $recent_requests
     * @param  \Illuminate\Support\Collection<int, mixed>  $requests_by_date
     * @param  \Illuminate\Support\Collection<int, mixed>  $requests_by_provider
     * @param  \Illuminate\Support\Collection<int, mixed>  $latency_by_model_type
     * @param  \Illuminate\Support\Collection<int, mixed>|null  $stats_by_model_type
     */
    public function __construct(
        public int $total_requests,
        public int $total_tokens,
        public float $total_cost_in_cents,
        public float $avg_latency,
        public int $total_images,
        public int $total_videos,
        public float $total_duration_seconds,
        public int $total_input_characters,
        public int $tts_characters,
        public float $tts_duration_seconds,
        public float $stt_duration_seconds,
        public array $cost_by_model_type,
        public Collection $top_models,
        public Collection $recent_requests,
        public Collection $requests_by_date,
        public Collection $requests_by_provider,
        public Collection $latency_by_model_type,
        public string $layout,
        public ?Collection $stats_by_model_type = null,
    ) {}
}
