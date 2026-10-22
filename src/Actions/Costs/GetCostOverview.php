<?php

declare(strict_types=1);

namespace Spectra\Actions\Costs;

use Carbon\CarbonInterface;
use Spectra\Data\Responses\CostOverviewResponse;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RequestAggregateQuery;

class GetCostOverview
{
    public function __invoke(): CostOverviewResponse
    {
        $startOfThisWeek = now()->startOfWeek();
        $startOfLastWeek = $startOfThisWeek->copy()->subWeek();
        $endOfLastWeek = $startOfThisWeek->copy()->subSecond();

        return new CostOverviewResponse(
            today: $this->costSince(now()->startOfDay()),
            this_week: $this->costSince($startOfThisWeek),
            last_week: $this->costSince($startOfLastWeek, $endOfLastWeek),
            this_month: $this->costSince(now()->startOfMonth()),
            this_year: $this->costSince(now()->startOfYear()),
        );
    }

    protected function costSince(CarbonInterface $from, ?CarbonInterface $to = null): float
    {
        return RequestAggregateQuery::make(new DateRange($from, $to))
            ->successfulOnly()
            ->sumCost();
    }
}
