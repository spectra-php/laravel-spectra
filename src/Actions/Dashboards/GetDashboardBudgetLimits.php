<?php

declare(strict_types=1);

namespace Spectra\Actions\Dashboards;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spectra\Data\DashboardBudgetLimit;
use Spectra\Support\Budget\BudgetEnforcer;

/**
 * A trackable's budget limits for a shared dashboard, each with what has been
 * used against it. Cost limits are left out of a dashboard that hides costs.
 * Budget periods are the budget's own, so the dashboard's date range doesn't
 * change them.
 */
class GetDashboardBudgetLimits
{
    /**
     * Create a new get shared budget limits action instance.
     */
    public function __construct(
        private readonly BudgetEnforcer $budgets,
    ) {}

    /**
     * Get the limits the trackable's active budget sets; empty without a budget.
     *
     * @return Collection<int, DashboardBudgetLimit>
     */
    public function __invoke(Model $trackable, bool $showCosts): Collection
    {
        $budget = $this->budgets->getBudget($trackable);

        if ($budget === null) {
            return collect();
        }

        $usage = $this->budgets->getUsage($trackable);

        $rows = [
            ['tokens', 'daily', $usage->dailyTokens, $budget->daily_token_limit],
            ['tokens', 'weekly', $usage->weeklyTokens, $budget->weekly_token_limit],
            ['tokens', 'monthly', $usage->monthlyTokens, $budget->monthly_token_limit],
            ['tokens', 'total', $usage->totalTokens, $budget->total_token_limit],
            ['requests', 'daily', $usage->dailyRequests, $budget->daily_request_limit],
            ['requests', 'weekly', $usage->weeklyRequests, $budget->weekly_request_limit],
            ['requests', 'monthly', $usage->monthlyRequests, $budget->monthly_request_limit],
        ];

        if ($showCosts) {
            $rows = [
                ['cost', 'daily', $usage->dailyCost, $budget->daily_limit],
                ['cost', 'weekly', $usage->weeklyCost, $budget->weekly_limit],
                ['cost', 'monthly', $usage->monthlyCost, $budget->monthly_limit],
                ['cost', 'total', $usage->totalCostInCents, $budget->total_limit],
                ...$rows,
            ];
        }

        return collect($rows)
            ->filter(fn (array $row) => $row[3] > 0)
            ->map(fn (array $row) => new DashboardBudgetLimit(metric: $row[0], period: $row[1], used: $row[2], limit: $row[3]))
            ->values();
    }
}
