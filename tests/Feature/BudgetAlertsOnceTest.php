<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Spectra\Events\BudgetThresholdReached;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Budget\BudgetEnforcer;
use Workbench\App\Models\User;

/**
 * Threshold events fire once per budget, limit and period — across PHP
 * processes (each test enforcer is a fresh instance, like a new FPM request)
 * and again when a new period starts.
 */
it('fires a threshold warning once per period, across processes', function () {
    Event::fake([BudgetThresholdReached::class]);

    $user = User::query()->create(['name' => 'b', 'email' => 'b@example.test', 'password' => 'x']);
    $user->configureAiBudget()->dailyCostLimitInCents(100)->warningThresholdPercentage(80)->criticalThresholdPercentage(95)->save();

    SpectraRequest::factory()->create([
        'trackable_type' => $user->getMorphClass(),
        'trackable_id' => $user->getKey(),
        'total_cost_in_cents' => 85,
        'created_at' => now(),
    ]);

    (new BudgetEnforcer(app('events')))->enforce($user, 'openai', 'gpt-4o');
    (new BudgetEnforcer(app('events')))->enforce($user, 'openai', 'gpt-4o');

    Event::assertDispatchedTimes(BudgetThresholdReached::class, 1);

    $this->travel(1)->days();

    SpectraRequest::factory()->create([
        'trackable_type' => $user->getMorphClass(),
        'trackable_id' => $user->getKey(),
        'total_cost_in_cents' => 85,
        'created_at' => now(),
    ]);

    (new BudgetEnforcer(app('events')))->enforce($user, 'openai', 'gpt-4o');

    Event::assertDispatchedTimes(BudgetThresholdReached::class, 2);
});
