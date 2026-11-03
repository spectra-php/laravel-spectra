<?php

declare(strict_types=1);

namespace Spectra\Data;

/**
 * How many cents a budgetable model may still spend in each budget period;
 * null where that period has no limit.
 */
readonly class RemainingBudget extends DataTransferObject
{
    public function __construct(
        public ?int $daily = null,
        public ?int $weekly = null,
        public ?int $monthly = null,
        public ?int $total = null,
    ) {}

}
