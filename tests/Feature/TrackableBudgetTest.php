<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spectra\Models\SpectraBudget;
use Spectra\Models\SpectraRequest;
use Workbench\App\Models\User;

/**
 * A trackable's budget on the dashboard: its page shows the budget the app
 * enforces, whether it was set in code or on the dashboard, and a user the
 * manageSpectra gate allows can set, change, switch off and remove it there.
 */
function budgetUri(User $user): string
{
    return "/spectra/api/trackables/view/{$user->getKey()}/budget";
}

function budgetPayload(User $user, array $limits, array $overrides = []): array
{
    return [
        'type' => $user->getMorphClass(),
        'limits' => $limits,
        'hard_limit' => true,
        'is_active' => true,
        ...$overrides,
    ];
}

beforeEach(function () {
    Gate::define('viewSpectra', fn ($user = null) => true);
    Gate::define('manageSpectra', fn ($user = null) => true);

    $this->user = User::query()->create([
        'name' => 'Budget User',
        'email' => 'budget@example.com',
        'password' => 'password',
    ]);
});

describe('showing', function () {
    it('shows a budget set in code with what is used against each limit', function () {
        $this->user->configureAiBudget()
            ->monthlyCostLimitInCents(2000)
            ->dailyRequestLimit(50)
            ->softLimit()
            ->allowProviders(['openai'])
            ->save();
        SpectraRequest::factory()->forTrackable($this->user)->withCost(150)->count(2)->create();

        $response = $this->getJson(budgetUri($this->user).'?type='.urlencode($this->user->getMorphClass()));

        $response->assertOk()
            ->assertJsonPath('exists', true)
            ->assertJsonPath('is_active', true)
            ->assertJsonPath('hard_limit', false)
            ->assertJsonPath('allowed_providers', ['openai'])
            ->assertJsonPath('limits', [
                ['metric' => 'cost', 'period' => 'monthly', 'used' => 300, 'limit' => 2000],
                ['metric' => 'requests', 'period' => 'daily', 'used' => 2, 'limit' => 50],
            ]);
    });

    it('answers with no budget for a trackable that has none', function () {
        $this->getJson(budgetUri($this->user).'?type='.urlencode($this->user->getMorphClass()))
            ->assertOk()
            ->assertJsonPath('exists', false)
            ->assertJsonPath('limits', [])
            ->assertJsonPath('can_manage', true);
    });

    it('still shows a budget that is switched off', function () {
        $this->user->configureAiBudget()->dailyTokenLimit(1000)->save();
        $this->user->disableAiBudget();

        $this->getJson(budgetUri($this->user).'?type='.urlencode($this->user->getMorphClass()))
            ->assertOk()
            ->assertJsonPath('exists', true)
            ->assertJsonPath('is_active', false)
            ->assertJsonPath('limits.0.limit', 1000);
    });

    it('refuses a request that names no trackable type', function () {
        $this->getJson(budgetUri($this->user))->assertStatus(400);
    });
});

describe('saving', function () {
    it('saves a budget the app then enforces on a route with the budget middleware', function () {
        Route::post('/ai/generate', fn () => 'ok')->middleware('spectra.budget');

        $this->putJson(budgetUri($this->user), budgetPayload($this->user, [
            ['metric' => 'requests', 'period' => 'daily', 'limit' => 2],
        ]))->assertOk();
        SpectraRequest::factory()->forTrackable($this->user)->count(2)->create();

        expect($this->user->getBudgetStatus()->allowed)->toBeFalse();
        $this->actingAs($this->user)->postJson('/ai/generate')
            ->assertStatus(429)
            ->assertJsonPath('limit', 2);
    });

    it('stores every kind of limit in the column the budget builder writes', function () {
        $this->putJson(budgetUri($this->user), budgetPayload($this->user, [
            ['metric' => 'cost', 'period' => 'monthly', 'limit' => 2500],
            ['metric' => 'tokens', 'period' => 'total', 'limit' => 1000000],
            ['metric' => 'requests', 'period' => 'weekly', 'limit' => 300],
        ], ['hard_limit' => false]))->assertOk();

        $budget = $this->user->aiBudget()->first();

        expect($budget->monthly_limit)->toBe(2500)
            ->and($budget->total_token_limit)->toBe(1000000)
            ->and($budget->weekly_request_limit)->toBe(300)
            ->and($budget->hard_limit)->toBeFalse()
            ->and(SpectraBudget::query()->count())->toBe(1);
    });

    it('changes a budget set in code, keeping its thresholds and allowed models and clearing a limit that was removed', function () {
        $this->user->configureAiBudget()
            ->dailyCostLimitInCents(500)
            ->monthlyTokenLimit(90000)
            ->warningThresholdPercentage(60)
            ->allowModels(['gpt-5.2'])
            ->name('Pro plan')
            ->save();

        $this->putJson(budgetUri($this->user), budgetPayload($this->user, [
            ['metric' => 'cost', 'period' => 'daily', 'limit' => 900],
        ]))->assertOk();

        $budget = $this->user->aiBudget()->first();

        expect($budget->daily_limit)->toBe(900)
            ->and($budget->monthly_token_limit)->toBeNull()
            ->and($budget->warning_threshold)->toBe(60)
            ->and($budget->allowed_models)->toBe(['gpt-5.2'])
            ->and($budget->name)->toBe('Pro plan')
            ->and(SpectraBudget::query()->count())->toBe(1);
    });

    it('stops enforcing a budget that is switched off and keeps its limits', function () {
        SpectraRequest::factory()->forTrackable($this->user)->count(3)->create();

        $this->putJson(budgetUri($this->user), budgetPayload($this->user, [
            ['metric' => 'requests', 'period' => 'daily', 'limit' => 1],
        ], ['is_active' => false]))->assertOk();

        expect($this->user->getBudgetStatus()->allowed)->toBeTrue()
            ->and($this->user->aiBudget()->first()->daily_request_limit)->toBe(1);
    });

    it('saves a budget for a trackable keyed by a uuid under a morph map alias', function () {
        Relation::morphMap(['member' => User::class]);
        $id = (string) Str::uuid();

        $this->putJson("/spectra/api/trackables/view/{$id}/budget", [
            'type' => 'member',
            'limits' => [['metric' => 'tokens', 'period' => 'daily', 'limit' => 5000]],
            'hard_limit' => true,
            'is_active' => true,
        ])->assertOk();
        SpectraRequest::factory()->withTokens(100, 20)->create(['trackable_type' => 'member', 'trackable_id' => $id]);

        $this->getJson("/spectra/api/trackables/view/{$id}/budget?type=member")
            ->assertOk()
            ->assertJsonPath('limits', [['metric' => 'tokens', 'period' => 'daily', 'used' => 120, 'limit' => 5000]]);
    })->after(fn () => Relation::morphMap([], false));

    it('rejects limits the budget cannot hold', function (array $limits, string $error) {
        $this->putJson(budgetUri($this->user), budgetPayload($this->user, $limits))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($error);

        expect(SpectraBudget::query()->count())->toBe(0);
    })->with([
        'no limits' => [[], 'limits'],
        'an all-time request limit' => [[['metric' => 'requests', 'period' => 'total', 'limit' => 10]], 'limits.0.period'],
        'the same limit twice' => [[
            ['metric' => 'cost', 'period' => 'daily', 'limit' => 100],
            ['metric' => 'cost', 'period' => 'daily', 'limit' => 200],
        ], 'limits.1.period'],
        'a limit of zero' => [[['metric' => 'tokens', 'period' => 'daily', 'limit' => 0]], 'limits.0.limit'],
        'a request limit past the column' => [[['metric' => 'requests', 'period' => 'daily', 'limit' => 4294967296]], 'limits.0.limit'],
        'an unknown kind' => [[['metric' => 'messages', 'period' => 'daily', 'limit' => 5]], 'limits.0.metric'],
    ]);
});

describe('removing', function () {
    it('removes the budget so nothing limits the trackable', function () {
        $this->user->configureAiBudget()->dailyRequestLimit(1)->save();
        SpectraRequest::factory()->forTrackable($this->user)->count(2)->create();

        $this->deleteJson(budgetUri($this->user).'?type='.urlencode($this->user->getMorphClass()))->assertOk();

        expect(SpectraBudget::query()->count())->toBe(0)
            ->and($this->user->getBudgetStatus()->allowed)->toBeTrue();
    });
});

describe('access', function () {
    it('shows the budget read-only when budgets cannot be changed', function (Closure $deny) {
        $deny();
        $this->user->configureAiBudget()->dailyRequestLimit(10)->save();

        $this->getJson(budgetUri($this->user).'?type='.urlencode($this->user->getMorphClass()))
            ->assertOk()
            ->assertJsonPath('exists', true)
            ->assertJsonPath('can_manage', false);
    })->with([
        'the manageSpectra gate refuses' => [fn () => Gate::define('manageSpectra', fn ($user = null) => false)],
        'dashboard.budgets is off' => [fn () => config()->set('spectra.dashboard.budgets', false)],
    ]);

    it('refuses to save or remove a budget when budgets cannot be changed', function (Closure $deny) {
        $deny();
        $this->user->configureAiBudget()->dailyRequestLimit(10)->save();

        $this->putJson(budgetUri($this->user), budgetPayload($this->user, [
            ['metric' => 'requests', 'period' => 'daily', 'limit' => 99],
        ]))->assertForbidden();
        $this->deleteJson(budgetUri($this->user).'?type='.urlencode($this->user->getMorphClass()))->assertForbidden();

        expect($this->user->aiBudget()->first()->daily_request_limit)->toBe(10);
    })->with([
        'the manageSpectra gate refuses' => [fn () => Gate::define('manageSpectra', fn ($user = null) => false)],
        'dashboard.budgets is off' => [fn () => config()->set('spectra.dashboard.budgets', false)],
    ]);
});

describe('trackables list', function () {
    it('marks only the trackables whose budget is switched on', function () {
        $unlimited = User::query()->create(['name' => 'No Budget', 'email' => 'none@example.com', 'password' => 'password']);
        $paused = User::query()->create(['name' => 'Paused Budget', 'email' => 'paused@example.com', 'password' => 'password']);
        $this->user->configureAiBudget()->dailyRequestLimit(10)->save();
        $paused->configureAiBudget()->dailyRequestLimit(10)->save();
        $paused->disableAiBudget();
        foreach ([$this->user, $unlimited, $paused] as $user) {
            SpectraRequest::factory()->forTrackable($user)->create();
        }

        $rows = collect($this->getJson('/spectra/api/trackables?period=all')->assertOk()->json('data'))
            ->mapWithKeys(fn (array $row) => [$row['trackable_id'] => $row['has_budget']]);

        expect($rows->all())->toEqual([
            (string) $this->user->getKey() => true,
            (string) $unlimited->getKey() => false,
            (string) $paused->getKey() => false,
        ]);
    });
});
