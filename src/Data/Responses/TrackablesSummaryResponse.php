<?php

namespace Spectra\Data\Responses;

use Spectra\Data\DataTransferObject;

readonly class TrackablesSummaryResponse extends DataTransferObject
{
    public function __construct(
        public int $total_trackables,
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
