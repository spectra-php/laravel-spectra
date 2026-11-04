<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Illuminate\Support\Collection;
use Spectra\Data\DataTransferObject;
use Spectra\Data\ModelPerformance;

/**
 * The overview's Performance card: error rate and latency percentiles of the
 * most used models in the period.
 */
readonly class PerformanceResponse extends DataTransferObject
{
    /**
     * Create a new performance response instance.
     *
     * @param  Collection<int, ModelPerformance>  $models
     */
    public function __construct(
        public Collection $models,
    ) {}
}
