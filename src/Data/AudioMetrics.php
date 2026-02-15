<?php

namespace Spectra\Data;

readonly class AudioMetrics extends DataTransferObject
{
    public function __construct(
        public ?float $durationSeconds = null,
        public ?int $inputCharacters = null,
    ) {}

    public function toArray(): array
    {
        return array_filter(parent::toArray(), fn ($value) => $value !== null);
    }
}
