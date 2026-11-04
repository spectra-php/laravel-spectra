<?php

declare(strict_types=1);

namespace Spectra\Data;

use Illuminate\Support\Arr;

/**
 * Audio usage of a request: the duration for per-second or per-minute billing
 * (speech-to-text, music) and the input characters for per-character
 * text-to-speech.
 */
readonly class AudioMetrics extends DataTransferObject
{
    /**
     * Create a new audio metrics instance.
     */
    public function __construct(
        public ?float $durationSeconds = null,
        public ?int $inputCharacters = null,
    ) {}

    /**
     * Get the audio usage as an array, leaving out the values the handler didn't set.
     */
    public function toArray(): array
    {
        return Arr::whereNotNull(parent::toArray());
    }
}
