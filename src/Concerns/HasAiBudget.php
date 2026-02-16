<?php

namespace Spectra\Concerns;

use Spectra\Data\BudgetStatus;
use Spectra\Data\RemainingBudget;
use Spectra\Models\SpectraBudget;
use Spectra\Support\Budget\BudgetBuilder;
use Spectra\Support\Budget\BudgetEnforcer;

/**
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 *
 * @phpstan-ignore trait.unused
 */
trait HasAiBudget
{
    public function configureAiBudget(): BudgetBuilder
    {
        return new BudgetBuilder($this);
    }

    public function setAiBudget(array $attributes): SpectraBudget
    {
        return $this->aiBudget()->updateOrCreate(
            ['budgetable_type' => $this->getMorphClass(), 'budgetable_id' => $this->getKey()],
            $attributes
        );
    }

    public function hasExceededBudget(string $provider, string $model): bool
    {
        $enforcer = app(BudgetEnforcer::class);
        $result = $enforcer->check($this, $provider, $model);

        return ! $result->allowed;
    }

    public function getBudgetStatus(string $provider = 'openai', string $model = 'gpt-4'): BudgetStatus
    {
        $enforcer = app(BudgetEnforcer::class);

        return $enforcer->check($this, $provider, $model);
    }

    public function getRemainingBudget(): RemainingBudget
    {
        $budget = $this->activeAiBudget()->first();

        if (! $budget) {
            return new RemainingBudget;
        }

        $enforcer = app(BudgetEnforcer::class);
        $usage = $enforcer->getUsage($this);

        return new RemainingBudget(
            daily: $budget->daily_limit ? max(0, $budget->daily_limit - $usage->dailyCost) : null,
            weekly: $budget->weekly_limit ? max(0, $budget->weekly_limit - $usage->weeklyCost) : null,
            monthly: $budget->monthly_limit ? max(0, $budget->monthly_limit - $usage->monthlyCost) : null,
            total: $budget->total_limit ? max(0, $budget->total_limit - $usage->totalCostInCents) : null,
        );
    }

    public function disableAiBudget(): bool
    {
        return (bool) $this->aiBudget()->update(['is_active' => false]);
    }

    public function enableAiBudget(): bool
    {
        return (bool) $this->aiBudget()->update(['is_active' => true]);
    }

    public function removeAiBudget(): bool
    {
        return (bool) $this->aiBudget()->delete();
    }
}
