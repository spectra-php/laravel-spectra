<?php

declare(strict_types=1);

namespace Spectra\Actions\Costs;

use Carbon\CarbonInterface;
use Spectra\Data\Responses\CostOverviewResponse;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RequestAggregateQuery;

/**
 * The costs screen's summary cards. They use fixed calendar periods (today,
 * this week, ...) whatever period the screen has selected.
 */
class GetCostOverview
{
    /**
     * Get the cost of today, this week, last week, this month and this year.
     */
    public function __invoke(): CostOverviewResponse
    {
        $startOfThisWeek = now()->startOfWeek();
        $startOfLastWeek = $startOfThisWeek->copy()->subWeek();
        $endOfLastWeek = $startOfThisWeek->copy()->subSecond();

        return new CostOverviewResponse(
            today: $this->costSince(today()),
            this_week: $this->costSince($startOfThisWeek),
            last_week: $this->costSince($startOfLastWeek, $endOfLastWeek),
            this_month: $this->costSince(now()->startOfMonth()),
            this_year: $this->costSince(now()->startOfYear()),
        );
    }

    /**
     * Get the cost of successful requests between the two moments.
     */
    protected function costSince(CarbonInterface $from, ?CarbonInterface $to = null): float
    {
        return RequestAggregateQuery::make(new DateRange($from, $to))
            ->successfulOnly()
            ->sumCost();
    }
}
