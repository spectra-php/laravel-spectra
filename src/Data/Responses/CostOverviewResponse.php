<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Spectra\Data\DataTransferObject;

/**
 * The costs screen's summary cards: spend today, this week, last week, this
 * month and this year, in cents over successful requests.
 */
readonly class CostOverviewResponse extends DataTransferObject
{
    /**
     * Create a new cost overview response instance.
     */
    public function __construct(
        public float $today,
        public float $this_week,
        public float $last_week,
        public float $this_month,
        public float $this_year,
    ) {}
}
