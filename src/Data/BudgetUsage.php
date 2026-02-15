<?php

namespace Spectra\Data;

readonly class BudgetUsage extends DataTransferObject
{
    public function __construct(
        public int $dailyCost = 0,
        public int $weeklyCost = 0,
        public int $monthlyCost = 0,
        public int $totalCostInCents = 0,
        public int $dailyTokens = 0,
        public int $weeklyTokens = 0,
        public int $monthlyTokens = 0,
        public int $totalTokens = 0,
        public int $dailyRequests = 0,
        public int $weeklyRequests = 0,
        public int $monthlyRequests = 0,
    ) {}

}
