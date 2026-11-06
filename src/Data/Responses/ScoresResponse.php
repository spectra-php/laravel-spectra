<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Illuminate\Support\Collection;
use Spectra\Data\DataTransferObject;
use Spectra\Data\ScoreSummary;

/**
 * The overview's Scores card: one row per score name given in the period.
 */
readonly class ScoresResponse extends DataTransferObject
{
    /**
     * Create a new scores response instance.
     *
     * @param  Collection<int, ScoreSummary>  $scores
     */
    public function __construct(
        public Collection $scores,
    ) {}
}
