<?php

namespace Spectra\Data;

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
