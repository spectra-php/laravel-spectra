<?php

declare(strict_types=1);

namespace Spectra\Data;

/**
 * The headline numbers on a shared dashboard. The cost is null on a dashboard
 * that hides costs.
 */
readonly class DashboardStats extends DataTransferObject
{
    /**
     * Create a new shared stats instance.
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
    ) {}
}
