<?php

declare(strict_types=1);

namespace Spectra\Data;

/**
 * The limits of a budget as set: spend in whole cents, tokens and requests
 * per period. Null means no limit for that period.
 */
readonly class BudgetLimits extends DataTransferObject
{
    /**
     * Create a new budget limits instance.
     */
    public function __construct(
        public ?int $dailyLimit = null,
        public ?int $weeklyLimit = null,
        public ?int $monthlyLimit = null,
        public ?int $totalLimit = null,
        public ?int $dailyTokenLimit = null,
        public ?int $weeklyTokenLimit = null,
        public ?int $monthlyTokenLimit = null,
        public ?int $totalTokenLimit = null,
        public ?int $dailyRequestLimit = null,
        public ?int $weeklyRequestLimit = null,
        public ?int $monthlyRequestLimit = null,
    ) {}

}
