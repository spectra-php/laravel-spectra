<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Spectra\Events\BudgetExceeded;
use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;
use Workbench\App\Models\User;

/**
 * Enforcement is pre-flight, so the request that takes a trackable's recorded
 * spend over a cost limit always completes. Saving it announces the crossing
 * through BudgetExceeded (wasBlocked: false), once per limit and period.
 */
beforeEach(function () {
    $this->user = User::query()->create([
        'name' => 'Reported User',
        'email' => 'reported@example.com',
        'password' => 'password',
    ]);
});

afterEach(function () {
    Relation::morphMap([], false);
});

/**
 * Track a gpt-4o call that costs 2 cents.
 */
function trackTwoCentCall(): void
{
    // 4,000 prompt tokens × 250 cents/1M + 1,000 completion tokens × 1,000 cents/1M = 2 cents
    Spectra::track('openai', 'gpt-4o', fn () => [
        'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hi']]],
        'usage' => ['prompt_tokens' => 4000, 'completion_tokens' => 1000],
    ]);
}

it('dispatches BudgetExceeded without blocking when a saved request takes spend over a hard daily limit', function () {
    Event::fake([BudgetExceeded::class]);
    $this->user->configureAiBudget()->dailyCostLimitInCents(100)->hardLimit()->save();
    SpectraRequest::factory()->forTrackable($this->user)->withCost(99)->create();
    Spectra::forTrackable($this->user);

    trackTwoCentCall();

    expect(SpectraRequest::query()->forTrackable($this->user)->count())->toBe(2);
    Event::assertDispatched(BudgetExceeded::class, fn (BudgetExceeded $event) => ! $event->wasBlocked
        && $event->limitType === 'daily'
        && $event->currentUsage === 101
        && $event->limit === 100
        && $event->budgetable->is($this->user));
});

it('reports a soft-limit crossing by the signed-in user once per day', function () {
    Event::fake([BudgetExceeded::class]);
    $this->user->configureAiBudget()->dailyCostLimitInCents(100)->softLimit()->save();
    SpectraRequest::factory()->forTrackable($this->user)->withCost(99)->create();
    $this->actingAs($this->user);

    trackTwoCentCall();
    trackTwoCentCall();

    Event::assertDispatchedTimes(BudgetExceeded::class, 1);

    $this->travel(1)->days();
    SpectraRequest::factory()->forTrackable($this->user)->withCost(99)->create();

    trackTwoCentCall();

    Event::assertDispatchedTimes(BudgetExceeded::class, 2);
    Event::assertDispatched(BudgetExceeded::class, fn (BudgetExceeded $event) => ! $event->wasBlocked
        && $event->limitType === 'daily'
        && $event->budgetable->is($this->user));
});

it('dispatches nothing when budgets are disabled', function () {
    Event::fake([BudgetExceeded::class]);
    config()->set('spectra.budget.enabled', false);
    $this->user->configureAiBudget()->dailyCostLimitInCents(100)->hardLimit()->save();
    SpectraRequest::factory()->forTrackable($this->user)->withCost(99)->create();
    Spectra::forTrackable($this->user);

    trackTwoCentCall();

    expect(SpectraRequest::query()->forTrackable($this->user)->count())->toBe(2);
    Event::assertNotDispatched(BudgetExceeded::class);
});

it('finds the budget of a trackable stored under its morph alias', function () {
    Event::fake([BudgetExceeded::class]);
    Relation::morphMap(['user' => User::class]);
    $this->user->configureAiBudget()->dailyCostLimitInCents(100)->hardLimit()->save();
    SpectraRequest::factory()->forTrackable($this->user)->withCost(99)->create();
    Spectra::forTrackable($this->user);

    trackTwoCentCall();

    expect(SpectraRequest::query()->where('trackable_type', 'user')->count())->toBe(2);
    Event::assertDispatched(BudgetExceeded::class, fn (BudgetExceeded $event) => ! $event->wasBlocked
        && $event->limitType === 'daily'
        && $event->currentUsage === 101
        && $event->budgetable->is($this->user));
});

it('dispatches nothing for a trackable without a budget', function () {
    Event::fake([BudgetExceeded::class]);
    SpectraRequest::factory()->forTrackable($this->user)->withCost(99)->create();
    Spectra::forTrackable($this->user);

    trackTwoCentCall();

    expect(SpectraRequest::query()->forTrackable($this->user)->count())->toBe(2);
    Event::assertNotDispatched(BudgetExceeded::class);
});
