<?php

declare(strict_types=1);

namespace Spectra\Data;

/**
 * Images a request generated, plus the output megapixels for models billed
 * per megapixel.
 */
readonly class ImageMetrics extends DataTransferObject
{
    public function __construct(
        public int $count = 0,
        /** Total output megapixels, for models billed per megapixel. */
        public ?float $megapixels = null,
    ) {}

    public function toArray(): array
    {
        return array_filter(parent::toArray(), fn ($value) => $value !== null);
    }
}
