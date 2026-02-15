<?php

namespace Spectra\Data\Responses;

use Spectra\Data\DataTransferObject;

readonly class CostOverviewResponse extends DataTransferObject
{
    public function __construct(
        public float $today,
        public float $this_week,
        public float $last_week,
        public float $this_month,
        public float $this_year,
    ) {}
}
