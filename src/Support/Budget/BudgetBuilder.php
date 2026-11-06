<?php

declare(strict_types=1);

namespace Spectra\Support\Budget;

use Illuminate\Database\Eloquent\Model;
use Spectra\Models\SpectraBudget;

/**
 * Fluent builder behind `$user->configureAiBudget()`: collects the limits,
 * thresholds and allow-lists, then creates or updates the model's budget
 * in one write.
 */
class BudgetBuilder
{
    /** @var array<string, mixed> */
    protected array $attributes = [];

    /**
     * Create a new budget builder for the given budgetable model.
     */
    public function __construct(
        protected Model $budgetable,
    ) {}

    /**
     * Set the most the model may spend per day, in cents.
     */
    public function dailyCostLimitInCents(int $costInCents): static
    {
        $this->attributes['daily_limit'] = $costInCents;

        return $this;
    }

    /**
     * Set the most the model may spend per week, in cents.
     */
    public function weeklyCostLimitInCents(int $costInCents): static
    {
        $this->attributes['weekly_limit'] = $costInCents;

        return $this;
    }

    /**
     * Set the most the model may spend per month, in cents.
     */
    public function monthlyCostLimitInCents(int $costInCents): static
    {
        $this->attributes['monthly_limit'] = $costInCents;

        return $this;
    }

    /**
     * Set the most the model may spend in all, in cents.
     */
    public function totalCostLimitInCents(int $costInCents): static
    {
        $this->attributes['total_limit'] = $costInCents;

        return $this;
    }

    /**
     * Set the most prompt and completion tokens the model may use per day.
     */
    public function dailyTokenLimit(int $maxTokens): static
    {
        $this->attributes['daily_token_limit'] = $maxTokens;

        return $this;
    }

    /**
     * Set the most prompt and completion tokens the model may use per week.
     */
    public function weeklyTokenLimit(int $maxTokens): static
    {
        $this->attributes['weekly_token_limit'] = $maxTokens;

        return $this;
    }

    /**
     * Set the most prompt and completion tokens the model may use per month.
     */
    public function monthlyTokenLimit(int $maxTokens): static
    {
        $this->attributes['monthly_token_limit'] = $maxTokens;

        return $this;
    }

    /**
     * Set the most prompt and completion tokens the model may use in all.
     */
    public function totalTokenLimit(int $maxTokens): static
    {
        $this->attributes['total_token_limit'] = $maxTokens;

        return $this;
    }

    /**
     * Set the most AI requests the model may make per day.
     */
    public function dailyRequestLimit(int $maxRequests): static
    {
        $this->attributes['daily_request_limit'] = $maxRequests;

        return $this;
    }

    /**
     * Set the most AI requests the model may make per week.
     */
    public function weeklyRequestLimit(int $maxRequests): static
    {
        $this->attributes['weekly_request_limit'] = $maxRequests;

        return $this;
    }

    /**
     * Set the most AI requests the model may make per month.
     */
    public function monthlyRequestLimit(int $maxRequests): static
    {
        $this->attributes['monthly_request_limit'] = $maxRequests;

        return $this;
    }

    /**
     * Set whether reaching a limit blocks further requests.
     */
    public function hardLimit(bool $enabled = true): static
    {
        $this->attributes['hard_limit'] = $enabled;

        return $this;
    }

    /**
     * Make the budget only report reaching a limit, without blocking requests.
     */
    public function softLimit(): static
    {
        $this->attributes['hard_limit'] = false;

        return $this;
    }

    /**
     * Set the percentage of a cost limit that fires the warning event.
     */
    public function warningThresholdPercentage(int $percentage): static
    {
        $this->attributes['warning_threshold'] = $percentage;

        return $this;
    }

    /**
     * Set the percentage of a cost limit that fires the critical event.
     */
    public function criticalThresholdPercentage(int $percentage): static
    {
        $this->attributes['critical_threshold'] = $percentage;

        return $this;
    }

    /**
     * Restrict the budget to these provider slugs.
     *
     * @param  array<int, string>  $providers
     */
    public function allowProviders(array $providers): static
    {
        $this->attributes['allowed_providers'] = $providers;

        return $this;
    }

    /**
     * Restrict the budget to these model ids.
     *
     * @param  array<int, string>  $models
     */
    public function allowModels(array $models): static
    {
        $this->attributes['allowed_models'] = $models;

        return $this;
    }

    /**
     * Set the budget's name.
     */
    public function name(string $name): static
    {
        $this->attributes['name'] = $name;

        return $this;
    }

    /**
     * Create or update the model's budget with the collected settings.
     */
    public function save(): SpectraBudget
    {
        return $this->budgetable->setAiBudget($this->attributes); // @phpstan-ignore method.notFound
    }

    /**
     * Get the collected budget attributes.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }
}
