<?php

declare(strict_types=1);

namespace Spectra\Data;

/**
 * One model type's requests in a dashboard's breakdown, without its cost.
 */
readonly class DashboardModelTypeRow extends DataTransferObject
{
    /**
     * Create a new dashboard model type row instance.
     */
    public function __construct(
        public string $model_type,
        public string $label,
        public int $count,
    ) {}
}
