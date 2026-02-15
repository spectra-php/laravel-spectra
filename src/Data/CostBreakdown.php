<?php

namespace Spectra\Data;

readonly class CostBreakdown extends DataTransferObject
{
    public function __construct(
        public int $requestCount = 0,
        public float $promptCostInCents = 0,
        public float $promptCost = 0,
        public float $completionCostInCents = 0,
        public float $completionCost = 0,
        public float $totalCostInCents = 0,
        public float $totalCost = 0,
        public float $avgCostInCents = 0,
        public float $avgCost = 0,
    ) {}

}
