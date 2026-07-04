<?php

declare(strict_types=1);

namespace Spectra\Actions\Stats;

use Illuminate\Support\Collection;
use Spectra\Data\TopModel;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RequestAggregateQuery;

class GetTopModels
{
    /**
     * @return Collection<int, TopModel>
     */
    public function __invoke(DateRange $dateRange, string $layout, int $limit = 5): Collection
    {
        return TopModel::fromCollection(
            RequestAggregateQuery::make($dateRange)
                ->forLayout($layout)
                ->groupByColumns('model', 'provider')
                ->countAs()
                ->successfulCost()
                ->orderByDesc('count')
                ->limit($limit)
                ->get()
        );
    }
}
