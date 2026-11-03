<?php

declare(strict_types=1);

namespace Spectra\Data;

/**
 * Audio usage of a request: the duration for per-second or per-minute billing
 * (speech-to-text, music) and the input characters for per-character
 * text-to-speech.
 */
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
