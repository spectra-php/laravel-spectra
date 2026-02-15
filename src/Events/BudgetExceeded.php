<?php

namespace Spectra\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Spectra\Models\SpectraBudget;

class BudgetExceeded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Model $budgetable,
        public readonly SpectraBudget $budget,
        public readonly string $limitType,
        public readonly int $currentUsage,
        public readonly int $limit,
        public readonly bool $wasBlocked
    ) {}

    public function getOverageAmount(): int
    {
        return max(0, $this->currentUsage - $this->limit);
    }

    public function getOverageAmountInDollars(): float
    {
        return round($this->getOverageAmount() / 100, 4);
    }

    public function getOveragePercentage(): float
    {
        if ($this->limit === 0) {
            return 100.0;
        }

        return round((($this->currentUsage - $this->limit) / $this->limit) * 100, 2);
    }
}
