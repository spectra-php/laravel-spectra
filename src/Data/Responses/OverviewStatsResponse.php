<?php

namespace Spectra\Data\Responses;

use Spectra\Data\DataTransferObject;

readonly class OverviewStatsResponse extends DataTransferObject
{
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
