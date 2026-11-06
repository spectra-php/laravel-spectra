<?php

declare(strict_types=1);

namespace Spectra\Data;

/**
 * One provider's or model's usage on a shared dashboard. The model is null on
 * a per-provider row, and the cost on a dashboard that hides costs.
 */
readonly class DashboardUsageRow extends DataTransferObject
{
    /**
     * Create a new shared usage row instance.
     */
    public function __construct(
        public ?string $provider,
        public ?string $provider_name,
        public ?string $model,
        public int $requests,
        public int $tokens,
        public ?float $cost,
    ) {}
}
