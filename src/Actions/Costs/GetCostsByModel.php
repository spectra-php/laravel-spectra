<?php

declare(strict_types=1);

namespace Spectra\Actions\Costs;

use Illuminate\Support\Collection;
use Spectra\Data\CostByModel;
use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;
use Spectra\Support\Pricing\PricingLookup;
use Spectra\Support\Query\RequestAggregateQuery;

class GetCostsByModel
{
    public function __construct(
        private readonly PricingLookup $pricingLookup,
    ) {}

    /**
     * @return Collection<int, CostByModel>
     */
    public function __invoke(DateRange $dateRange, int $limit = 10): Collection
    {
        return RequestAggregateQuery::make($dateRange)
            ->successfulOnly()
            ->groupByColumns('model', 'provider', 'model_type')
            ->countAs('requests')
            ->tokenSum()
            ->usageSums()
            ->selectRaw('SUM(total_cost_in_cents) as cost')
            ->orderByDesc('cost')
            ->limit($limit)
            ->get()
            ->map(fn (SpectraRequest $item) => new CostByModel(
                model: $item->model,
                provider: $item->provider,
                model_type: $item->model_type ?? 'text',
                requests: (int) $item->requests,
                tokens: (int) $item->tokens_sum,
                images: (int) $item->images_sum,
                videos: (int) $item->videos_sum,
                input_characters: (int) $item->input_characters_sum,
                duration_seconds: (float) $item->duration_seconds_sum,
                cost: (float) $item->cost,
                capabilities: $this->pricingLookup->getCapabilities($item->provider ?? '', $item->model),
            ));
    }
}
