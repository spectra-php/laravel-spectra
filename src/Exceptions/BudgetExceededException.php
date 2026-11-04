<?php

declare(strict_types=1);

namespace Spectra\Exceptions;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Spectra\Models\SpectraBudget;

/**
 * Thrown before an AI call when a hard budget limit is reached or the
 * provider or model is not allowed by the budget.
 *
 * It is the one error Spectra lets reach the app on purpose: the budget
 * middleware turns it into a 429, and code calling `enforce()` catches it.
 * `limit` and `current` are in the limit's unit (cents, tokens or requests),
 * and 0 for an allow-list block.
 */
class BudgetExceededException extends Exception
{
    /**
     * Create a new budget exceeded exception instance.
     */
    public function __construct(
        public readonly Model $budgetable,
        public readonly SpectraBudget $budget,
        public readonly string $limitType,
        public readonly int $limit,
        public readonly int $current,
        ?string $message = null
    ) {
        $message ??= $this->buildMessage();
        parent::__construct($message);
    }

    /**
     * Build the default message, with the limit and current usage in dollars.
     */
    protected function buildMessage(): string
    {
        $limitInDollars = round($this->limit / 100, 2);
        $currentInDollars = round($this->current / 100, 2);

        return sprintf(
            'AI budget exceeded for %s. %s limit: $%.2f, current usage: $%.2f',
            $this->limitType,
            ucfirst($this->limitType),
            $limitInDollars,
            $currentInDollars
        );
    }

    /**
     * Get the current usage as a percentage of the limit.
     */
    public function getPercentageUsed(): float
    {
        if ($this->limit === 0) {
            return 100.0;
        }

        return round(($this->current / $this->limit) * 100, 2);
    }

    /**
     * Get how far the current usage went over the limit.
     */
    public function getOverageAmount(): int
    {
        return max(0, $this->current - $this->limit);
    }

    /**
     * Get the overage in dollars, for limits measured in cents.
     */
    public function getOverageAmountInDollars(): float
    {
        return round($this->getOverageAmount() / 100, 4);
    }

    /**
     * Create an exception for spend over the daily cost limit.
     */
    public static function dailyLimitExceeded(
        Model $budgetable,
        SpectraBudget $budget,
        int $limit,
        int $current
    ): self {
        return new self($budgetable, $budget, 'daily', $limit, $current);
    }

    /**
     * Create an exception for spend over the weekly cost limit.
     */
    public static function weeklyLimitExceeded(
        Model $budgetable,
        SpectraBudget $budget,
        int $limit,
        int $current
    ): self {
        return new self($budgetable, $budget, 'weekly', $limit, $current);
    }

    /**
     * Create an exception for spend over the monthly cost limit.
     */
    public static function monthlyLimitExceeded(
        Model $budgetable,
        SpectraBudget $budget,
        int $limit,
        int $current
    ): self {
        return new self($budgetable, $budget, 'monthly', $limit, $current);
    }

    /**
     * Create an exception for spend over the all-time total cost limit.
     */
    public static function totalLimitExceeded(
        Model $budgetable,
        SpectraBudget $budget,
        int $limit,
        int $current
    ): self {
        return new self($budgetable, $budget, 'total', $limit, $current);
    }

    /**
     * Create an exception for token usage over the given period's token limit.
     */
    public static function tokenLimitExceeded(
        Model $budgetable,
        SpectraBudget $budget,
        string $period,
        int $limit,
        int $current
    ): self {
        return new self(
            $budgetable,
            $budget,
            "{$period}_tokens",
            $limit,
            $current,
            sprintf(
                'AI token budget exceeded for %s. %s token limit: %d, current usage: %d tokens',
                $period,
                ucfirst($period),
                $limit,
                $current
            )
        );
    }

    /**
     * Create an exception for a request count over the given period's request limit.
     */
    public static function requestLimitExceeded(
        Model $budgetable,
        SpectraBudget $budget,
        string $period,
        int $limit,
        int $current
    ): self {
        return new self(
            $budgetable,
            $budget,
            "{$period}_requests",
            $limit,
            $current,
            sprintf(
                'AI request limit exceeded for %s. %s request limit: %d, current count: %d requests',
                $period,
                ucfirst($period),
                $limit,
                $current
            )
        );
    }

    /**
     * Create an exception for a provider the budget does not allow.
     */
    public static function providerNotAllowed(
        Model $budgetable,
        SpectraBudget $budget,
        string $provider
    ): self {
        return new self(
            $budgetable,
            $budget,
            'provider',
            0,
            0,
            sprintf('Provider "%s" is not allowed by the budget configuration.', $provider)
        );
    }

    /**
     * Create an exception for a model the budget does not allow.
     */
    public static function modelNotAllowed(
        Model $budgetable,
        SpectraBudget $budget,
        string $model
    ): self {
        return new self(
            $budgetable,
            $budget,
            'model',
            0,
            0,
            sprintf('Model "%s" is not allowed by the budget configuration.', $model)
        );
    }
}
