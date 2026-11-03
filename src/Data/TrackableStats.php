<?php

declare(strict_types=1);

namespace Spectra\Data;

/**
 * The headline numbers for one trackable on its detail screen: counts and
 * usage over every request, cost and latency over successful ones.
 */
readonly class TrackableStats extends DataTransferObject
{
    public function __construct(
        public int $total_requests,
        public int $total_tokens,
        public int $total_images,
        public int $total_videos,
        public int $total_tts_characters,
        public float $tts_duration_seconds,
        public float $stt_duration_seconds,
        public float $total_cost,
        public float $avg_latency,
    ) {}
}
