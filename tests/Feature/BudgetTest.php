<?php

use Illuminate\Support\Facades\Event;
use Spectra\Events\BudgetThresholdReached;
use Spectra\Exceptions\BudgetExceededException;
use Spectra\Models\SpectraBudget;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Budget\BudgetBuilder;
use Spectra\Support\Budget\BudgetEnforcer;
use Workbench\App\Models\User;

it('can create a budget for a user', function () {
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
    ]);

    $budget = $user->setAiBudget([
        'daily_limit' => 1000, // $10.00
        'monthly_limit' => 10000, // $100.00
        'warning_threshold' => 80,
        'critical_threshold' => 95,
    ]);

    expect($budget)->toBeInstanceOf(SpectraBudget::class)
        ->and($budget->daily_limit)->toBe(1000)
        ->and($budget->monthly_limit)->toBe(10000)
        ->and($budget->dailyLimitInDollars)->toBe(10.0);
});

it('can check if budget allows request', function () {
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
    ]);

    $user->setAiBudget([
        'daily_limit' => 1000,
    ]);

    $enforcer = app(BudgetEnforcer::class);
    $result = $enforcer->check($user, 'openai', 'gpt-4');

    expect($result['allowed'])->toBeTrue()
        ->and($result['budget'])->toBeInstanceOf(SpectraBudget::class)
        ->and($result['percentage'])->toBe(0.0);
});

it('throws exception when budget exceeded', function () {
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
    ]);

    $user->setAiBudget([
        'daily_limit' => 100, // $1.00
        'hard_limit' => true,
    ]);

    // Create a request that exceeds the budget
    SpectraRequest::create([
        'provider' => 'openai',
        'model' => 'gpt-4',
        'trackable_type' => User::class,
        'trackable_id' => $user->id,
        'response' => json_encode(['prompt' => 'test']),
        'total_cost_in_cents' => 150, // $1.50
        'status_code' => 200,
        'created_at' => now(),
    ]);

    $enforcer = app(BudgetEnforcer::class);

    expect(fn () => $enforcer->enforce($user, 'openai', 'gpt-4'))
        ->toThrow(BudgetExceededException::class);
});

it('allows request when soft limit is set', function () {
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
    ]);

    $user->setAiBudget([
        'daily_limit' => 100,
        'hard_limit' => false, // Soft limit
    ]);

    // Create a request that exceeds the budget
    SpectraRequest::create([
        'provider' => 'openai',
        'model' => 'gpt-4',
        'trackable_type' => User::class,
        'trackable_id' => $user->id,
        'response' => json_encode(['prompt' => 'test']),
        'total_cost_in_cents' => 150,
        'status_code' => 200,
        'created_at' => now(),
    ]);

    $enforcer = app(BudgetEnforcer::class);

    // Should not throw with soft limit
    $enforcer->enforce($user, 'openai', 'gpt-4');

    $result = $enforcer->check($user, 'openai', 'gpt-4');
    // With soft limit, request IS allowed even when budget exceeded
    expect($result['allowed'])->toBeTrue()
        ->and($result['percentage'])->toBeGreaterThan(100);
});

it('fires threshold reached events', function () {
    Event::fake([BudgetThresholdReached::class]);

    $user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
    ]);

    $user->setAiBudget([
        'daily_limit' => 100,
        'warning_threshold' => 80,
        'critical_threshold' => 95,
        'hard_limit' => false,
    ]);

    // Create a request at 85% of budget
    SpectraRequest::create([
        'provider' => 'openai',
        'model' => 'gpt-4',
        'trackable_type' => User::class,
        'trackable_id' => $user->id,
        'response' => json_encode(['prompt' => 'test']),
        'total_cost_in_cents' => 85,
        'status_code' => 200,
        'created_at' => now(),
    ]);

    $enforcer = app(BudgetEnforcer::class);
    $enforcer->enforce($user, 'openai', 'gpt-4');

    Event::assertDispatched(BudgetThresholdReached::class, function ($event) {
        return $event->thresholdType === 'warning';
    });
});

it('restricts provider when not allowed', function () {
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
    ]);

    $user->setAiBudget([
        'daily_limit' => 1000,
        'allowed_providers' => ['openai'], // Only OpenAI allowed
    ]);

    $enforcer = app(BudgetEnforcer::class);

    expect(fn () => $enforcer->enforce($user, 'anthropic', 'claude-3'))
        ->toThrow(BudgetExceededException::class, 'Provider "anthropic" is not allowed');
});

it('restricts model when not allowed', function () {
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
    ]);

    $user->setAiBudget([
        'daily_limit' => 1000,
        'allowed_models' => ['gpt-3.5-turbo'], // Only GPT-3.5 allowed
    ]);

    $enforcer = app(BudgetEnforcer::class);

    expect(fn () => $enforcer->enforce($user, 'openai', 'gpt-4'))
        ->toThrow(BudgetExceededException::class, 'Model "gpt-4" is not allowed');
});

it('can get remaining budget', function () {
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
    ]);

    $user->setAiBudget([
        'daily_limit' => 1000,
        'monthly_limit' => 5000,
    ]);

    // Use 300 cents
    SpectraRequest::create([
        'provider' => 'openai',
        'model' => 'gpt-4',
        'trackable_type' => User::class,
        'trackable_id' => $user->id,
        'response' => json_encode(['prompt' => 'test']),
        'total_cost_in_cents' => 300,
        'status_code' => 200,
        'created_at' => now(),
    ]);

    $remaining = $user->getRemainingBudget();

    expect($remaining['daily'])->toBe(700);
    expect($remaining['monthly'])->toBe(4700);
});

it('can disable and enable budget', function () {
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
    ]);

    $budget = $user->setAiBudget([
        'daily_limit' => 1000,
        'is_active' => true,
    ]);

    expect($budget->is_active)->toBeTrue();

    $user->disableAiBudget();
    $budget->refresh();
    expect($budget->is_active)->toBeFalse();

    $user->enableAiBudget();
    $budget->refresh();
    expect($budget->is_active)->toBeTrue();
});

it('can check budget status', function () {
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
    ]);

    $user->setAiBudget([
        'daily_limit' => 1000,
    ]);

    // Use 500 cents
    SpectraRequest::create([
        'provider' => 'openai',
        'model' => 'gpt-4',
        'trackable_type' => User::class,
        'trackable_id' => $user->id,
        'response' => json_encode(['prompt' => 'test']),
        'total_cost_in_cents' => 500,
        'status_code' => 200,
        'created_at' => now(),
    ]);

    $status = $user->getBudgetStatus('openai', 'gpt-4');

    expect($status['allowed'])->toBeTrue();
    expect($status['percentage'])->toBe(50.0);
    expect($status['usage']['dailyCost'])->toBe(500);
});

it('enforces token limits', function () {
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
    ]);

    $user->setAiBudget([
        'daily_token_limit' => 1000,
        'hard_limit' => true,
    ]);

    // Use 1500 tokens
    SpectraRequest::create([
        'provider' => 'openai',
        'model' => 'gpt-4',
        'trackable_type' => User::class,
        'trackable_id' => $user->id,
        'response' => json_encode(['prompt' => 'test']),
        'prompt_tokens' => 1000,
        'completion_tokens' => 500,
        'total_cost_in_cents' => 0,
        'status_code' => 200,
        'created_at' => now(),
    ]);

    $enforcer = app(BudgetEnforcer::class);

    expect(fn () => $enforcer->enforce($user, 'openai', 'gpt-4'))
        ->toThrow(BudgetExceededException::class, 'token budget exceeded');
});

it('enforces request limits', function () {
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
    ]);

    $user->setAiBudget([
        'daily_request_limit' => 2,
        'hard_limit' => true,
    ]);

    // Create 3 requests
    for ($i = 0; $i < 3; $i++) {
        SpectraRequest::create([
            'provider' => 'openai',
            'model' => 'gpt-4',
            'trackable_type' => User::class,
            'trackable_id' => $user->id,
            'response' => json_encode(['prompt' => 'test']),
            'total_cost_in_cents' => 0,
            'status_code' => 200,
            'created_at' => now(),
        ]);
    }

    $enforcer = app(BudgetEnforcer::class);

    expect(fn () => $enforcer->enforce($user, 'openai', 'gpt-4'))
        ->toThrow(BudgetExceededException::class, 'request limit exceeded');
});

it('rounds fractional request costs up to whole cents for budget enforcement', function () {
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test-rounding@example.com',
        'password' => 'password',
    ]);

    $user->setAiBudget([
        'daily_limit' => 1,
        'hard_limit' => true,
    ]);

    SpectraRequest::create([
        'provider' => 'openai',
        'model' => 'gpt-4',
        'trackable_type' => User::class,
        'trackable_id' => $user->id,
        'response' => json_encode(['prompt' => 'test']),
        'total_cost_in_cents' => 0.2,
        'status_code' => 200,
        'created_at' => now(),
    ]);

    $enforcer = app(BudgetEnforcer::class);
    $status = $enforcer->check($user, 'openai', 'gpt-4');

    expect($status['usage']['dailyCost'])->toBe(1);
    expect(fn () => $enforcer->enforce($user, 'openai', 'gpt-4'))
        ->toThrow(BudgetExceededException::class);
});

it('returns a BudgetBuilder from configureAiBudget', function () {
    $user = User::create([
        'name' => 'Test User',
        'email' => 'builder-instance@example.com',
        'password' => 'password',
    ]);

    $builder = $user->configureAiBudget();

    expect($builder)->toBeInstanceOf(BudgetBuilder::class);
});

it('can create a budget using fluent builder', function () {
    $user = User::create([
        'name' => 'Test User',
        'email' => 'builder-create@example.com',
        'password' => 'password',
    ]);

    $budget = $user->configureAiBudget()
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

it('can create a budget with soft limit using fluent builder', function () {
    $user = User::create([
        'name' => 'Test User',
        'email' => 'builder-soft@example.com',
        'password' => 'password',
    ]);

    $budget = $user->configureAiBudget()
        ->dailyCostLimitInCents(1000)
        ->softLimit()
        ->save();

    expect($budget->daily_limit)->toBe(1000)
        ->and($budget->hard_limit)->toBeFalse();
});

it('can update an existing budget using fluent builder', function () {
    $user = User::create([
        'name' => 'Test User',
        'email' => 'builder-update@example.com',
        'password' => 'password',
    ]);

    $user->configureAiBudget()
        ->dailyCostLimitInCents(1000)
        ->save();

    $updated = $user->configureAiBudget()
        ->dailyCostLimitInCents(2000)
        ->weeklyCostLimitInCents(10000)
        ->save();

    expect($updated->daily_limit)->toBe(2000)
        ->and($updated->weekly_limit)->toBe(10000)
        ->and(SpectraBudget::where('budgetable_id', $user->id)->count())->toBe(1);
});

it('builder methods return the builder for chaining', function () {
    $user = User::create([
        'name' => 'Test User',
        'email' => 'builder-chaining@example.com',
        'password' => 'password',
    ]);

    $builder = $user->configureAiBudget();

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

it('builder toArray reflects all set methods', function () {
    $user = User::create([
        'name' => 'Test User',
        'email' => 'builder-toarray@example.com',
        'password' => 'password',
    ]);

    $builder = $user->configureAiBudget()
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

it('builder save persists budget to database', function () {
    $user = User::create([
        'name' => 'Test User',
        'email' => 'builder-save@example.com',
        'password' => 'password',
    ]);

    $budget = $user->configureAiBudget()
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

it('fluent builder budget works with enforcement', function () {
    $user = User::create([
        'name' => 'Test User',
        'email' => 'builder-enforce@example.com',
        'password' => 'password',
    ]);

    $user->configureAiBudget()
        ->dailyCostLimitInCents(100)
        ->hardLimit()
        ->save();

    SpectraRequest::create([
        'provider' => 'openai',
        'model' => 'gpt-4',
        'trackable_type' => User::class,
        'trackable_id' => $user->id,
        'response' => json_encode(['prompt' => 'test']),
        'total_cost_in_cents' => 150,
        'status_code' => 200,
        'created_at' => now(),
    ]);

    $enforcer = app(BudgetEnforcer::class);

    expect(fn () => $enforcer->enforce($user, 'openai', 'gpt-4'))
        ->toThrow(BudgetExceededException::class);
});
