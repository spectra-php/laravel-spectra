<?php

declare(strict_types=1);

namespace Spectra\Data;

/**
 * What one prune deleted.
 */
readonly class PruneResult extends DataTransferObject
{
    /**
     * Create a new prune result instance.
     */
    public function __construct(
        public int $requests,
        public int $spans,
        public int $media_files,
        public int $daily_stats,
        public int $scores = 0,
        public int $review_items = 0,
        public int $payloads = 0,
    ) {}
}
