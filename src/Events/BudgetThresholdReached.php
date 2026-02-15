<?php

namespace Spectra\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Spectra\Models\SpectraBudget;

class BudgetThresholdReached
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Model $budgetable,
        public readonly SpectraBudget $budget,
        public readonly string $thresholdType,
        public readonly string $limitType,
        public readonly float $percentageUsed,
        public readonly int $currentUsage,
        public readonly int $limit
    ) {}

    public function isWarning(): bool
    {
        return $this->thresholdType === 'warning';
    }

    public function isCritical(): bool
    {
        return $this->thresholdType === 'critical';
    }

    public function getRemainingBudget(): int
    {
        return max(0, $this->limit - $this->currentUsage);
    }

    public function getRemainingBudgetInDollars(): float
    {
        return round($this->getRemainingBudget() / 100, 4);
    }
}
