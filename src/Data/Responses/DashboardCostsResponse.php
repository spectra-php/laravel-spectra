<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Spectra\Data\DataTransferObject;

/**
 * The costs page of a shared dashboard: its totals and breakdowns.
 */
readonly class DashboardCostsResponse extends DataTransferObject
{
    /**
     * Create a new dashboard costs response instance.
     *
     * @param  array<int, mixed>  $by_date
     * @param  array<int, array<string, mixed>>  $by_provider
     * @param  array<int, array<string, mixed>>  $by_model
     * @param  array<int, array<string, mixed>>  $by_model_type
     */
    public function __construct(
        public float $total_cost,
        public int $total_requests,
        public array $by_date,
        public array $by_provider,
        public array $by_model,
        public array $by_model_type,
    ) {}
}
