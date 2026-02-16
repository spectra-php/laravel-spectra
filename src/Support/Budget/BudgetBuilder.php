<?php

namespace Spectra\Support\Budget;

use Illuminate\Database\Eloquent\Model;
use Spectra\Models\SpectraBudget;

class BudgetBuilder
{
    /** @var array<string, mixed> */
    protected array $attributes = [];

    public function __construct(
        protected Model $budgetable,
    ) {}

    public function dailyCostLimitInCents(int $costInCents): static
    {
        $this->attributes['daily_limit'] = $costInCents;

        return $this;
    }

    public function weeklyCostLimitInCents(int $costInCents): static
    {
        $this->attributes['weekly_limit'] = $costInCents;

        return $this;
    }

    public function monthlyCostLimitInCents(int $costInCents): static
    {
        $this->attributes['monthly_limit'] = $costInCents;

        return $this;
    }

    public function totalCostLimitInCents(int $costInCents): static
    {
        $this->attributes['total_limit'] = $costInCents;

        return $this;
    }

    public function dailyTokenLimit(int $maxTokens): static
    {
        $this->attributes['daily_token_limit'] = $maxTokens;

        return $this;
    }

    public function weeklyTokenLimit(int $maxTokens): static
    {
        $this->attributes['weekly_token_limit'] = $maxTokens;

        return $this;
    }

    public function monthlyTokenLimit(int $maxTokens): static
    {
        $this->attributes['monthly_token_limit'] = $maxTokens;

        return $this;
    }

    public function totalTokenLimit(int $maxTokens): static
    {
        $this->attributes['total_token_limit'] = $maxTokens;

        return $this;
    }

    public function dailyRequestLimit(int $maxRequests): static
    {
        $this->attributes['daily_request_limit'] = $maxRequests;

        return $this;
    }

    public function weeklyRequestLimit(int $maxRequests): static
    {
        $this->attributes['weekly_request_limit'] = $maxRequests;

        return $this;
    }

    public function monthlyRequestLimit(int $maxRequests): static
    {
        $this->attributes['monthly_request_limit'] = $maxRequests;

        return $this;
    }

    public function hardLimit(bool $enabled = true): static
    {
        $this->attributes['hard_limit'] = $enabled;

        return $this;
    }

    public function softLimit(): static
    {
        $this->attributes['hard_limit'] = false;

        return $this;
    }

    public function warningThresholdPercentage(int $percentage): static
    {
        $this->attributes['warning_threshold'] = $percentage;

        return $this;
    }

    public function criticalThresholdPercentage(int $percentage): static
    {
        $this->attributes['critical_threshold'] = $percentage;

        return $this;
    }

    /**
     * @param  array<int, string>  $providers
     */
    public function allowProviders(array $providers): static
    {
        $this->attributes['allowed_providers'] = $providers;

        return $this;
    }

    /**
     * @param  array<int, string>  $models
     */
    public function allowModels(array $models): static
    {
        $this->attributes['allowed_models'] = $models;

        return $this;
    }

    public function name(string $name): static
    {
        $this->attributes['name'] = $name;

        return $this;
    }

    public function save(): SpectraBudget
    {
        return $this->budgetable->setAiBudget($this->attributes); // @phpstan-ignore method.notFound
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }
}
