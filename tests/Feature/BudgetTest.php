<?php

use Illuminate\Support\Facades\Event;
use Spectra\Events\BudgetThresholdReached;
use Spectra\Exceptions\BudgetExceededException;
use Spectra\Models\SpectraBudget;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Budget\BudgetBuilder;
use Spectra\Support\Budget\BudgetEnforcer;
use Workbench\App\Models\User;

beforeEach(function () {
    $this->defaultProvider = config('spectra.budget.default_provider');
    $this->defaultModel = config('spectra.budget.default_model');

    /** @var User $user */
    $this->user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
    ]);
});

it('should create a budget for a user', function () {
    $this->user->configureAiBudget()
        ->dailyCostLimitInCents(1000)
        ->monthlyCostLimitInCents(10000)
        ->warningThresholdPercentage(80)
        ->criticalThresholdPercentage(95)
        ->save();

    $this->assertDatabaseHas('spectra_budgets', [
        'budgetable_type' => User::class,
        'budgetable_id' => $this->user->id,
        'daily_limit' => 1000,
        'monthly_limit' => 10000,
        'warning_threshold' => 80,
        'critical_threshold' => 95,
    ]);
});

it('should check if budget allows request', function () {
    $this->user->configureAiBudget()
        ->dailyCostLimitInCents(1000)
        ->save();

    $enforcer = app(BudgetEnforcer::class);
    $result = $enforcer->check($this->user, $this->defaultProvider, $this->defaultModel);

    expect($result->allowed)->toBeTrue()
        ->and($result->budget)->toBeInstanceOf(SpectraBudget::class)
        ->and($result->percentage)->toBe(0.0);
});

it('should throw exception when budget exceeded', function () {
    $this->user->configureAiBudget()
        ->dailyCostLimitInCents(100)
        ->hardLimit()
        ->save();

    SpectraRequest::factory()->forTrackable($this->user)->withCost(150)->create();

    $enforcer = app(BudgetEnforcer::class);

    expect(fn () => $enforcer->enforce($this->user, $this->defaultProvider, $this->defaultModel))
        ->toThrow(BudgetExceededException::class);
});

it('should allow request when soft limit is set', function () {
    $this->user->configureAiBudget()
        ->dailyCostLimitInCents(100)
        ->softLimit()
        ->save();

    SpectraRequest::factory()->forTrackable($this->user)->withCost(150)->create();

    $enforcer = app(BudgetEnforcer::class);

    // Should not throw with soft limit
    $enforcer->enforce($this->user, $this->defaultProvider, $this->defaultModel);

    $result = $enforcer->check($this->user, $this->defaultProvider, $this->defaultModel);
    // With soft limit, request IS allowed even when budget exceeded
    expect($result->allowed)->toBeTrue()
        ->and($result->percentage)->toBeGreaterThan(100);
});

it('should fire threshold reached events', function () {
    Event::fake([BudgetThresholdReached::class]);

    $this->user->configureAiBudget()
        ->dailyCostLimitInCents(100)
        ->warningThresholdPercentage(80)
        ->criticalThresholdPercentage(95)
        ->softLimit()
        ->save();

    SpectraRequest::factory()->forTrackable($this->user)->withCost(85)->create();

    $enforcer = app(BudgetEnforcer::class);
    $enforcer->enforce($this->user, $this->defaultProvider, $this->defaultModel);

    Event::assertDispatched(BudgetThresholdReached::class, function ($event) {
        return $event->thresholdType === 'warning';
    });
});

it('should restrict provider when not allowed', function () {
    $this->user->configureAiBudget()
        ->dailyCostLimitInCents(1000)
        ->allowProviders([$this->defaultProvider])
        ->save();

    $enforcer = app(BudgetEnforcer::class);

    expect(fn () => $enforcer->enforce($this->user, 'anthropic', 'claude-3'))
        ->toThrow(BudgetExceededException::class, 'Provider "anthropic" is not allowed');
});

it('should restrict model when not allowed', function () {
    $this->user->configureAiBudget()
        ->dailyCostLimitInCents(1000)
        ->allowModels(['gpt-3.5-turbo'])
        ->save();

    $enforcer = app(BudgetEnforcer::class);

    expect(fn () => $enforcer->enforce($this->user, $this->defaultProvider, $this->defaultModel))
        ->toThrow(BudgetExceededException::class, "Model \"{$this->defaultModel}\" is not allowed");
});

it('should get remaining budget', function () {
    $this->user->configureAiBudget()
        ->dailyCostLimitInCents(1000)
        ->monthlyCostLimitInCents(5000)
        ->save();

    SpectraRequest::factory()->forTrackable($this->user)->withCost(300)->create();

    $remaining = $this->user->getRemainingBudget();

    expect($remaining['daily'])->toBe(700)
        ->and($remaining['monthly'])->toBe(4700);
});

it('should disable and enable budget', function () {
    $budget = $this->user->configureAiBudget()
        ->dailyCostLimitInCents(1000)
        ->save();

    expect($budget->is_active)->toBeTrue();

    $this->user->disableAiBudget();
    $budget->refresh();
    expect($budget->is_active)->toBeFalse();

    $this->user->enableAiBudget();
    $budget->refresh();
    expect($budget->is_active)->toBeTrue();
});

it('should check budget status', function () {
    $this->user->configureAiBudget()
        ->dailyCostLimitInCents(1000)
        ->save();

    SpectraRequest::factory()->forTrackable($this->user)->withCost(500)->create();

    $status = $this->user->getBudgetStatus($this->defaultProvider, $this->defaultModel);

    expect($status->allowed)->toBeTrue()
        ->and($status->percentage)->toBe(50.0)
        ->and($status->usage->dailyCost)->toBe(500);
});

it('should enforce token limits', function () {
    $this->user->configureAiBudget()
        ->dailyTokenLimit(1000)
        ->hardLimit()
        ->save();

    SpectraRequest::factory()->forTrackable($this->user)->withTokens(1000, 500)->create();

    $enforcer = app(BudgetEnforcer::class);

    expect(fn () => $enforcer->enforce($this->user, $this->defaultProvider, $this->defaultModel))
        ->toThrow(BudgetExceededException::class, 'token budget exceeded');
});

it('should enforce request limits', function () {
    $this->user->configureAiBudget()
        ->dailyRequestLimit(2)
        ->hardLimit()
        ->save();

    SpectraRequest::factory()->forTrackable($this->user)->count(3)->create();

    $enforcer = app(BudgetEnforcer::class);

    expect(fn () => $enforcer->enforce($this->user, $this->defaultProvider, $this->defaultModel))
        ->toThrow(BudgetExceededException::class, 'request limit exceeded');
});

it('should round fractional request costs up to whole cents for budget enforcement', function () {
    $this->user->configureAiBudget()
        ->dailyCostLimitInCents(1)
        ->hardLimit()
        ->save();

    SpectraRequest::factory()->forTrackable($this->user)->withCost(0.2)->create();

    $enforcer = app(BudgetEnforcer::class);
    $status = $enforcer->check($this->user, $this->defaultProvider, $this->defaultModel);

    expect($status->usage->dailyCost)->toBe(1)
        ->and(fn () => $enforcer->enforce($this->user, $this->defaultProvider, $this->defaultModel))
        ->toThrow(BudgetExceededException::class);
});

it('should return a BudgetBuilder from configureAiBudget', function () {
    $builder = $this->user->configureAiBudget();

    expect($builder)->toBeInstanceOf(BudgetBuilder::class);
});

it('should persist budget to database', function () {
    $budget = $this->user->configureAiBudget()
        ->dailyCostLimitInCents(1000)
        ->monthlyTokenLimit(5000000)
        ->dailyRequestLimit(200)
        ->hardLimit()
        ->warningThresholdPercentage(75)
        ->criticalThresholdPercentage(90)
        ->save();

    expect($budget)->toBeInstanceOf(SpectraBudget::class)
        ->and($budget->exists)->toBeTrue()
        ->and($budget->daily_limit)->toBe(1000)
        ->and($budget->monthly_token_limit)->toBe(5000000)
        ->and($budget->daily_request_limit)->toBe(200)
        ->and($budget->hard_limit)->toBeTrue()
        ->and($budget->warning_threshold)->toBe(75)
        ->and($budget->critical_threshold)->toBe(90);
});

it('should create a budget using fluent builder', function () {
    $budget = $this->user->configureAiBudget()
        ->dailyCostLimitInCents(1000)
        ->weeklyCostLimitInCents(5000)
        ->monthlyCostLimitInCents(20000)
        ->totalCostLimitInCents(100000)
        ->dailyTokenLimit(500000)
        ->weeklyTokenLimit(2000000)
        ->monthlyTokenLimit(5000000)
        ->totalTokenLimit(10000000)
        ->dailyRequestLimit(200)
        ->weeklyRequestLimit(1000)
        ->monthlyRequestLimit(5000)
        ->hardLimit()
        ->warningThresholdPercentage(80)
        ->criticalThresholdPercentage(95)
        ->allowProviders(['openai', 'anthropic'])
        ->allowModels(['gpt-4o', 'claude-sonnet-4-20250514'])
        ->name('Production Budget')
        ->save();

    expect($budget)->toBeInstanceOf(SpectraBudget::class)
        ->and($budget->daily_limit)->toBe(1000)
        ->and($budget->weekly_limit)->toBe(5000)
        ->and($budget->monthly_limit)->toBe(20000)
        ->and($budget->total_limit)->toBe(100000)
        ->and($budget->daily_token_limit)->toBe(500000)
        ->and($budget->weekly_token_limit)->toBe(2000000)
        ->and($budget->monthly_token_limit)->toBe(5000000)
        ->and($budget->total_token_limit)->toBe(10000000)
        ->and($budget->daily_request_limit)->toBe(200)
        ->and($budget->weekly_request_limit)->toBe(1000)
        ->and($budget->monthly_request_limit)->toBe(5000)
        ->and($budget->hard_limit)->toBeTrue()
        ->and($budget->warning_threshold)->toBe(80)
        ->and($budget->critical_threshold)->toBe(95)
        ->and($budget->allowed_providers)->toBe(['openai', 'anthropic'])
        ->and($budget->allowed_models)->toBe(['gpt-4o', 'claude-sonnet-4-20250514'])
        ->and($budget->name)->toBe('Production Budget');
});

it('should create a budget with soft limit using fluent builder', function () {
    $budget = $this->user->configureAiBudget()
        ->dailyCostLimitInCents(1000)
        ->softLimit()
        ->save();

    expect($budget->daily_limit)->toBe(1000)
        ->and($budget->hard_limit)->toBeFalse();
});

it('should update an existing budget using fluent builder', function () {
    $this->user->configureAiBudget()
        ->dailyCostLimitInCents(1000)
        ->save();

    $updated = $this->user->configureAiBudget()
        ->dailyCostLimitInCents(2000)
        ->weeklyCostLimitInCents(10000)
        ->save();

    expect($updated->daily_limit)->toBe(2000)
        ->and($updated->weekly_limit)->toBe(10000)
        ->and(SpectraBudget::where('budgetable_id', $this->user->id)->count())->toBe(1);
});

it('should return the builder for chaining from all methods', function () {
    $builder = $this->user->configureAiBudget();

    expect($builder->dailyCostLimitInCents(1000))->toBeInstanceOf(BudgetBuilder::class)
        ->and($builder->weeklyCostLimitInCents(5000))->toBeInstanceOf(BudgetBuilder::class)
        ->and($builder->monthlyCostLimitInCents(20000))->toBeInstanceOf(BudgetBuilder::class)
        ->and($builder->totalCostLimitInCents(100000))->toBeInstanceOf(BudgetBuilder::class)
        ->and($builder->dailyTokenLimit(500000))->toBeInstanceOf(BudgetBuilder::class)
        ->and($builder->weeklyTokenLimit(2000000))->toBeInstanceOf(BudgetBuilder::class)
        ->and($builder->monthlyTokenLimit(5000000))->toBeInstanceOf(BudgetBuilder::class)
        ->and($builder->totalTokenLimit(10000000))->toBeInstanceOf(BudgetBuilder::class)
        ->and($builder->dailyRequestLimit(200))->toBeInstanceOf(BudgetBuilder::class)
        ->and($builder->weeklyRequestLimit(1000))->toBeInstanceOf(BudgetBuilder::class)
        ->and($builder->monthlyRequestLimit(5000))->toBeInstanceOf(BudgetBuilder::class)
        ->and($builder->hardLimit())->toBeInstanceOf(BudgetBuilder::class)
        ->and($builder->softLimit())->toBeInstanceOf(BudgetBuilder::class)
        ->and($builder->warningThresholdPercentage(80))->toBeInstanceOf(BudgetBuilder::class)
        ->and($builder->criticalThresholdPercentage(95))->toBeInstanceOf(BudgetBuilder::class)
        ->and($builder->allowProviders(['openai']))->toBeInstanceOf(BudgetBuilder::class)
        ->and($builder->allowModels(['gpt-4o']))->toBeInstanceOf(BudgetBuilder::class)
        ->and($builder->name('Test'))->toBeInstanceOf(BudgetBuilder::class);
});

it('should reflect all set methods in builder toArray', function () {
    $builder = $this->user->configureAiBudget()
        ->dailyCostLimitInCents(1000)
        ->weeklyCostLimitInCents(5000)
        ->monthlyCostLimitInCents(20000)
        ->totalCostLimitInCents(100000)
        ->dailyTokenLimit(500000)
        ->weeklyTokenLimit(2000000)
        ->monthlyTokenLimit(5000000)
        ->totalTokenLimit(10000000)
        ->dailyRequestLimit(200)
        ->weeklyRequestLimit(1000)
        ->monthlyRequestLimit(5000)
        ->hardLimit()
        ->warningThresholdPercentage(80)
        ->criticalThresholdPercentage(95)
        ->allowProviders(['openai', 'anthropic'])
        ->allowModels(['gpt-4o'])
        ->name('Test Budget');

    expect($builder->toArray())->toBe([
        'daily_limit' => 1000,
        'weekly_limit' => 5000,
        'monthly_limit' => 20000,
        'total_limit' => 100000,
        'daily_token_limit' => 500000,
        'weekly_token_limit' => 2000000,
        'monthly_token_limit' => 5000000,
        'total_token_limit' => 10000000,
        'daily_request_limit' => 200,
        'weekly_request_limit' => 1000,
        'monthly_request_limit' => 5000,
        'hard_limit' => true,
        'warning_threshold' => 80,
        'critical_threshold' => 95,
        'allowed_providers' => ['openai', 'anthropic'],
        'allowed_models' => ['gpt-4o'],
        'name' => 'Test Budget',
    ]);
});

it('should enforce fluent builder budget', function () {
    $this->user->configureAiBudget()
        ->dailyCostLimitInCents(100)
        ->hardLimit()
        ->save();

    SpectraRequest::factory()->forTrackable($this->user)->withCost(150)->create();

    $enforcer = app(BudgetEnforcer::class);

    expect(fn () => $enforcer->enforce($this->user, $this->defaultProvider, $this->defaultModel))
        ->toThrow(BudgetExceededException::class);
});
