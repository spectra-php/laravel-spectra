<?php

namespace Spectra\Data;

readonly class Metrics extends DataTransferObject
{
    public function __construct(
        public ?TokenMetrics $tokens = null,
        public ?ImageMetrics $image = null,
        public ?AudioMetrics $audio = null,
        public ?VideoMetrics $video = null,
    ) {}

    public function toArray(): array
    {
        return array_filter(
            array_map(
                fn ($value) => $value instanceof DataTransferObject ? $value->toArray() : $value,
                parent::toArray(),
            ),
            fn ($value) => $value !== null,
        );
    }
}
