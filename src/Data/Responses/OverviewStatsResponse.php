<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Spectra\Data\DataTransferObject;

/**
 * The overview's headline totals for the period and layout: counts and usage
 * over every request, cost and latency over successful ones.
 */
readonly class OverviewStatsResponse extends DataTransferObject
{
    /**
     * Create a new overview stats response instance.
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
    ) {}
}
