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

    public function dailyLimit(int $cents): static
    {
        $this->attributes['daily_limit'] = $cents;

        return $this;
    }

    public function weeklyLimit(int $cents): static
    {
        $this->attributes['weekly_limit'] = $cents;

        return $this;
    }

    public function monthlyLimit(int $cents): static
    {
        $this->attributes['monthly_limit'] = $cents;

        return $this;
    }

    public function totalLimit(int $cents): static
    {
        $this->attributes['total_limit'] = $cents;

        return $this;
    }

    public function dailyTokenLimit(int $tokens): static
    {
        $this->attributes['daily_token_limit'] = $tokens;

        return $this;
    }

    public function weeklyTokenLimit(int $tokens): static
    {
        $this->attributes['weekly_token_limit'] = $tokens;

        return $this;
    }

    public function monthlyTokenLimit(int $tokens): static
    {
        $this->attributes['monthly_token_limit'] = $tokens;

        return $this;
    }

    public function totalTokenLimit(int $tokens): static
    {
        $this->attributes['total_token_limit'] = $tokens;

        return $this;
    }

    public function dailyRequestLimit(int $count): static
    {
        $this->attributes['daily_request_limit'] = $count;

        return $this;
    }

    public function weeklyRequestLimit(int $count): static
    {
        $this->attributes['weekly_request_limit'] = $count;

        return $this;
    }

    public function monthlyRequestLimit(int $count): static
    {
        $this->attributes['monthly_request_limit'] = $count;

        return $this;
    }

    public function hardLimit(bool $hard = true): static
    {
        $this->attributes['hard_limit'] = $hard;

        return $this;
    }

    public function softLimit(): static
    {
        $this->attributes['hard_limit'] = false;

        return $this;
    }

    public function warningThreshold(int $percentage): static
    {
        $this->attributes['warning_threshold'] = $percentage;

        return $this;
    }

    public function criticalThreshold(int $percentage): static
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
