<?php

namespace Spectra\Exceptions;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Spectra\Models\SpectraBudget;

class BudgetExceededException extends Exception
{
    public function __construct(
        public readonly Model $budgetable,
        public readonly SpectraBudget $budget,
        public readonly string $limitType,
        public readonly int $limit,
        public readonly int $current,
        ?string $message = null
    ) {
        $message = $message ?? $this->buildMessage();
        parent::__construct($message);
    }

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

    public function getPercentageUsed(): float
    {
        if ($this->limit === 0) {
            return 100.0;
        }

        return round(($this->current / $this->limit) * 100, 2);
    }

    public function getOverageAmount(): int
    {
        return max(0, $this->current - $this->limit);
    }

    public function getOverageAmountInDollars(): float
    {
        return round($this->getOverageAmount() / 100, 4);
    }

    public static function dailyLimitExceeded(
        Model $budgetable,
        SpectraBudget $budget,
        int $limit,
        int $current
    ): self {
        return new self($budgetable, $budget, 'daily', $limit, $current);
    }

    public static function weeklyLimitExceeded(
        Model $budgetable,
        SpectraBudget $budget,
        int $limit,
        int $current
    ): self {
        return new self($budgetable, $budget, 'weekly', $limit, $current);
    }

    public static function monthlyLimitExceeded(
        Model $budgetable,
        SpectraBudget $budget,
        int $limit,
        int $current
    ): self {
        return new self($budgetable, $budget, 'monthly', $limit, $current);
    }

    public static function totalLimitExceeded(
        Model $budgetable,
        SpectraBudget $budget,
        int $limit,
        int $current
    ): self {
        return new self($budgetable, $budget, 'total', $limit, $current);
    }

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
