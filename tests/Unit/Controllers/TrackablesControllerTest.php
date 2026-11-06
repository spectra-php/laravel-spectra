<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Spectra\Actions\Trackables\GetFilteredTrackables;
use Spectra\Http\Controllers\Api\ShowTrackableController;
use Spectra\Http\Controllers\Api\TrackablesController;
use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;
use Workbench\App\Models\User;

/**
 * The trackables list endpoint: filters, sorting, input limits, success-only
 * cost and latency, and how trackable ids, types and names are resolved.
 */
it('counts only the requests matching every request filter per trackable', function () {
    [$included] = SpectraRequest::factory()
        ->count(3)
        ->state(new Sequence(
            ['model' => 'gpt-4o', 'model_type' => 'text', 'finish_reason' => 'stop', 'has_tool_calls' => false, 'trace_id' => 'trace-include', 'prompt_tokens' => 120, 'completion_tokens' => 80, 'total_cost_in_cents' => 100, 'trackable_type' => 'App\\Models\\User', 'trackable_id' => 1],
            ['model' => 'gpt-image-1', 'model_type' => 'image', 'status_code' => 500, 'finish_reason' => 'error', 'has_tool_calls' => true, 'trace_id' => 'trace-excluded', 'image_count' => 2, 'total_cost_in_cents' => 200, 'trackable_type' => 'App\\Models\\User', 'trackable_id' => 1],
            ['provider' => 'anthropic', 'model' => 'claude-3-5-sonnet', 'model_type' => 'text', 'finish_reason' => 'stop', 'has_tool_calls' => false, 'trace_id' => 'trace-other-provider', 'prompt_tokens' => 10, 'completion_tokens' => 5, 'total_cost_in_cents' => 50, 'trackable_type' => 'App\\Models\\Team', 'trackable_id' => 2],
        ))
        ->create();

    $included->attachTags(['priority']);

    $controller = app(TrackablesController::class);
    $response = app()->call($controller->__invoke(...), ['request' => new Request([
        'period' => 'all',
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'model_type' => 'text',
        'status' => 'success',
        'has_tool_calls' => '0',
        'trace_id' => 'trace-include',
        'tag' => 'priority',
    ])]);

    $json = $response->toArray();

    expect($json['total'])->toBe(1)
        ->and($json['data'])->toHaveCount(1)
        ->and($json['data'][0]['trackable_type'])->toBe('App\\Models\\User')
        ->and($json['data'][0]['trackable_id'])->toBe('1')
        ->and($json['data'][0]['requests'])->toBe(1)
        ->and($json['available_tags'])->toContain('priority');
});

it('lists only the trackables with requests inside a custom date range', function () {
    $this->travelTo('2026-02-12 12:00:00');

    SpectraRequest::factory()
        ->count(2)
        ->state(new Sequence(
            ['model' => 'gpt-4o', 'model_type' => 'text', 'prompt_tokens' => 100, 'completion_tokens' => 20, 'total_cost_in_cents' => 70, 'trackable_type' => 'App\\Models\\User', 'trackable_id' => 7, 'created_at' => Date::parse('2026-02-10 08:00:00')],
            ['model' => 'gpt-4o-mini', 'model_type' => 'text', 'prompt_tokens' => 50, 'completion_tokens' => 10, 'total_cost_in_cents' => 40, 'trackable_type' => 'App\\Models\\User', 'trackable_id' => 8, 'created_at' => Date::parse('2026-02-12 08:00:00')],
        ))
        ->create();

    $controller = app(TrackablesController::class);
    $response = app()->call($controller->__invoke(...), ['request' => new Request([
        'period' => 'custom',
        'start_date' => '2026-02-10',
        'end_date' => '2026-02-10',
    ])]);

    $json = $response->toArray();

    expect($json['total'])->toBe(1)
        ->and($json['data'])->toHaveCount(1)
        ->and($json['data'][0]['trackable_id'])->toBe('7')
        ->and($json['summary']['total_requests'])->toBe(1);
});

function callTrackables(array $query): array
{
    return app()->call([app(TrackablesController::class), '__invoke'], ['request' => new Request(['period' => 'all', ...$query])])->toArray();
}

it('sorts trackables by token usage', function () {
    SpectraRequest::factory()->create(['prompt_tokens' => 10, 'completion_tokens' => 0, 'total_cost_in_cents' => 500, 'trackable_type' => 'App\\Models\\User', 'trackable_id' => 'few-tokens']);
    SpectraRequest::factory()->create(['prompt_tokens' => 900, 'completion_tokens' => 0, 'total_cost_in_cents' => 1, 'trackable_type' => 'App\\Models\\User', 'trackable_id' => 'many-tokens']);

    $json = callTrackables(['sort_by' => 'tokens', 'sort_dir' => 'desc']);

    expect(collect($json['data'])->pluck('trackable_id')->all())->toBe(['many-tokens', 'few-tokens']);
});

it('falls back to a descending sort and a capped page size for bad input', function () {
    SpectraRequest::factory()->create(['total_cost_in_cents' => 1, 'trackable_type' => 'App\\Models\\User', 'trackable_id' => 'cheap']);
    SpectraRequest::factory()->create(['total_cost_in_cents' => 9, 'trackable_type' => 'App\\Models\\User', 'trackable_id' => 'pricey']);

    $json = callTrackables(['sort_by' => 'created_at; drop table', 'sort_dir' => 'sideways', 'per_page' => 100000]);
    $paginated = app(GetFilteredTrackables::class)(new Request(['per_page' => 100000]), new DateRange(null, null));

    expect(collect($json['data'])->pluck('trackable_id')->all())->toBe(['pricey', 'cheap'])
        ->and($paginated->perPage())->toBe(100);
});

it('counts failed requests but leaves their cost and latency out of each trackable row', function () {
    SpectraRequest::factory()->create(['status_code' => 200, 'total_cost_in_cents' => 100, 'latency_ms' => 100, 'trackable_type' => 'App\\Models\\User', 'trackable_id' => '1']);
    SpectraRequest::factory()->create(['status_code' => 500, 'total_cost_in_cents' => 900, 'latency_ms' => 9000, 'trackable_type' => 'App\\Models\\User', 'trackable_id' => '1']);

    $row = callTrackables([])['data'][0];

    expect($row['requests'])->toBe(2)
        ->and($row['cost'])->toEqualWithDelta(100.0, 0.0001)
        ->and($row['avg_latency'])->toBe(100.0);
});

it('keeps ULID trackable ids intact', function () {
    SpectraRequest::factory()->create(['trackable_type' => 'App\\Models\\Team', 'trackable_id' => '01JBXQ7Z9V8K3M4N5P6Q7R8S9T']);

    expect(callTrackables([])['data'][0]['trackable_id'])->toBe('01JBXQ7Z9V8K3M4N5P6Q7R8S9T');
});

it('counts trackables by type and id together', function () {
    SpectraRequest::factory()->create(['trackable_type' => 'App\\Models\\User', 'trackable_id' => '1']);
    SpectraRequest::factory()->create(['trackable_type' => 'App\\Models\\User', 'trackable_id' => '1']);
    SpectraRequest::factory()->create(['trackable_type' => 'App\\Models\\Team', 'trackable_id' => '1']);

    expect(callTrackables([])['summary']['total_trackables'])->toBe(2);
});

it('limits the list, summary and type filter to users when asked', function () {
    Relation::morphMap(['member' => User::class, 'conversation' => SpectraRequest::class]);

    SpectraRequest::factory()->create(['total_cost_in_cents' => 10, 'trackable_type' => 'member', 'trackable_id' => '1']);
    SpectraRequest::factory()->create(['total_cost_in_cents' => 20, 'trackable_type' => 'conversation', 'trackable_id' => '01a1b04c-d820-7140-bea1-3e85e8d94cfb']);
    SpectraRequest::factory()->create(['total_cost_in_cents' => 40, 'trackable_type' => 'App\\Models\\Gone', 'trackable_id' => '3']);

    $users = app()->call([app(TrackablesController::class), '__invoke'], ['request' => new Request(['period' => 'all', 'users_only' => '1'])])->toArray();
    $all = app()->call([app(TrackablesController::class), '__invoke'], ['request' => new Request(['period' => 'all'])])->toArray();

    expect(collect($users['data'])->map(fn ($row) => "{$row['trackable_type']}#{$row['trackable_id']}")->all())->toBe(['member#1'])
        ->and($users['total'])->toBe(1)
        ->and($users['summary']['total_trackables'])->toBe(1)
        ->and((float) $users['summary']['total_cost'])->toEqualWithDelta(10.0, 0.0001)
        ->and($users['available_trackable_types'])->toBe(['member'])
        ->and($all['total'])->toBe(3)
        ->and($all['available_trackable_types'])->toHaveCount(3);
})->after(fn () => Relation::morphMap([], false));

it('lists nobody as a user when no call was attributed to one', function () {
    SpectraRequest::factory()->create(['total_cost_in_cents' => 20, 'trackable_type' => 'conversation', 'trackable_id' => '48']);

    $json = app()->call([app(TrackablesController::class), '__invoke'], ['request' => new Request(['period' => 'all', 'users_only' => '1'])])->toArray();

    expect($json['data'])->toHaveCount(0)
        ->and($json['summary']['total_trackables'])->toBe(0)
        ->and($json['available_trackable_types'])->toBe([]);
});

it('resolves trackable names through the morph map', function () {
    Relation::morphMap(['member' => User::class]);
    $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'secret']);
    SpectraRequest::factory()->create(['trackable_type' => 'member', 'trackable_id' => (string) $user->getKey()]);

    $json = callTrackables([]);
    $details = app()->call([app(ShowTrackableController::class), '__invoke'], [
        'request' => new Request(['period' => 'all', 'type' => 'member']),
        'id' => (string) $user->getKey(),
    ])->toArray();

    expect($json['data'][0]['trackable_name'])->toBe('Ada')
        ->and($details['trackable']['name'])->toBe('Ada');
})->after(fn () => Relation::morphMap([], false));

it('never instantiates a trackable type that is not an Eloquent model', function () {
    SpectraRequest::factory()->create(['trackable_type' => Request::class, 'trackable_id' => '1']);

    $details = app()->call([app(ShowTrackableController::class), '__invoke'], [
        'request' => new Request(['period' => 'all', 'type' => Request::class]),
        'id' => '1',
    ])->toArray();

    expect(callTrackables([])['data'][0]['trackable_name'])->toBeNull()
        ->and($details['trackable'])->not->toHaveKey('name');
});

it('lists only the trackables of the type asked for and offers every stored type', function () {
    SpectraRequest::factory()->create(['trackable_type' => 'App\\Models\\User', 'trackable_id' => '1']);
    SpectraRequest::factory()->create(['trackable_type' => 'App\\Models\\Team', 'trackable_id' => '1']);
    SpectraRequest::factory()->create();

    $json = callTrackables(['trackable_type' => 'App\\Models\\Team']);

    expect($json['total'])->toBe(1)
        ->and($json['data'][0]['trackable_type'])->toBe('App\\Models\\Team')
        ->and($json['summary']['total_trackables'])->toBe(1)
        ->and($json['available_trackable_types'])->toBe(['App\\Models\\Team', 'App\\Models\\User']);
});

it('reports a trackable model per provider so the same model on two providers stays apart', function () {
    $rows = ['trackable_type' => 'App\\Models\\User', 'trackable_id' => '1', 'model' => 'gpt-4o', 'status_code' => 200, 'total_cost_in_cents' => 10, 'created_at' => now()];
    SpectraRequest::factory()->count(2)->create($rows + ['provider' => 'openai']);
    SpectraRequest::factory()->create($rows + ['provider' => 'openrouter']);

    $details = app()->call([app(ShowTrackableController::class), '__invoke'], [
        'request' => new Request(['period' => 'all', 'type' => 'App\\Models\\User']),
        'id' => '1',
    ])->toArray();

    $requestsByProvider = collect($details['trackable']['usage_by_model'])->pluck('requests', 'provider')->all();

    expect($requestsByProvider)->toBe(['openai' => 2, 'openrouter' => 1]);
});
