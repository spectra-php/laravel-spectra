<?php

declare(strict_types=1);

namespace Spectra\Actions\Costs;

use Illuminate\Support\Collection;
use Spectra\Actions\Trackables\GetUserTrackableTypes;
use Spectra\Actions\Trackables\ResolveTrackableMetadata;
use Spectra\Data\CostByUser;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RequestAggregateQuery;

/**
 * The costs screen's top users: cost per user over successful requests, with
 * request counts that include failures, enriched with each user's name.
 */
class GetCostsByUser
{
    /**
     * Create a new get costs by user action instance.
     */
    public function __construct(
        private readonly GetUserTrackableTypes $getUserTrackableTypes,
        private readonly ResolveTrackableMetadata $resolveMetadata,
    ) {}

    /**
     * Get the users that spent the most in the date range, with their names.
     * Narrowed to one model when `$model` (and `$provider`) is given.
     *
     * @return Collection<int, CostByUser>
     */
    public function __invoke(DateRange $dateRange, int $limit = 10, ?string $model = null, ?string $provider = null): Collection
    {
        // Group by type + id: ids alone collide across polymorphic types
        // (User 1 and Admin 1 must not be merged into one row).
        $items = RequestAggregateQuery::fromRollups($dateRange)
            ->forModel($model, $provider)
            ->withTrackable()
            ->forTrackableTypes(($this->getUserTrackableTypes)())
            ->groupByColumns('trackable_type', 'trackable_id')
            ->countAs('requests')
            ->successfulCost()
            ->orderByDesc('cost')
            ->orderBy('trackable_type')
            ->orderBy('trackable_id')
            ->limit($limit)
            ->get();

        return CostByUser::fromCollection($items, ($this->resolveMetadata)($items));
    }
}
