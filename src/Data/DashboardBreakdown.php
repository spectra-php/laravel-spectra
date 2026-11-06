<?php

declare(strict_types=1);

namespace Spectra\Data;

use Illuminate\Support\Collection;

/**
 * A dashboard's Breakdown card: the requests per provider and per model type.
 */
readonly class DashboardBreakdown extends DataTransferObject
{
    /**
     * Create a new dashboard breakdown instance.
     *
     * @param  Collection<int, DashboardUsageRow>  $providers
     * @param  Collection<int, DashboardModelTypeRow>  $model_types
     */
    public function __construct(
        public Collection $providers,
        public Collection $model_types,
    ) {}
}
