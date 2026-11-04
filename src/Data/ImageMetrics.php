<?php

declare(strict_types=1);

namespace Spectra\Data;

use Illuminate\Support\Arr;

/**
 * Images a request generated, plus the output megapixels for models billed
 * per megapixel.
 */
readonly class ImageMetrics extends DataTransferObject
{
    /**
     * Create a new image metrics instance.
     */
    public function __construct(
        public int $count = 0,
        /** Total output megapixels, for models billed per megapixel. */
        public ?float $megapixels = null,
    ) {}

    /**
     * Get the image usage as an array, leaving out the megapixels when not set.
     */
    public function toArray(): array
    {
        return Arr::whereNotNull(parent::toArray());
    }
}
