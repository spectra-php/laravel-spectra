<?php

declare(strict_types=1);

namespace Spectra\Data;

use Illuminate\Support\Arr;

/**
 * Videos a request generated, plus their duration for models billed per
 * second of video.
 */
readonly class VideoMetrics extends DataTransferObject
{
    /**
     * Create a new video metrics instance.
     */
    public function __construct(
        public int $count = 0,
        public ?float $durationSeconds = null,
    ) {}

    /**
     * Get the video usage as an array, leaving out the duration when not set.
     */
    public function toArray(): array
    {
        return Arr::whereNotNull(parent::toArray());
    }
}
