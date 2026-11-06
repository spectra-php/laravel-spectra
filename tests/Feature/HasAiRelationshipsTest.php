<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Spectra\Models\SpectraRequest;
use Workbench\App\Models\User;

/**
 * The relations HasAiRelationships gives a trackable model with an integer
 * key: the key is compared with Spectra's string morph id columns as a
 * string, so loading, eager loading, has() and withCount() work on every
 * driver and use the index.
 */

/**
 * Create a user who made the given number of tracked requests.
 */
function createTrackedUser(string $name, int $requests): User
{
    $user = User::query()->create(['name' => $name, 'email' => "{$name}@example.com", 'password' => 'secret']);
    SpectraRequest::factory()->count($requests)->forTrackable($user)->create();

    return $user;
}

it('binds an integer trackable key as a string when loading its requests', function () {
    $user = createTrackedUser('alice', 2);
    createTrackedUser('bob', 1);
    DB::enableQueryLog();

    $requests = $user->aiRequests()->get();
    $bindings = DB::getQueryLog()[0]['bindings'];

    expect($requests)->toHaveCount(2)
        ->and($bindings)->toContain((string) $user->getKey())
        ->and($bindings)->not->toContain($user->getKey());
});

it('eager loads requests and budgets by string keys and gives each user its own', function () {
    $alice = createTrackedUser('alice', 2);
    $bob = createTrackedUser('bob', 1);
    $budget = $bob->configureAiBudget()->dailyCostLimitInCents(500)->save();
    DB::enableQueryLog();

    $users = User::query()->with(['aiRequests', 'aiBudget'])->get()->keyBy('email');
    $eagerSql = collect(DB::getQueryLog())->pluck('query')->implode(' ');

    expect($users['alice@example.com']->aiRequests)->toHaveCount(2)
        ->and($users['bob@example.com']->aiRequests)->toHaveCount(1)
        ->and($users['alice@example.com']->aiBudget)->toBeNull()
        ->and($users['bob@example.com']->aiBudget?->getKey())->toBe($budget->getKey())
        ->and($eagerSql)->not->toMatch('/ in \(\d/');
});

it('counts and filters users by their requests and budget inside the database', function () {
    createTrackedUser('alice', 2);
    $bob = createTrackedUser('bob', 0);
    $bob->configureAiBudget()->dailyCostLimitInCents(500)->save();

    $counts = User::query()->withCount('aiRequests')->get()->pluck('ai_requests_count', 'email')->all();
    $withRequests = User::query()->has('aiRequests')->pluck('email')->all();
    $withActiveBudget = User::query()->whereHas('aiBudget', fn ($query) => $query->where('is_active', true))->pluck('email')->all();

    expect($counts)->toEqual(['alice@example.com' => 2, 'bob@example.com' => 0])
        ->and($withRequests)->toBe(['alice@example.com'])
        ->and($withActiveBudget)->toBe(['bob@example.com']);
});
