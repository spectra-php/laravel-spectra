<?php

declare(strict_types=1);

namespace Spectra\Data;

/**
 * Videos a request generated, plus their duration for models billed per
 * second of video.
 */
readonly class VideoMetrics extends DataTransferObject
{
    public function __construct(
        public int $count = 0,
        public ?float $durationSeconds = null,
    ) {}

    public function toArray(): array
    {
        return array_filter(parent::toArray(), fn ($value) => $value !== null);
    }
}
