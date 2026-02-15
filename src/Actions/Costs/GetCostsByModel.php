<?php

namespace Spectra\Actions\Costs;

use Illuminate\Support\Collection;
use Spectra\Data\CostByModel;
use Spectra\Models\SpectraRequest;
use Spectra\Queries\CostsByModelQuery;
use Spectra\Support\DateRange;
use Spectra\Support\Pricing\PricingLookup;

class GetCostsByModel
{
    public function __construct(
        private readonly CostsByModelQuery $query,
        private readonly PricingLookup $pricingLookup,
    ) {}

    /**
     * @return \Illuminate\Support\Collection<int, CostByModel>
     */
    public function __invoke(DateRange $dateRange, int $limit = 10): Collection
    {
        return ($this->query)($dateRange, $limit)
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
