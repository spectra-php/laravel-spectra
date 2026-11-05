<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spectra\Events\BudgetThresholdReached;
use Spectra\Exceptions\BudgetExceededException;
use Spectra\Models\SpectraBudget;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Budget\BudgetEnforcer;
use Workbench\App\Models\User;

/**
 * Budgets configured on a model with HasAiBudget: the fluent builder that
 * saves them, the enforcer that allows, warns or blocks a request by the
 * spend of each period (day, week, month, all time), and the remaining
 * budget and status the model reports.
 */
beforeEach(function () {
    $this->defaultProvider = config('spectra.budget.default_provider');
    $this->defaultModel = config('spectra.budget.default_model');

    $this->user = User::query()->create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
    ]);
});

it('throws BudgetExceededException when spend passes a hard daily cost limit', function () {
    $this->user->configureAiBudget()
        ->dailyCostLimitInCents(100)
        ->hardLimit()
        ->save();

    SpectraRequest::factory()->forTrackable($this->user)->withCost(150)->create();

    expect(fn () => app(BudgetEnforcer::class)->enforce($this->user, $this->defaultProvider, $this->defaultModel))
        ->toThrow(BudgetExceededException::class);
});

it('blocks on a hard weekly limit only once spend inside the week reaches it', function () {
    // A Wednesday: Saturday the 7th is last week and Tuesday the 10th this
    // week, whether the week starts on Sunday or Monday.
    $this->travelTo('2026-03-11 12:00:00');
    $this->user->configureAiBudget()
        ->weeklyCostLimitInCents(100)
        ->hardLimit()
        ->save();
    SpectraRequest::factory()->forTrackable($this->user)->withCost(100)->create(['created_at' => '2026-03-07 23:00:00']);
    $enforcer = app(BudgetEnforcer::class);
    $enforce = fn () => $enforcer->enforce($this->user, $this->defaultProvider, $this->defaultModel);

    expect($enforce)->not->toThrow(BudgetExceededException::class);

    SpectraRequest::factory()->forTrackable($this->user)->withCost(100)->create(['created_at' => '2026-03-10 09:00:00']);

    expect($enforce)->toThrow(fn (BudgetExceededException $exception) => expect($exception->limitType)->toBe('weekly')
        ->and($exception->current)->toBe(100)
        ->and($exception->limit)->toBe(100)
        ->and($exception->getMessage())->toBe('AI budget exceeded for weekly. Weekly limit: $1.00, current usage: $1.00'));
});

it('blocks on a hard monthly limit only once spend inside the month reaches it', function () {
    $this->travelTo('2026-03-11 12:00:00');
    $this->user->configureAiBudget()
        ->monthlyCostLimitInCents(100)
        ->hardLimit()
        ->save();
    SpectraRequest::factory()->forTrackable($this->user)->withCost(100)->create(['created_at' => '2026-02-27 12:00:00']);
    $enforcer = app(BudgetEnforcer::class);
    $enforce = fn () => $enforcer->enforce($this->user, $this->defaultProvider, $this->defaultModel);

    expect($enforce)->not->toThrow(BudgetExceededException::class);

    // The 2nd is a week before "now", so only the month puts it inside.
    SpectraRequest::factory()->forTrackable($this->user)->withCost(100)->create(['created_at' => '2026-03-02 12:00:00']);

    expect($enforce)->toThrow(fn (BudgetExceededException $exception) => expect($exception->limitType)->toBe('monthly')
        ->and($exception->current)->toBe(100)
        ->and($exception->limit)->toBe(100)
        ->and($exception->getMessage())->toBe('AI budget exceeded for monthly. Monthly limit: $1.00, current usage: $1.00'));
});

it('counts spend from any period toward a hard total limit', function () {
    $this->travelTo('2026-03-11 12:00:00');
    $this->user->configureAiBudget()
        ->totalCostLimitInCents(100)
        ->hardLimit()
        ->save();
    SpectraRequest::factory()->forTrackable($this->user)->withCost(60)->create(['created_at' => '2025-01-15 12:00:00']);
    $enforcer = app(BudgetEnforcer::class);
    $enforce = fn () => $enforcer->enforce($this->user, $this->defaultProvider, $this->defaultModel);

    expect($enforce)->not->toThrow(BudgetExceededException::class);

    SpectraRequest::factory()->forTrackable($this->user)->withCost(40)->create(['created_at' => '2026-03-11 11:00:00']);

    expect($enforce)->toThrow(fn (BudgetExceededException $exception) => expect($exception->limitType)->toBe('total')
        ->and($exception->current)->toBe(100)
        ->and($exception->limit)->toBe(100)
        ->and($exception->getMessage())->toBe('AI budget exceeded for total. Total limit: $1.00, current usage: $1.00'));
});

it('treats a cost limit of 0 as no limit', function () {
    $this->user->configureAiBudget()
        ->dailyCostLimitInCents(0)
        ->weeklyCostLimitInCents(0)
        ->monthlyCostLimitInCents(0)
        ->totalCostLimitInCents(0)
        ->hardLimit()
        ->save();
    SpectraRequest::factory()->forTrackable($this->user)->withCost(5000)->create();

    expect(fn () => app(BudgetEnforcer::class)->enforce($this->user, $this->defaultProvider, $this->defaultModel))
        ->not->toThrow(BudgetExceededException::class);
});

it('treats an unset cost limit as no limit', function () {
    $this->user->configureAiBudget()
        ->dailyTokenLimit(1_000_000)
        ->hardLimit()
        ->save();
    SpectraRequest::factory()->forTrackable($this->user)->withCost(5000)->create();

    expect(fn () => app(BudgetEnforcer::class)->enforce($this->user, $this->defaultProvider, $this->defaultModel))
        ->not->toThrow(BudgetExceededException::class);
});

it('lets requests through over a soft limit while reporting usage above 100%', function () {
    $this->user->configureAiBudget()
        ->dailyCostLimitInCents(100)
        ->softLimit()
        ->save();

    SpectraRequest::factory()->forTrackable($this->user)->withCost(150)->create();

    $enforcer = app(BudgetEnforcer::class);

    $enforcer->enforce($this->user, $this->defaultProvider, $this->defaultModel);
    $result = $enforcer->check($this->user, $this->defaultProvider, $this->defaultModel);

    expect($result->allowed)->toBeTrue()
        ->and($result->percentage)->toBeGreaterThan(100);
});

it('dispatches a warning threshold event once spend passes the warning percentage', function () {
    Event::fake([BudgetThresholdReached::class]);

    $this->user->configureAiBudget()
        ->dailyCostLimitInCents(100)
        ->warningThresholdPercentage(80)
        ->criticalThresholdPercentage(95)
        ->softLimit()
        ->save();

    SpectraRequest::factory()->forTrackable($this->user)->withCost(85)->create();

    app(BudgetEnforcer::class)->enforce($this->user, $this->defaultProvider, $this->defaultModel);

    Event::assertDispatched(BudgetThresholdReached::class, fn (BudgetThresholdReached $event) => $event->thresholdType === 'warning');
});

it('returns the daily and monthly budget left after recorded spend', function () {
    $this->user->configureAiBudget()
        ->dailyCostLimitInCents(1000)
        ->monthlyCostLimitInCents(5000)
        ->save();

    SpectraRequest::factory()->forTrackable($this->user)->withCost(300)->create();

    $remaining = $this->user->getRemainingBudget();

    expect($remaining['daily'])->toBe(700)
        ->and($remaining['monthly'])->toBe(4700);
});

it('deactivates the budget on disable and reactivates it on enable', function () {
    $budget = $this->user->configureAiBudget()
        ->dailyCostLimitInCents(1000)
        ->save();

    $this->user->disableAiBudget();
    $activeWhenDisabled = $budget->refresh()->is_active;
    $this->user->enableAiBudget();

    expect($activeWhenDisabled)->toBeFalse()
        ->and($budget->refresh()->is_active)->toBeTrue();
});

it('reports 50% usage after spending half the daily limit', function () {
    $this->user->configureAiBudget()
        ->dailyCostLimitInCents(1000)
        ->save();

    SpectraRequest::factory()->forTrackable($this->user)->withCost(500)->create();

    $status = $this->user->getBudgetStatus($this->defaultProvider, $this->defaultModel);

    expect($status->allowed)->toBeTrue()
        ->and($status->percentage)->toBe(50.0)
        ->and($status->usage->dailyCost)->toBe(500);
});

it('rounds fractional request costs up to whole cents when enforcing a budget', function () {
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

it('saves every limit, allow list and name set on the fluent builder', function () {
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

it('updates the existing budget instead of creating a second one', function () {
    $this->user->configureAiBudget()
        ->dailyCostLimitInCents(1000)
        ->save();

    $updated = $this->user->configureAiBudget()
        ->dailyCostLimitInCents(2000)
        ->weeklyCostLimitInCents(10000)
        ->save();

    expect($updated->daily_limit)->toBe(2000)
        ->and($updated->weekly_limit)->toBe(10000)
        ->and(SpectraBudget::query()->where('budgetable_id', $this->user->id)->count())->toBe(1);
});

it('never fires a warning threshold set to 0 and lets the critical one fire on its own', function () {
    Event::fake([BudgetThresholdReached::class]);
    $user = User::query()->create(['name' => 'Zero', 'email' => 'zero@example.com', 'password' => 'secret']);
    $user->configureAiBudget()->dailyCostLimitInCents(100)->warningThresholdPercentage(0)->criticalThresholdPercentage(95)->save();
    SpectraRequest::factory()->forTrackable($user)->withCost(50)->create();

    app(BudgetEnforcer::class)->enforce($user, 'openai', 'gpt-4o');

    Event::assertNotDispatched(BudgetThresholdReached::class);

    SpectraRequest::factory()->forTrackable($user)->withCost(46)->create();
    app(BudgetEnforcer::class)->enforce($user, 'openai', 'gpt-4o');

    Event::assertDispatchedTimes(BudgetThresholdReached::class, 1);
    Event::assertDispatched(BudgetThresholdReached::class, fn (BudgetThresholdReached $event) => $event->thresholdType === 'critical');
});

it('lets the warning run up to the limit when the critical threshold is 0', function () {
    Event::fake([BudgetThresholdReached::class]);
    $user = User::query()->create(['name' => 'Warn', 'email' => 'warn@example.com', 'password' => 'secret']);
    $user->configureAiBudget()->dailyCostLimitInCents(100)->warningThresholdPercentage(80)->criticalThresholdPercentage(0)->save();
    SpectraRequest::factory()->forTrackable($user)->withCost(97)->create();

    app(BudgetEnforcer::class)->enforce($user, 'openai', 'gpt-4o');

    Event::assertDispatchedTimes(BudgetThresholdReached::class, 1);
    Event::assertDispatched(BudgetThresholdReached::class, fn (BudgetThresholdReached $event) => $event->thresholdType === 'warning');
});

it('reads only the spend of the limits a budget sets when a request is saved, binding the key as a string', function () {
    $user = User::query()->create(['name' => 'Saved', 'email' => 'saved@example.com', 'password' => 'secret']);
    $user->configureAiBudget()->dailyCostLimitInCents(1000)->save();
    $request = SpectraRequest::factory()->forTrackable($user)->withCost(5)->create();

    DB::enableQueryLog();
    app(BudgetEnforcer::class)->reportRecordedRequest($request);
    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();

    $spendQueries = $queries->filter(fn (array $query) => Str::contains($query['query'], 'spectra_requests'));
    $keyBindings = $queries->pluck('bindings')->flatten()->filter(fn (mixed $binding) => (string) $binding === (string) $user->getKey());

    // The budget, the user, and the day's spend: nothing for the week, the month or all time.
    expect($queries)->toHaveCount(3)
        ->and($spendQueries)->toHaveCount(1)
        ->and($keyBindings)->not->toBeEmpty()
        ->and($keyBindings->every(fn (mixed $binding) => is_string($binding)))->toBeTrue();
});
