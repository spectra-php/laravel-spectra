<?php

declare(strict_types=1);

namespace Spectra\Data;

/**
 * What a prune with the given retention would delete right now.
 */
readonly class PrunePreview extends DataTransferObject
{
    /**
     * Create a new prune preview instance.
     */
    public function __construct(
        public int $hours,
        public ?int $stats_days,
        public string $cutoff,
        public int $requests,
        public int $requests_with_media,
        public int $spans,
        public int $daily_stats,
        public int $scores = 0,
        public int $review_items = 0,
    ) {}
}
