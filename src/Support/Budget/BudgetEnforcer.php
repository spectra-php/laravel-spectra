<?php

namespace Spectra\Support\Budget;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Spectra\Data\BudgetLimits;
use Spectra\Data\BudgetStatus;
use Spectra\Data\BudgetUsage;
use Spectra\Events\BudgetExceeded;
use Spectra\Events\BudgetThresholdReached;
use Spectra\Exceptions\BudgetExceededException;
use Spectra\Models\SpectraBudget;
use Spectra\Models\SpectraRequest;

class BudgetEnforcer
{
    /** @var array<string, bool> */
    protected array $thresholdsFired = [];

    public function __construct(
        protected Dispatcher $events
    ) {}

    public function enforce(Model $budgetable, string $provider, string $model): void
    {
        $budget = $this->getBudget($budgetable);

        if (! $budget) {
            return;
        }

        if (! $budget->isProviderAllowed($provider)) {
            throw BudgetExceededException::providerNotAllowed($budgetable, $budget, $provider);
        }

        if (! $budget->isModelAllowed($model)) {
            throw BudgetExceededException::modelNotAllowed($budgetable, $budget, $model);
        }

        $this->checkCostLimits($budgetable, $budget);
        $this->checkTokenLimits($budgetable, $budget);
        $this->checkRequestLimits($budgetable, $budget);
    }

    public function check(Model $budgetable, string $provider, string $model): BudgetStatus
    {
        $budget = $this->getBudget($budgetable);

        if (! $budget) {
            return new BudgetStatus(
                allowed: true,
                budget: null,
                usage: new BudgetUsage,
                limits: new BudgetLimits,
                percentage: 0.0,
            );
        }

        $usage = $this->getUsage($budgetable);
        $limits = $this->getLimits($budget);

        $providerAllowed = $budget->isProviderAllowed($provider);
        $modelAllowed = $budget->isModelAllowed($model);

        $maxPercentage = $this->calculateMaxPercentage($usage, $limits);

        $allowed = $providerAllowed
            && $modelAllowed
            && ($maxPercentage < 100 || ! $budget->hard_limit);

        return new BudgetStatus(
            allowed: $allowed,
            budget: $budget,
            usage: $usage,
            limits: $limits,
            percentage: $maxPercentage,
            providerAllowed: $providerAllowed,
            modelAllowed: $modelAllowed,
        );
    }

    public function getUsage(Model $budgetable): BudgetUsage
    {
        return new BudgetUsage(
            dailyCost: $this->getCostForPeriod($budgetable, 'day'),
            weeklyCost: $this->getCostForPeriod($budgetable, 'week'),
            monthlyCost: $this->getCostForPeriod($budgetable, 'month'),
            totalCostInCents: $this->getTotalCost($budgetable),
            dailyTokens: $this->getTokensForPeriod($budgetable, 'day'),
            weeklyTokens: $this->getTokensForPeriod($budgetable, 'week'),
            monthlyTokens: $this->getTokensForPeriod($budgetable, 'month'),
            totalTokens: $this->getTotalTokens($budgetable),
            dailyRequests: $this->getRequestsForPeriod($budgetable, 'day'),
            weeklyRequests: $this->getRequestsForPeriod($budgetable, 'week'),
            monthlyRequests: $this->getRequestsForPeriod($budgetable, 'month'),
        );
    }

    public function getBudget(Model $budgetable): ?SpectraBudget
    {
        return SpectraBudget::query()
            ->where('budgetable_type', $budgetable->getMorphClass())
            ->where('budgetable_id', $budgetable->getKey())
            ->active()
            ->first();
    }

    public function recordUsage(Model $budgetable, int $cost, int $tokens): void
    {
        $budget = $this->getBudget($budgetable);

        if (! $budget) {
            return;
        }

        $usage = $this->getUsage($budgetable);
        $this->checkThresholds($budgetable, $budget, $usage);
    }

    protected function checkCostLimits(Model $budgetable, SpectraBudget $budget): void
    {
        if ($budget->daily_limit !== null && $budget->daily_limit > 0) {
            $dailyLimit = $budget->daily_limit;
            $dailyCost = $this->getCostForPeriod($budgetable, 'day');
            $this->checkAndDispatchThreshold($budgetable, $budget, 'daily', $dailyCost, $dailyLimit);

            if ($dailyCost >= $dailyLimit && $budget->hard_limit) {
                $this->dispatchExceeded($budgetable, $budget, 'daily', $dailyCost, $dailyLimit, true);
                throw BudgetExceededException::dailyLimitExceeded($budgetable, $budget, $dailyLimit, $dailyCost);
            }
        }

        if ($budget->weekly_limit !== null && $budget->weekly_limit > 0) {
            $weeklyLimit = $budget->weekly_limit;
            $weeklyCost = $this->getCostForPeriod($budgetable, 'week');
            $this->checkAndDispatchThreshold($budgetable, $budget, 'weekly', $weeklyCost, $weeklyLimit);

            if ($weeklyCost >= $weeklyLimit && $budget->hard_limit) {
                $this->dispatchExceeded($budgetable, $budget, 'weekly', $weeklyCost, $weeklyLimit, true);
                throw BudgetExceededException::weeklyLimitExceeded($budgetable, $budget, $weeklyLimit, $weeklyCost);
            }
        }

        if ($budget->monthly_limit !== null && $budget->monthly_limit > 0) {
            $monthlyLimit = $budget->monthly_limit;
            $monthlyCost = $this->getCostForPeriod($budgetable, 'month');
            $this->checkAndDispatchThreshold($budgetable, $budget, 'monthly', $monthlyCost, $monthlyLimit);

            if ($monthlyCost >= $monthlyLimit && $budget->hard_limit) {
                $this->dispatchExceeded($budgetable, $budget, 'monthly', $monthlyCost, $monthlyLimit, true);
                throw BudgetExceededException::monthlyLimitExceeded($budgetable, $budget, $monthlyLimit, $monthlyCost);
            }
        }

        if ($budget->total_limit !== null && $budget->total_limit > 0) {
            $totalLimit = $budget->total_limit;
            $totalCost = $this->getTotalCost($budgetable);
            $this->checkAndDispatchThreshold($budgetable, $budget, 'total', $totalCost, $totalLimit);

            if ($totalCost >= $totalLimit && $budget->hard_limit) {
                $this->dispatchExceeded($budgetable, $budget, 'total', $totalCost, $totalLimit, true);
                throw BudgetExceededException::totalLimitExceeded($budgetable, $budget, $totalLimit, $totalCost);
            }
        }
    }

    protected function checkTokenLimits(Model $budgetable, SpectraBudget $budget): void
    {
        $periods = [
            'daily' => ['limit' => $budget->daily_token_limit, 'period' => 'day'],
            'weekly' => ['limit' => $budget->weekly_token_limit, 'period' => 'week'],
            'monthly' => ['limit' => $budget->monthly_token_limit, 'period' => 'month'],
            'total' => ['limit' => $budget->total_token_limit, 'period' => null],
        ];

        foreach ($periods as $name => $config) {
            if (! $config['limit']) {
                continue;
            }

            $tokens = $config['period']
                ? $this->getTokensForPeriod($budgetable, $config['period'])
                : $this->getTotalTokens($budgetable);

            if ($tokens >= $config['limit'] && $budget->hard_limit) {
                throw BudgetExceededException::tokenLimitExceeded($budgetable, $budget, $name, $config['limit'], $tokens);
            }
        }
    }

    protected function checkRequestLimits(Model $budgetable, SpectraBudget $budget): void
    {
        $periods = [
            'daily' => ['limit' => $budget->daily_request_limit, 'period' => 'day'],
            'weekly' => ['limit' => $budget->weekly_request_limit, 'period' => 'week'],
            'monthly' => ['limit' => $budget->monthly_request_limit, 'period' => 'month'],
        ];

        foreach ($periods as $name => $config) {
            if (! $config['limit']) {
                continue;
            }

            $requests = $this->getRequestsForPeriod($budgetable, $config['period']);

            if ($requests >= $config['limit'] && $budget->hard_limit) {
                throw BudgetExceededException::requestLimitExceeded($budgetable, $budget, $name, $config['limit'], $requests);
            }
        }
    }

    protected function checkAndDispatchThreshold(
        Model $budgetable,
        SpectraBudget $budget,
        string $limitType,
        int $current,
        int $limit
    ): void {
        if ($limit === 0) {
            return;
        }

        $percentage = ($current / $limit) * 100;
        $key = $budgetable->getMorphClass().':'.$budgetable->getKey().':'.$limitType;

        if ($percentage >= $budget->warning_threshold && $percentage < $budget->critical_threshold) {
            if (! isset($this->thresholdsFired[$key.':warning'])) {
                $this->thresholdsFired[$key.':warning'] = true;
                $this->events->dispatch(new BudgetThresholdReached(
                    $budgetable,
                    $budget,
                    'warning',
                    $limitType,
                    $percentage,
                    $current,
                    $limit
                ));
            }
        }

        if ($percentage >= $budget->critical_threshold && $percentage < 100) {
            if (! isset($this->thresholdsFired[$key.':critical'])) {
                $this->thresholdsFired[$key.':critical'] = true;
                $this->events->dispatch(new BudgetThresholdReached(
                    $budgetable,
                    $budget,
                    'critical',
                    $limitType,
                    $percentage,
                    $current,
                    $limit
                ));
            }
        }
    }

    protected function dispatchExceeded(
        Model $budgetable,
        SpectraBudget $budget,
        string $limitType,
        int $current,
        int $limit,
        bool $wasBlocked
    ): void {
        $this->events->dispatch(new BudgetExceeded(
            $budgetable,
            $budget,
            $limitType,
            $current,
            $limit,
            $wasBlocked
        ));
    }

    protected function checkThresholds(Model $budgetable, SpectraBudget $budget, BudgetUsage $usage): void
    {
        $checks = [
            ['usage' => 'dailyCost', 'limit' => $budget->daily_limit, 'type' => 'daily'],
            ['usage' => 'weeklyCost', 'limit' => $budget->weekly_limit, 'type' => 'weekly'],
            ['usage' => 'monthlyCost', 'limit' => $budget->monthly_limit, 'type' => 'monthly'],
            ['usage' => 'totalCostInCents', 'limit' => $budget->total_limit, 'type' => 'total'],
        ];

        foreach ($checks as $check) {
            if ($check['limit']) {
                $this->checkAndDispatchThreshold(
                    $budgetable,
                    $budget,
                    $check['type'],
                    $usage[$check['usage']],
                    $check['limit']
                );
            }
        }
    }

    protected function getCostForPeriod(Model $budgetable, string $period): int
    {
        $start = match ($period) {
            'day' => now()->startOfDay(),
            'week' => now()->startOfWeek(),
            'month' => now()->startOfMonth(),
            default => now()->startOfDay(),
        };

        $cost = (float) SpectraRequest::query()
            ->where('trackable_type', $budgetable->getMorphClass())
            ->where('trackable_id', $budgetable->getKey())
            ->where('created_at', '>=', $start)
            ->sum('total_cost_in_cents');

        return $this->toWholeCents($cost);
    }

    protected function getTotalCost(Model $budgetable): int
    {
        $cost = (float) SpectraRequest::query()
            ->where('trackable_type', $budgetable->getMorphClass())
            ->where('trackable_id', $budgetable->getKey())
            ->sum('total_cost_in_cents');

        return $this->toWholeCents($cost);
    }

    protected function getTokensForPeriod(Model $budgetable, string $period): int
    {
        $start = match ($period) {
            'day' => now()->startOfDay(),
            'week' => now()->startOfWeek(),
            'month' => now()->startOfMonth(),
            default => now()->startOfDay(),
        };

        return (int) SpectraRequest::query()
            ->where('trackable_type', $budgetable->getMorphClass())
            ->where('trackable_id', $budgetable->getKey())
            ->where('created_at', '>=', $start)
            ->selectRaw('SUM(prompt_tokens + completion_tokens) as total')
            ->value('total');
    }

    protected function getTotalTokens(Model $budgetable): int
    {
        return (int) SpectraRequest::query()
            ->where('trackable_type', $budgetable->getMorphClass())
            ->where('trackable_id', $budgetable->getKey())
            ->selectRaw('SUM(prompt_tokens + completion_tokens) as total')
            ->value('total');
    }

    protected function getRequestsForPeriod(Model $budgetable, string $period): int
    {
        $start = match ($period) {
            'day' => now()->startOfDay(),
            'week' => now()->startOfWeek(),
            'month' => now()->startOfMonth(),
            default => now()->startOfDay(),
        };

        return SpectraRequest::query()
            ->where('trackable_type', $budgetable->getMorphClass())
            ->where('trackable_id', $budgetable->getKey())
            ->where('created_at', '>=', $start)
            ->count();
    }

    protected function getLimits(SpectraBudget $budget): BudgetLimits
    {
        return new BudgetLimits(
            dailyLimit: $budget->daily_limit,
            weeklyLimit: $budget->weekly_limit,
            monthlyLimit: $budget->monthly_limit,
            totalLimit: $budget->total_limit,
            dailyTokenLimit: $budget->daily_token_limit,
            weeklyTokenLimit: $budget->weekly_token_limit,
            monthlyTokenLimit: $budget->monthly_token_limit,
            totalTokenLimit: $budget->total_token_limit,
            dailyRequestLimit: $budget->daily_request_limit,
            weeklyRequestLimit: $budget->weekly_request_limit,
            monthlyRequestLimit: $budget->monthly_request_limit,
        );
    }

    protected function calculateMaxPercentage(BudgetUsage $usage, BudgetLimits $limits): float
    {
        $percentages = [];

        $mappings = [
            'dailyCost' => 'dailyLimit',
            'weeklyCost' => 'weeklyLimit',
            'monthlyCost' => 'monthlyLimit',
            'totalCostInCents' => 'totalLimit',
            'dailyTokens' => 'dailyTokenLimit',
            'weeklyTokens' => 'weeklyTokenLimit',
            'monthlyTokens' => 'monthlyTokenLimit',
            'totalTokens' => 'totalTokenLimit',
            'dailyRequests' => 'dailyRequestLimit',
            'weeklyRequests' => 'weeklyRequestLimit',
            'monthlyRequests' => 'monthlyRequestLimit',
        ];

        foreach ($mappings as $usageKey => $limitKey) {
            if (isset($limits[$limitKey]) && $limits[$limitKey] > 0 && isset($usage[$usageKey])) {
                $percentages[] = ($usage[$usageKey] / $limits[$limitKey]) * 100;
            }
        }

        return empty($percentages) ? 0.0 : max($percentages);
    }

    protected function toWholeCents(float|int $cost): int
    {
        if ($cost <= 0) {
            return 0;
        }

        return (int) ceil($cost);
    }
}
