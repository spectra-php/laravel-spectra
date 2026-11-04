<?php

declare(strict_types=1);

namespace Spectra\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Spectra\Models\SpectraBudget;

/**
 * Spend reached a budget's warning or critical percentage of a cost limit.
 *
 * Fires once per budget, limit, threshold and period, so a listener can
 * send an alert without repeating it on every request.
 */
class BudgetThresholdReached
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new budget threshold reached event instance.
     */
    public function __construct(
        public readonly Model $budgetable,
        public readonly SpectraBudget $budget,
        public readonly string $thresholdType,
        public readonly string $limitType,
        public readonly float $percentageUsed,
        public readonly int $currentUsage,
        public readonly int $limit
    ) {}

    /**
     * Determine if the usage crossed the budget's warning threshold.
     */
    public function isWarning(): bool
    {
        return $this->thresholdType === 'warning';
    }

    /**
     * Determine if the usage crossed the budget's critical threshold.
     */
    public function isCritical(): bool
    {
        return $this->thresholdType === 'critical';
    }

    /**
     * Get what is left of the limit before it is exceeded.
     */
    public function getRemainingBudget(): int
    {
        return max(0, $this->limit - $this->currentUsage);
    }

    /**
     * Get the remaining budget in dollars, for limits measured in cents.
     */
    public function getRemainingBudgetInDollars(): float
    {
        return round($this->getRemainingBudget() / 100, 4);
    }
}
