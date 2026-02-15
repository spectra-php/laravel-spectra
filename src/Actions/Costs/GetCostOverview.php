<?php

namespace Spectra\Actions\Costs;

use Spectra\Data\Responses\CostOverviewResponse;
use Spectra\Queries\CostOverviewQuery;

class GetCostOverview
{
    public function __construct(
        private readonly CostOverviewQuery $query,
    ) {}

    public function __invoke(): CostOverviewResponse
    {
        $startOfThisWeek = now()->startOfWeek();
        $startOfLastWeek = $startOfThisWeek->copy()->subWeek();
        $endOfLastWeek = $startOfThisWeek->copy()->subSecond();

        return new CostOverviewResponse(
            today: ($this->query)(now()->startOfDay()),
            this_week: ($this->query)($startOfThisWeek),
            last_week: ($this->query)($startOfLastWeek, $endOfLastWeek),
            this_month: ($this->query)(now()->startOfMonth()),
            this_year: ($this->query)(now()->startOfYear()),
        );
    }
}
