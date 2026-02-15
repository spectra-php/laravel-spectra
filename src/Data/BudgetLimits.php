<?php

namespace Spectra\Data;

readonly class BudgetLimits extends DataTransferObject
{
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
