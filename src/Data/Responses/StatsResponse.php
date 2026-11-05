<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Illuminate\Support\Collection;
use Spectra\Data\DataTransferObject;
use Spectra\Data\ModelTypeCost;
use Spectra\Data\ModelTypeLatency;
use Spectra\Data\RequestByDate;
use Spectra\Data\RequestByProvider;
use Spectra\Data\TopModel;
use Spectra\Models\SpectraRequest;

/**
 * The overview screen: headline totals, cost and latency by model type, top
 * models, recent requests and the per-day and per-provider charts.
 */
readonly class StatsResponse extends DataTransferObject
{
    /**
     * Create a new stats response instance.
     *
     * @param  array<string, float>  $cost_by_model_type
     * @param  Collection<int, TopModel>  $top_models
     * @param  Collection<int, SpectraRequest>  $recent_requests
     * @param  Collection<int, RequestByDate>  $requests_by_date
     * @param  Collection<int, RequestByProvider>  $requests_by_provider
     * @param  Collection<int, ModelTypeLatency>  $latency_by_model_type
     * @param  Collection<int, ModelTypeCost>|null  $stats_by_model_type
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
