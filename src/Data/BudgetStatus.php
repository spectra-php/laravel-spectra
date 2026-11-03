<?php

declare(strict_types=1);

namespace Spectra\Data;

use Spectra\Models\SpectraBudget;

/**
 * The answer to "may this call go ahead?": whether the budget allows it
 * (including its provider and model allow-lists), the usage and limits it was
 * judged on, and how much of the budget is used.
 */
readonly class BudgetStatus extends DataTransferObject
{
    public function __construct(
        public bool $allowed,
        public ?SpectraBudget $budget,
        public BudgetUsage $usage,
        public BudgetLimits $limits,
        public float $percentage,
        public bool $providerAllowed = true,
        public bool $modelAllowed = true,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_map(
            fn ($value) => $value instanceof DataTransferObject ? $value->toArray() : $value,
            $this->toArray(),
        );
    }
}
