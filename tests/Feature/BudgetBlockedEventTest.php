<?php

declare(strict_types=1);

/**
 * Every pre-flight block announces itself through BudgetExceeded
 * (wasBlocked: true), whichever limit or allow-list stopped the request.
 */

use Illuminate\Support\Facades\Event;
use Spectra\Events\BudgetExceeded;
use Spectra\Exceptions\BudgetExceededException;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Budget\BudgetEnforcer;
use Workbench\App\Models\User;

beforeEach(function () {
    $this->user = User::query()->create([
        'name' => 'Blocked User',
        'email' => 'blocked@example.com',
        'password' => 'password',
    ]);
});

it('dispatches BudgetExceeded before a token limit blocks the request', function () {
    Event::fake([BudgetExceeded::class]);
    $this->user->configureAiBudget()->dailyTokenLimit(1000)->hardLimit()->save();
    SpectraRequest::factory()->forTrackable($this->user)->withTokens(1000, 500)->create();

    expect(fn () => app(BudgetEnforcer::class)->enforce($this->user, 'openai', 'gpt-4o'))
        ->toThrow(BudgetExceededException::class);

    Event::assertDispatched(BudgetExceeded::class, fn (BudgetExceeded $event) => $event->wasBlocked
        && $event->limitType === 'daily_tokens'
        && $event->currentUsage === 1500
        && $event->limit === 1000);
});

it('dispatches BudgetExceeded before a request-count limit blocks the request', function () {
    Event::fake([BudgetExceeded::class]);
    $this->user->configureAiBudget()->dailyRequestLimit(2)->hardLimit()->save();
    SpectraRequest::factory()->forTrackable($this->user)->count(3)->create();

    expect(fn () => app(BudgetEnforcer::class)->enforce($this->user, 'openai', 'gpt-4o'))
        ->toThrow(BudgetExceededException::class);

    Event::assertDispatched(BudgetExceeded::class, fn (BudgetExceeded $event) => $event->wasBlocked
        && $event->limitType === 'daily_requests'
        && $event->currentUsage === 3
        && $event->limit === 2);
});

it('dispatches BudgetExceeded with zero usage and limit when the provider is not allowed', function () {
    Event::fake([BudgetExceeded::class]);
    $this->user->configureAiBudget()->dailyCostLimitInCents(1000)->allowProviders(['openai'])->save();

    expect(fn () => app(BudgetEnforcer::class)->enforce($this->user, 'anthropic', 'claude-sonnet-4'))
        ->toThrow(BudgetExceededException::class);

    Event::assertDispatched(BudgetExceeded::class, fn (BudgetExceeded $event) => $event->wasBlocked
        && $event->limitType === 'provider'
        && $event->currentUsage === 0
        && $event->limit === 0);
});

it('dispatches BudgetExceeded when the model is not allowed', function () {
    Event::fake([BudgetExceeded::class]);
    $this->user->configureAiBudget()->dailyCostLimitInCents(1000)->allowModels(['gpt-4o-mini'])->save();

    expect(fn () => app(BudgetEnforcer::class)->enforce($this->user, 'openai', 'gpt-4o'))
        ->toThrow(BudgetExceededException::class);

    Event::assertDispatched(BudgetExceeded::class, fn (BudgetExceeded $event) => $event->wasBlocked
        && $event->limitType === 'model');
});

it('dispatches nothing when the request is within every limit', function () {
    Event::fake([BudgetExceeded::class]);
    $this->user->configureAiBudget()->dailyTokenLimit(1000)->dailyRequestLimit(5)->hardLimit()->save();

    app(BudgetEnforcer::class)->enforce($this->user, 'openai', 'gpt-4o');

    Event::assertNotDispatched(BudgetExceeded::class);
});
