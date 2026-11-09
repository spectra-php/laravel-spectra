<?php

declare(strict_types=1);

namespace Spectra\Actions\Costs;

use Illuminate\Support\Collection;
use Spectra\Data\CostByModel;
use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;
use Spectra\Support\Pricing\PricingLookup;
use Spectra\Support\Query\RequestAggregateQuery;

/**
 * Requests, usage and cost per model for the costs screen, with each model's
 * catalog capabilities; counts and usage include failures, cost covers
 * successful requests.
 */
class GetCostsByModel
{
    /**
     * Create a new get costs by model action instance.
     */
    public function __construct(
        private readonly PricingLookup $pricingLookup,
    ) {}

    /**
     * Get the requests, usage, cost and capabilities of the most expensive models.
     * Narrowed to one model when `$model` (and `$provider`) is given.
     *
     * @return Collection<int, CostByModel>
     */
    public function __invoke(DateRange $dateRange, int $limit = 10, ?string $model = null, ?string $provider = null): Collection
    {
        return RequestAggregateQuery::fromRollups($dateRange)
            ->forModel($model, $provider)
            ->groupByColumns('model', 'provider', 'model_type')
            ->countAs('requests')
            ->tokenSum()
            ->usageSums()
            ->successfulCost()
            ->orderByDesc('cost')
            ->orderBy('model')
            ->orderBy('provider')
            ->limit($limit)
            ->get()
            ->map(fn (SpectraRequest $item) => new CostByModel(
                model: $item->model,
                provider: $item->provider,
                model_type: $item->model_type ?? 'text',
                requests: $item->requests,
                tokens: $item->tokens_sum,
                images: $item->images_sum,
                videos: $item->videos_sum,
                input_characters: $item->input_characters_sum,
                duration_seconds: $item->duration_seconds_sum,
                cost: $item->cost,
                capabilities: $this->pricingLookup->getCapabilities($item->provider ?? '', $item->model),
            ));
    }
}
