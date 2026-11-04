<?php

declare(strict_types=1);

namespace Spectra\Data;

use Illuminate\Support\Arr;
use Spectra\Models\SpectraBudget;

/**
 * The answer to "may this call go ahead?": whether the budget allows it
 * (including its provider and model allow-lists), the usage and limits it was
 * judged on, and how much of the budget is used.
 */
readonly class BudgetStatus extends DataTransferObject
{
    /**
     * Create a new budget status instance.
     */
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
     * Get the status for JSON, with the usage and limits DTOs turned into arrays.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return Arr::map(
            $this->toArray(),
            fn (mixed $value) => $value instanceof DataTransferObject ? $value->toArray() : $value,
        );
    }
}
