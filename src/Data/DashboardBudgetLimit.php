<?php

declare(strict_types=1);

namespace Spectra\Data;

/**
 * One limit of a budget on a shared dashboard, with what has been used against
 * it: `metric` is cost (whole cents), tokens or requests, and `period` is
 * daily, weekly, monthly or total.
 */
readonly class DashboardBudgetLimit extends DataTransferObject
{
    /**
     * Create a new shared budget limit instance.
     */
    public function __construct(
        public string $metric,
        public string $period,
        public int $used,
        public int $limit,
    ) {}
}
