<?php

namespace Spectra\Data\Responses;

use Illuminate\Support\Collection;
use Spectra\Data\DataTransferObject;

readonly class CostsResponse extends DataTransferObject
{
    /**
     * @param  Collection<int, mixed>  $costs_by_provider
     * @param  Collection<int, mixed>  $costs_by_model
     * @param  Collection<int, mixed>  $costs_by_model_type
     * @param  Collection<int, mixed>  $costs_by_date
     * @param  Collection<int, mixed>  $costs_by_user
     */
    public function __construct(
        public float $total_cost_in_cents,
        public CostOverviewResponse $cost_overview,
        public Collection $costs_by_provider,
        public Collection $costs_by_model,
        public Collection $costs_by_model_type,
        public Collection $costs_by_date,
        public Collection $costs_by_user,
    ) {}
}
