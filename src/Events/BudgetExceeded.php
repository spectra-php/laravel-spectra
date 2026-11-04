<?php

declare(strict_types=1);

namespace Spectra\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Spectra\Models\SpectraBudget;

/**
 * A budget limit was reached.
 *
 * `wasBlocked: true` fires before a hard limit stops a request (cost, token
 * and request-count limits, and the allowed providers and models).
 * `wasBlocked: false` fires once per cost limit and period when a recorded
 * request takes spend over the limit, so listeners hear about it even when
 * the limit is soft.
 *
 * `currentUsage` and `limit` are in the limit's unit: cents for cost limits
 * (`daily`, `weekly`, `monthly`, `total`), tokens for `*_tokens`, requests
 * for `*_requests`. For `provider` and `model` (not on the allowed list)
 * both are 0.
 */
class BudgetExceeded
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new budget exceeded event instance.
     */
    public function __construct(
        public readonly Model $budgetable,
        public readonly SpectraBudget $budget,
        public readonly string $limitType,
        public readonly int $currentUsage,
        public readonly int $limit,
        public readonly bool $wasBlocked
    ) {}

    /**
     * Get how far the usage went over the limit, in cents or units of the limit type.
     */
    public function getOverageAmount(): int
    {
        return max(0, $this->currentUsage - $this->limit);
    }

    /**
     * Get the overage in dollars, for limits measured in cents.
     */
    public function getOverageAmountInDollars(): float
    {
        return round($this->getOverageAmount() / 100, 4);
    }

    /**
     * Get how far the usage went over the limit, as a percentage of the limit.
     */
    public function getOveragePercentage(): float
    {
        if ($this->limit === 0) {
            return 100.0;
        }

        return round((($this->currentUsage - $this->limit) / $this->limit) * 100, 2);
    }
}
