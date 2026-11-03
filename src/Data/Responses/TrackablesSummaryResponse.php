<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Spectra\Data\DataTransferObject;

/**
 * The totals strip above the trackables list: matching trackables and their
 * combined usage, with cost and latency over successful requests.
 */
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
