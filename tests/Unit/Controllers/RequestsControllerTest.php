<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Spectra\Http\Controllers\Api\RequestsController;
use Spectra\Models\SpectraRequest;

/**
 * The requests list endpoint: date range, sort, search and trace id filters.
 */
it('lists only the requests inside a custom date range', function () {
    $this->travelTo('2026-02-12 12:00:00');

    SpectraRequest::factory()
        ->count(2)
        ->state(new Sequence(
            ['model' => 'gpt-4o-in-range', 'model_type' => 'text', 'prompt_tokens' => 100, 'completion_tokens' => 50, 'total_cost_in_cents' => 120, 'created_at' => Carbon::parse('2026-02-10 10:00:00')],
            ['model' => 'gpt-4o-out-of-range', 'model_type' => 'text', 'prompt_tokens' => 60, 'completion_tokens' => 30, 'total_cost_in_cents' => 90, 'created_at' => Carbon::parse('2026-02-12 10:00:00')],
        ))
        ->create();

    $controller = app(RequestsController::class);
    $response = app()->call([$controller, '__invoke'], ['request' => new Request([
        'period' => 'custom',
        'start_date' => '2026-02-10',
        'end_date' => '2026-02-10',
    ])]);

    $json = $response->toArray();

    expect($json['total'])->toBe(1)
        ->and($json['data'])->toHaveCount(1)
        ->and($json['data'][0]['model'])->toBe('gpt-4o-in-range');
});

function callRequests(array $query): array
{
    return app()->call([app(RequestsController::class), '__invoke'], ['request' => new Request(['period' => 'all', ...$query])])->toArray();
}

it('sorts requests by total tokens', function () {
    SpectraRequest::factory()->create(['model' => 'small', 'prompt_tokens' => 5, 'completion_tokens' => 5]);
    SpectraRequest::factory()->create(['model' => 'large', 'prompt_tokens' => 500, 'completion_tokens' => 5]);

    $json = callRequests(['sort_by' => 'total_tokens', 'sort_dir' => 'desc']);

    expect(collect($json['data'])->pluck('model')->all())->toBe(['large', 'small']);
});

it('finds a request by its exact id or by part of its model', function () {
    $byId = SpectraRequest::factory()->create(['model' => 'gpt-4o']);
    SpectraRequest::factory()->create(['model' => 'claude-x']);

    expect(callRequests(['search' => $byId->id])['data'][0]['id'])->toBe($byId->id)
        ->and(collect(callRequests(['search' => 'claude'])['data'])->pluck('model')->all())->toBe(['claude-x']);
});

it('lists only the requests of the given trace id', function () {
    SpectraRequest::factory()->create(['model' => 'in-trace', 'trace_id' => 'checkout-flow']);
    SpectraRequest::factory()->create(['model' => 'elsewhere']);

    expect(collect(callRequests(['trace_id' => 'checkout-flow'])['data'])->pluck('model')->all())->toBe(['in-trace']);
});
