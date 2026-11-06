<?php

declare(strict_types=1);

namespace Spectra\Support\Budget;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Cache;
use Spectra\Data\BudgetLimits;
use Spectra\Data\BudgetStatus;
use Spectra\Data\BudgetUsage;
use Spectra\Events\BudgetExceeded;
use Spectra\Events\BudgetThresholdReached;
use Spectra\Exceptions\BudgetExceededException;
use Spectra\Models\SpectraBudget;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Query\RollupSchema;

/**
 * Checks budgets before a request (blocking when a hard limit is reached)
 * and announces thresholds and overruns after a request is recorded.
 *
 * Warning, critical and exceeded events fire once per budget, limit and
 * period. That "already sent" mark lives in the cache, so it holds across
 * PHP-FPM requests and queue workers, and resets when the period (day, week,
 * month) or the limit changes.
 */
class BudgetEnforcer
{
    /**
     * Create a new budget enforcer instance.
     */
    public function __construct(
        protected Dispatcher $events
    ) {}

    /**
     * Check a request against the model's budget before it is sent, throwing when it is blocked.
     */
    public function enforce(Model $budgetable, string $provider, string $model): void
    {
        $budget = $this->getBudget($budgetable);

        if (! $budget) {
            return;
        }

        if (! $budget->isProviderAllowed($provider)) {
            $this->block(BudgetExceededException::providerNotAllowed($budgetable, $budget, $provider));
        }

        if (! $budget->isModelAllowed($model)) {
            $this->block(BudgetExceededException::modelNotAllowed($budgetable, $budget, $model));
        }

        $this->checkCostLimits($budgetable, $budget);
        $this->checkTokenLimits($budgetable, $budget);
        $this->checkRequestLimits($budgetable, $budget);
    }

    /**
     * Get the model's budget status for a request without blocking or firing events.
     */
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

        $isProviderAllowed = $budget->isProviderAllowed($provider);
        $isModelAllowed = $budget->isModelAllowed($model);

        $maxPercentage = $this->calculateMaxPercentage($usage, $limits);

        $isAllowed = $isProviderAllowed
            && $isModelAllowed
            && ($maxPercentage < 100 || ! $budget->hard_limit);

        return new BudgetStatus(
            allowed: $isAllowed,
            budget: $budget,
            usage: $usage,
            limits: $limits,
            percentage: $maxPercentage,
            providerAllowed: $isProviderAllowed,
            modelAllowed: $isModelAllowed,
        );
    }

    /**
     * Get the model's cost, token and request usage for each budget period.
     */
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

    /**
     * Get the active budget of the model, if it has one.
     */
    public function getBudget(Model $budgetable): ?SpectraBudget
    {
        return $this->findBudget($budgetable->getMorphClass(), (string) $budgetable->getKey());
    }

    /**
     * Fire the threshold and exceeded events for the model's recorded spend.
     *
     * The spend is read from the saved rows, so the cost and tokens of the
     * request that was just recorded are not needed.
     */
    public function recordUsage(Model $budgetable, int $cost, int $tokens): void
    {
        $budget = $this->getBudget($budgetable);

        if (! $budget) {
            return;
        }

        $this->reportSpend($budgetable, $budget);
    }

    /**
     * Announce the thresholds and cost limits a recorded request has crossed.
     *
     * Pre-flight enforcement can only see spend from previous requests, so
     * the request that actually crosses a limit is detected here and
     * announced via BudgetExceeded — without ever blocking the
     * already-completed request.
     *
     * This runs for every saved request, so it costs one query for the
     * budget (most trackables have none) and then one per cost limit the
     * budget sets.
     */
    public function reportRecordedRequest(SpectraRequest $request): void
    {
        $type = $request->trackable_type;
        $id = $request->trackable_id;

        if (! $type || $id === null) {
            return;
        }

        $budget = $this->findBudget($type, $id);

        if ($budget === null) {
            return;
        }

        $budgetable = $this->resolveBudgetable($type, $id);

        if ($budgetable === null) {
            return;
        }

        $this->reportSpend($budgetable, $budget);
    }

    /**
     * Find the active budget saved under a morph type and id.
     */
    protected function findBudget(string $morphType, string $id): ?SpectraBudget
    {
        return SpectraBudget::query()
            ->where('budgetable_type', $morphType)
            ->where('budgetable_id', $id)
            ->active()
            ->first();
    }

    /**
     * Fire the threshold and exceeded events for the cost limits the budget
     * sets, reading only the spend those limits compare against.
     */
    protected function reportSpend(Model $budgetable, SpectraBudget $budget): void
    {
        $usage = new BudgetUsage(
            dailyCost: $budget->daily_limit > 0 ? $this->getCostForPeriod($budgetable, 'day') : 0,
            weeklyCost: $budget->weekly_limit > 0 ? $this->getCostForPeriod($budgetable, 'week') : 0,
            monthlyCost: $budget->monthly_limit > 0 ? $this->getCostForPeriod($budgetable, 'month') : 0,
            totalCostInCents: $budget->total_limit > 0 ? $this->getTotalCost($budgetable) : 0,
        );

        $this->checkThresholds($budgetable, $budget, $usage);
        $this->reportExceededLimits($budgetable, $budget, $usage);
    }

    /**
     * Find the trackable model of a recorded request from its morph type and id.
     */
    protected function resolveBudgetable(string $morphType, string $id): ?Model
    {
        $class = Relation::getMorphedModel($morphType) ?? $morphType;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return $class::query()->find($id);
    }

    /**
     * Emit BudgetExceeded (wasBlocked: false) once per limit type when the
     * recorded spend has crossed a cost limit.
     */
    protected function reportExceededLimits(Model $budgetable, SpectraBudget $budget, BudgetUsage $usage): void
    {
        $checks = [
            'daily' => [$usage->dailyCost, $budget->daily_limit],
            'weekly' => [$usage->weeklyCost, $budget->weekly_limit],
            'monthly' => [$usage->monthlyCost, $budget->monthly_limit],
            'total' => [$usage->totalCostInCents, $budget->total_limit],
        ];

        foreach ($checks as $type => [$current, $limit]) {
            if ($limit === null || $limit <= 0 || $current < $limit) {
                continue;
            }

            if (! $this->fireOnce($budget, $type, 'exceeded', $limit)) {
                continue;
            }

            $this->dispatchExceeded($budgetable, $budget, $type, $current, $limit, false);
        }
    }

    /**
     * Fire threshold events for each cost limit and block once a hard limit is reached.
     */
    protected function checkCostLimits(Model $budgetable, SpectraBudget $budget): void
    {
        if ($budget->daily_limit !== null && $budget->daily_limit > 0) {
            $dailyLimit = $budget->daily_limit;
            $dailyCost = $this->getCostForPeriod($budgetable, 'day');
            $this->checkAndDispatchThreshold($budgetable, $budget, 'daily', $dailyCost, $dailyLimit);

            if ($dailyCost >= $dailyLimit && $budget->hard_limit) {
                $this->block(BudgetExceededException::dailyLimitExceeded($budgetable, $budget, $dailyLimit, $dailyCost));
            }
        }

        if ($budget->weekly_limit !== null && $budget->weekly_limit > 0) {
            $weeklyLimit = $budget->weekly_limit;
            $weeklyCost = $this->getCostForPeriod($budgetable, 'week');
            $this->checkAndDispatchThreshold($budgetable, $budget, 'weekly', $weeklyCost, $weeklyLimit);

            if ($weeklyCost >= $weeklyLimit && $budget->hard_limit) {
                $this->block(BudgetExceededException::weeklyLimitExceeded($budgetable, $budget, $weeklyLimit, $weeklyCost));
            }
        }

        if ($budget->monthly_limit !== null && $budget->monthly_limit > 0) {
            $monthlyLimit = $budget->monthly_limit;
            $monthlyCost = $this->getCostForPeriod($budgetable, 'month');
            $this->checkAndDispatchThreshold($budgetable, $budget, 'monthly', $monthlyCost, $monthlyLimit);

            if ($monthlyCost >= $monthlyLimit && $budget->hard_limit) {
                $this->block(BudgetExceededException::monthlyLimitExceeded($budgetable, $budget, $monthlyLimit, $monthlyCost));
            }
        }

        if ($budget->total_limit !== null && $budget->total_limit > 0) {
            $totalLimit = $budget->total_limit;
            $totalCost = $this->getTotalCost($budgetable);
            $this->checkAndDispatchThreshold($budgetable, $budget, 'total', $totalCost, $totalLimit);

            if ($totalCost >= $totalLimit && $budget->hard_limit) {
                $this->block(BudgetExceededException::totalLimitExceeded($budgetable, $budget, $totalLimit, $totalCost));
            }
        }
    }

    /**
     * Block the request once a hard token limit is reached.
     */
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
                $this->block(BudgetExceededException::tokenLimitExceeded($budgetable, $budget, $name, $config['limit'], $tokens));
            }
        }
    }

    /**
     * Block the request once a hard request-count limit is reached.
     */
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
                $this->block(BudgetExceededException::requestLimitExceeded($budgetable, $budget, $name, $config['limit'], $requests));
            }
        }
    }

    /**
     * Fire the warning or critical event once per period when usage crosses
     * its threshold. A threshold of 0 is off: it never fires, and the
     * warning then runs up to the limit.
     */
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
        $warningCeiling = $budget->critical_threshold > 0 ? $budget->critical_threshold : 100;

        if ($budget->warning_threshold > 0 && $percentage >= $budget->warning_threshold && $percentage < $warningCeiling) {
            if ($this->fireOnce($budget, $limitType, 'warning', $limit)) {
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

        if ($budget->critical_threshold > 0 && $percentage >= $budget->critical_threshold && $percentage < 100) {
            if ($this->fireOnce($budget, $limitType, 'critical', $limit)) {
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

    /**
     * Announce a pre-flight block, then throw it. The event carries the same
     * limit type, usage and limit as the exception, so listeners see every
     * blocked request, whichever limit or allow-list stopped it.
     *
     * @throws BudgetExceededException
     */
    protected function block(BudgetExceededException $exception): never
    {
        $this->dispatchExceeded(
            $exception->budgetable,
            $exception->budget,
            $exception->limitType,
            $exception->current,
            $exception->limit,
            true
        );

        throw $exception;
    }

    /**
     * Dispatch the BudgetExceeded event for a limit.
     */
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

    /**
     * Fire threshold events for each cost limit the budget sets.
     */
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

    /**
     * Get the model's spend since the start of the day, week or month, in whole cents.
     */
    protected function getCostForPeriod(Model $budgetable, string $period): int
    {
        return $this->toWholeCents((float) $this->usageRows($budgetable, $this->periodStart($period))->sum('total_cost_in_cents'));
    }

    /**
     * Get the model's spend across all requests, in whole cents.
     */
    protected function getTotalCost(Model $budgetable): int
    {
        return $this->toWholeCents((float) $this->usageRows($budgetable)->sum('total_cost_in_cents'));
    }

    /**
     * Get the prompt and completion tokens the model used since the start of the period.
     */
    protected function getTokensForPeriod(Model $budgetable, string $period): int
    {
        return $this->sumTokens($this->usageRows($budgetable, $this->periodStart($period)));
    }

    /**
     * Get the prompt and completion tokens the model used across all requests.
     */
    protected function getTotalTokens(Model $budgetable): int
    {
        return $this->sumTokens($this->usageRows($budgetable));
    }

    /**
     * Get the number of requests the model made since the start of the period.
     */
    protected function getRequestsForPeriod(Model $budgetable, string $period): int
    {
        $rows = $this->usageRows($budgetable, $this->periodStart($period));

        return $this->usesDailyStats() ? (int) $rows->sum('request_count') : $rows->count();
    }

    /**
     * Add up the tokens of the usage rows.
     *
     * @param  Builder<SpectraRequest>  $rows
     */
    protected function sumTokens(Builder $rows): int
    {
        return (int) $rows
            ->selectRaw($this->usesDailyStats() ? 'SUM(total_tokens) as total' : 'SUM(prompt_tokens + completion_tokens) as total')
            ->value('total');
    }

    /**
     * Get the rows that hold the model's usage, since a moment when given: its
     * daily stats, which every budget period starts on a day of, or else its
     * requests. Every saved call passes through here, so a heavy user's budget
     * must not cost more than a light one's.
     *
     * The key is bound as a string: trackable_id is a string column, and MySQL
     * compares it with a number as doubles, which can't use the index.
     *
     * @return Builder<SpectraRequest>
     */
    protected function usageRows(Model $budgetable, ?CarbonInterface $since = null): Builder
    {
        $onDailyStats = $this->usesDailyStats();

        return SpectraRequest::query()
            ->when($onDailyStats, fn (Builder $query) => $query->from('spectra_daily_stats'))
            ->where('trackable_type', $budgetable->getMorphClass())
            ->where('trackable_id', (string) $budgetable->getKey())
            ->when($since, fn (Builder $query, CarbonInterface $start) => $query->where($onDailyStats ? 'date' : 'created_at', '>=', $start));
    }

    /**
     * Determine if usage is read from the daily stats.
     */
    protected function usesDailyStats(): bool
    {
        return app(RollupSchema::class)->isReadable();
    }

    /**
     * Get when the current day, week or month started.
     */
    protected function periodStart(string $period): CarbonInterface
    {
        return match ($period) {
            'week' => now()->startOfWeek(),
            'month' => now()->startOfMonth(),
            default => today(),
        };
    }

    /**
     * Get the cost, token and request limits the budget sets.
     */
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

    /**
     * Get the highest share of any set limit the usage has reached, as a percentage.
     */
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

        return $percentages === [] ? 0.0 : max($percentages);
    }

    /**
     * Mark this budget / limit / level as announced for the current period,
     * returning true the first time it is reached and false after that until
     * the period or the limit changes.
     */
    protected function fireOnce(SpectraBudget $budget, string $limitType, string $level, int $limit): bool
    {
        [$periodKey, $expiresAt] = $this->period($limitType);

        $key = implode(':', ['spectra', 'budget', $budget->getKey(), $limitType, $level, $limit, $periodKey]);

        return Cache::add($key, true, $expiresAt);
    }

    /**
     * Get the cache key part and expiry for the current period of a limit type.
     *
     * @return array{0: string, 1: CarbonInterface}
     */
    protected function period(string $limitType): array
    {
        $now = now();

        return match ($limitType) {
            'daily' => [$now->format('Y-m-d'), $now->copy()->endOfDay()->addHour()],
            'weekly' => [$now->copy()->startOfWeek()->format('Y-m-d'), $now->copy()->endOfWeek()->addHour()],
            'monthly' => [$now->format('Y-m'), $now->copy()->endOfMonth()->addHour()],
            default => ['all', $now->copy()->addYear()],
        };
    }

    /**
     * Convert a cost to whole cents, rounding up.
     *
     * Budgets are enforced in whole cents, rounding up so fractional usage
     * always counts against the limit (a 0.2-cent request consumes 1 cent).
     * The sum is first rounded to the storage precision (6 decimals) so float
     * noise from SUM() can never push an exactly-on-limit total over it.
     */
    protected function toWholeCents(float|int $cost): int
    {
        if ($cost <= 0) {
            return 0;
        }

        return (int) ceil(round((float) $cost, 6));
    }
}
