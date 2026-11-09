<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Illuminate\Support\Collection;
use Spectra\Data\CostByDate;
use Spectra\Data\CostByModel;
use Spectra\Data\CostByModelType;
use Spectra\Data\CostByProvider;
use Spectra\Data\CostByUser;
use Spectra\Data\CostModelOption;
use Spectra\Data\DataTransferObject;

/**
 * The costs screen: the period total, the summary cards, and cost by provider,
 * model, model type, day and user, plus the models its model filter offers.
 */
readonly class CostsResponse extends DataTransferObject
{
    /**
     * Create a new costs response instance.
     *
     * @param  Collection<int, CostByProvider>  $costs_by_provider
     * @param  Collection<int, CostByModel>  $costs_by_model
     * @param  Collection<int, CostByModelType>  $costs_by_model_type
     * @param  Collection<int, CostByDate>  $costs_by_date
     * @param  Collection<int, CostByUser>  $costs_by_user
     * @param  Collection<int, CostModelOption>  $models
     */
    public function __construct(
        public float $total_cost_in_cents,
        public CostOverviewResponse $cost_overview,
        public Collection $costs_by_provider,
        public Collection $costs_by_model,
        public Collection $costs_by_model_type,
        public Collection $costs_by_date,
        public Collection $costs_by_user,
        public Collection $models,
    ) {}
}
