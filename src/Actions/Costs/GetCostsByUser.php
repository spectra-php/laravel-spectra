<?php

declare(strict_types=1);

namespace Spectra\Actions\Costs;

use Illuminate\Support\Collection;
use Spectra\Actions\Trackables\ResolveTrackableMetadata;
use Spectra\Data\CostByUser;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RequestAggregateQuery;

class GetCostsByUser
{
    public function __construct(
        private readonly ResolveTrackableMetadata $resolveMetadata,
    ) {}

    /**
     * @return Collection<int, CostByUser>
     */
    public function __invoke(DateRange $dateRange, int $limit = 10): Collection
    {
        // Group by type + id: ids alone collide across polymorphic types
        // (User 1 and Team 1 must not be merged into one row).
        $items = RequestAggregateQuery::make($dateRange)
            ->successfulOnly()
            ->withTrackable()
            ->groupByColumns('trackable_type', 'trackable_id')
            ->countAs('requests')
            ->selectRaw('SUM(total_cost_in_cents) as cost')
            ->orderByDesc('cost')
            ->limit($limit)
            ->get();

        return CostByUser::fromCollection($items, ($this->resolveMetadata)($items));
    }
}
