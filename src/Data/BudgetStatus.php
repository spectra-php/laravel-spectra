<?php

namespace Spectra\Data;

use Spectra\Models\SpectraBudget;

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
