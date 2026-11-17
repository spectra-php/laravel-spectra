<?php

declare(strict_types=1);

namespace Spectra\Support\Budget;

use Illuminate\Support\Collection;
use Spectra\Data\TrackableBudgetLimit;
use Spectra\Models\SpectraBudget;
use Spectra\Models\SpectraRequest;

/**
 * Reads and writes a trackable's budget for the dashboard, which knows a
 * trackable only by its stored morph type and id. It works on the same row
 * as `$model->setAiBudget()`, so a budget set in code shows on the dashboard
 * and one set on the dashboard is the one the app enforces.
 */
class TrackableBudgets
{
    /**
     * Every limit a budget can set: metric, then period, then the column that
     * holds it and the usage it is compared with. Requests have no all-time
     * limit.
     */
    public const LIMITS = [
        'cost' => [
            'daily' => ['daily_limit', 'dailyCost'],
            'weekly' => ['weekly_limit', 'weeklyCost'],
            'monthly' => ['monthly_limit', 'monthlyCost'],
            'total' => ['total_limit', 'totalCostInCents'],
        ],
        'tokens' => [
            'daily' => ['daily_token_limit', 'dailyTokens'],
            'weekly' => ['weekly_token_limit', 'weeklyTokens'],
            'monthly' => ['monthly_token_limit', 'monthlyTokens'],
            'total' => ['total_token_limit', 'totalTokens'],
        ],
        'requests' => [
            'daily' => ['daily_request_limit', 'dailyRequests'],
            'weekly' => ['weekly_request_limit', 'weeklyRequests'],
            'monthly' => ['monthly_request_limit', 'monthlyRequests'],
        ],
    ];

    /**
     * Create a new trackable budgets instance.
     */
    public function __construct(
        protected BudgetEnforcer $enforcer,
    ) {}

    /**
     * Find the trackable's budget, whether it is on or off.
     */
    public function find(string $type, string $id): ?SpectraBudget
    {
        return SpectraBudget::query()
            ->where('budgetable_type', $type)
            ->where('budgetable_id', $id)
            ->orderByDesc('is_active')
            ->first();
    }

    /**
     * Get the limits the budget sets, each with what has been used against it.
     *
     * @return Collection<int, TrackableBudgetLimit>
     */
    public function limits(SpectraBudget $budget): Collection
    {
        $usage = $this->enforcer->usageFor($budget->budgetable_type, $budget->budgetable_id);
        $limits = collect();

        foreach (self::LIMITS as $metric => $periods) {
            foreach ($periods as $period => [$column, $usageKey]) {
                $limit = (int) $budget->getAttribute($column);

                if ($limit > 0) {
                    $limits->push(new TrackableBudgetLimit(metric: $metric, period: $period, used: $usage[$usageKey], limit: $limit));
                }
            }
        }

        return $limits;
    }

    /**
     * Save the given limits as the trackable's budget, clearing every limit
     * that isn't listed. The budget's name, thresholds and allowed providers
     * and models are left as they are.
     *
     * @param  list<array{metric: string, period: string, limit: int}>  $limits
     */
    public function save(string $type, string $id, array $limits, bool $hardLimit, bool $isActive): SpectraBudget
    {
        $attributes = ['hard_limit' => $hardLimit, 'is_active' => $isActive];

        foreach (self::LIMITS as $periods) {
            foreach ($periods as [$column]) {
                $attributes[$column] = null;
            }
        }

        foreach ($limits as $limit) {
            [$column] = self::LIMITS[$limit['metric']][$limit['period']];

            $attributes[$column] = $limit['limit'];
        }

        $budget = $this->find($type, $id) ?? new SpectraBudget(['budgetable_type' => $type, 'budgetable_id' => $id]);

        $budget->fill($attributes)->save();

        return $budget;
    }

    /**
     * Delete the trackable's budget.
     */
    public function remove(string $type, string $id): void
    {
        SpectraBudget::query()
            ->where('budgetable_type', $type)
            ->where('budgetable_id', $id)
            ->delete();
    }

    /**
     * Get the trackables of a list page that have a budget switched on, keyed
     * as `type:id`, in one query.
     *
     * @param  Collection<int, SpectraRequest>  $rows
     * @return array<string, true>
     */
    public function activeKeys(Collection $rows): array
    {
        $rows = $rows->filter(fn (SpectraRequest $row) => filled($row->trackable_type) && filled($row->trackable_id));

        if ($rows->isEmpty()) {
            return [];
        }

        $keys = SpectraBudget::query()
            ->active()
            ->whereIn('budgetable_type', $rows->pluck('trackable_type')->unique()->values()->all())
            ->whereIn('budgetable_id', $rows->pluck('trackable_id')->map(fn ($id) => (string) $id)->unique()->values()->all())
            ->get(['budgetable_type', 'budgetable_id'])
            ->map(fn (SpectraBudget $budget) => "{$budget->budgetable_type}:{$budget->budgetable_id}")
            ->all();

        return array_fill_keys($keys, true);
    }
}
