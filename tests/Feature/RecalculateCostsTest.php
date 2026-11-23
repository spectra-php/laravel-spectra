<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Spectra\Actions\Maintenance\RecalculateCosts;
use Spectra\Jobs\RecalculateCostsJob;
use Spectra\Models\SpectraDailyStat;
use Spectra\Models\SpectraRequest;
use Spectra\Tests\Fixtures\FakeCatalogs;

/**
 * Recalculating costs prices the stored requests again from the catalog and
 * writes only the database. A request keeps the cost it was recorded with
 * when the catalog can't say better: the provider reported it, the model has
 * no price, or the cost was made from something the row doesn't keep.
 */
beforeEach(function () {
    Gate::define('viewSpectra', fn ($user = null) => true);
    Gate::define('manageSpectra', fn ($user = null) => true);

    $openai = FakeCatalogs::openaiSection();
    $openai['models'] += [
        'audio-x' => ['tiers' => [['tier' => 'standard', 'input_price' => 100, 'output_price' => 400, 'audio_input_price' => 3200]]],
        'cache-x' => ['tiers' => [['tier' => 'standard', 'input_price' => 100, 'output_price' => 400, 'cache_write_5m_price' => 125, 'cache_write_1h_price' => 200]]],
        'pixel-x' => ['pricing_unit' => 'megapixel', 'tiers' => [['tier' => 'standard', 'price_per_unit' => 3]]],
    ];

    $this->bundled = FakeCatalogs::useBundled([
        'openai' => $openai,
        'anthropic' => FakeCatalogs::anthropicSection(),
        'xai' => ['currency' => 'USD', 'models' => ['grok-x' => ['tiers' => [['tier' => 'standard', 'input_price' => 200, 'output_price' => 1000]]]]],
    ]);

    config(['spectra.costs.pricing_path' => null]);
});

afterEach(function () {
    File::delete($this->bundled);
});

/**
 * Store a request with the given usage and recorded cost.
 *
 * @param  array<string, mixed>  $attributes
 */
function storedRequest(array $attributes): SpectraRequest
{
    return SpectraRequest::factory()->create([
        'provider' => 'openai',
        'model' => 'GPT X',
        'snapshot' => 'gpt-x',
        'pricing_tier' => 'standard',
        'prompt_tokens' => 1000,
        'completion_tokens' => 500,
        'currency' => 'USD',
        ...$attributes,
    ]);
}

it('prices a request that was recorded at $0 once the catalog knows its model', function () {
    $request = storedRequest(['total_cost_in_cents' => 0]);

    $result = app(RecalculateCosts::class)();

    // GPT X, cents per 1M (in 100, out 400): 1,000×100 / 1M = 0.1 and 500×400 / 1M = 0.2
    expect($result['requests'])->toBe(1)
        ->and($result['updated'])->toBe(1)
        ->and($result['difference'])->toEqualWithDelta(0.3, 1e-9)
        ->and($request->refresh()->total_cost_in_cents)->toEqualWithDelta(0.3, 1e-9)
        ->and($request->prompt_cost)->toEqualWithDelta(0.1, 1e-9)
        ->and($request->completion_cost)->toEqualWithDelta(0.2, 1e-9);
});

it('recalculates a wrong cost from the usage and tier stored on the row', function (array $attributes, float $cost) {
    $request = storedRequest(['total_cost_in_cents' => 9, ...$attributes]);

    app(RecalculateCosts::class)();

    expect($request->refresh()->total_cost_in_cents)->toEqualWithDelta($cost, 1e-9);
})->with([
    // GPT X (in 100, cached 10, out 400): (600×100 + 400×10 + 500×400) / 1M
    'cached tokens' => [['cached_tokens' => 400], 0.264],
    // GPT X batch (in 50, out 200): (1,000×50 + 500×200) / 1M
    'the batch tier' => [['pricing_tier' => 'batch'], 0.15],
    // GPT X above 200K input tokens (in 200): 300,000×200 / 1M
    'a long prompt' => [['prompt_tokens' => 300_000, 'completion_tokens' => 0], 60.0],
    // Image X hd: 3 images × 8 cents
    'an image count' => [['model' => 'Image X', 'snapshot' => 'image-x', 'pricing_tier' => 'hd', 'prompt_tokens' => 0, 'completion_tokens' => 0, 'image_count' => 3], 24.0],
]);

it('prices by the stored model when the row has no snapshot', function () {
    $request = storedRequest(['model' => 'gpt-y', 'snapshot' => null, 'total_cost_in_cents' => 0]);

    app(RecalculateCosts::class)();

    // GPT Y (in 30, out 120): (1,000×30 + 500×120) / 1M
    expect($request->refresh()->total_cost_in_cents)->toEqualWithDelta(0.09, 1e-9);
});

it('counts no change for a request whose cost is already the catalog\'s', function () {
    storedRequest(['prompt_cost' => 0.1, 'completion_cost' => 0.2, 'total_cost_in_cents' => 0.3]);

    $result = app(RecalculateCosts::class)();

    expect($result['requests'])->toBe(1)
        ->and($result['updated'])->toBe(0);
});

it('keeps the recorded cost when the catalog cannot say better', function (array $attributes) {
    $request = storedRequest(['total_cost_in_cents' => 7.5, ...$attributes]);

    $result = app(RecalculateCosts::class)();

    expect($result['updated'])->toBe(0)
        ->and($request->refresh()->total_cost_in_cents)->toEqualWithDelta(7.5, 1e-9);
})->with([
    'the provider has no catalog' => [['provider' => 'openrouter', 'model' => 'vendor/model', 'snapshot' => 'vendor/model']],
    'the model has no price' => [['model' => 'gpt-unknown', 'snapshot' => 'gpt-unknown']],
    'it was recorded in another currency' => [['currency' => 'EUR']],
    'a regional surcharge is not on the row' => [['provider' => 'anthropic', 'model' => 'Claude X', 'snapshot' => 'claude-x']],
    'audio tokens are not stored apart' => [['model' => 'audio-x', 'snapshot' => 'audio-x']],
    '1-hour cache writes are not stored apart' => [['model' => 'cache-x', 'snapshot' => 'cache-x', 'cache_write_tokens' => 200]],
    'megapixels are not stored' => [['model' => 'pixel-x', 'snapshot' => 'pixel-x', 'image_count' => 1]],
]);

it('leaves a provider-reported request as recorded, even one recorded at $0', function () {
    $request = storedRequest(['provider' => 'xai', 'model' => 'grok-x', 'snapshot' => 'grok-x', 'total_cost_in_cents' => 0]);

    $result = app(RecalculateCosts::class)();

    expect($result['requests'])->toBe(0)
        ->and($request->refresh()->total_cost_in_cents)->toEqualWithDelta(0.0, 1e-9);
});

it('prices a request recorded at $0 from the usage the row keeps when part of its cost is not stored', function () {
    $request = storedRequest(['provider' => 'anthropic', 'model' => 'Claude X', 'snapshot' => 'claude-x', 'total_cost_in_cents' => 0]);

    app(RecalculateCosts::class)();

    // Claude X (in 300, out 1500): (1,000×300 + 500×1,500) / 1M
    expect($request->refresh()->total_cost_in_cents)->toEqualWithDelta(1.05, 1e-9);
});

it('recalculates a request without cache writes on a model with a 1-hour cache-write price', function () {
    $request = storedRequest(['model' => 'cache-x', 'snapshot' => 'cache-x', 'cache_write_tokens' => 0, 'total_cost_in_cents' => 9]);

    app(RecalculateCosts::class)();

    // cache-x (in 100, out 400): (1,000×100 + 500×400) / 1M
    expect($request->refresh()->total_cost_in_cents)->toEqualWithDelta(0.3, 1e-9);
});

it('prices a request at the price of its own date', function () {
    File::delete($this->bundled);
    $this->bundled = FakeCatalogs::useBundled(['openai' => ['currency' => 'USD', 'models' => ['gpt-x' => ['tiers' => [
        ['tier' => 'standard', 'input_price' => 100, 'output_price' => 400],
        ['tier' => 'standard', 'input_price' => 200, 'output_price' => 800, 'since' => '2026-06-01'],
    ]]]]]);
    $before = storedRequest(['total_cost_in_cents' => 0, 'created_at' => '2026-05-01 10:00:00', 'started_at' => '2026-05-01 10:00:00']);
    $after = storedRequest(['total_cost_in_cents' => 0, 'created_at' => '2026-07-01 10:00:00', 'started_at' => '2026-07-01 10:00:00']);

    app(RecalculateCosts::class)();

    // Before 1 June (in 100, out 400): 0.3; from 1 June (in 200, out 800): 0.6
    expect($before->refresh()->total_cost_in_cents)->toEqualWithDelta(0.3, 1e-9)
        ->and($after->refresh()->total_cost_in_cents)->toEqualWithDelta(0.6, 1e-9);
});

it('rebuilds the daily stats of the days it changed', function () {
    storedRequest(['total_cost_in_cents' => 0, 'created_at' => now()->subDay()]);

    app(RecalculateCosts::class)();

    // GPT X (in 100, out 400): (1,000×100 + 500×400) / 1M
    expect((float) SpectraDailyStat::query()->whereNull('trackable_type')->sum('total_cost_in_cents'))->toEqualWithDelta(0.3, 1e-9);
});

it('changes nothing when cost tracking is off', function () {
    config(['spectra.costs.enabled' => false]);
    $request = storedRequest(['total_cost_in_cents' => 0]);

    $result = app(RecalculateCosts::class)();

    expect($result['updated'])->toBe(0)
        ->and($request->refresh()->total_cost_in_cents)->toEqualWithDelta(0.0, 1e-9);
});

describe('from the dashboard', function () {
    it('refuses a recalculation without the manageSpectra gate', function () {
        Queue::fake();
        Gate::define('manageSpectra', fn ($user = null) => false);

        $this->getJson('/spectra/api/maintenance/pricing')->assertJsonPath('can_manage', false);
        $this->postJson('/spectra/api/maintenance/pricing/recalculate')->assertForbidden();

        Queue::assertNothingPushed();
    });

    it('queues the recalculation when Spectra\'s queue is on', function () {
        Queue::fake();
        config(['spectra.queue.enabled' => true, 'spectra.queue.queue' => 'spectra']);
        $request = storedRequest(['total_cost_in_cents' => 0]);

        $this->getJson('/spectra/api/maintenance/pricing')
            ->assertJsonPath('can_manage', true)
            ->assertJsonPath('runs_on_queue', true)
            ->assertJsonPath('queue_name', 'spectra');
        $this->postJson('/spectra/api/maintenance/pricing/recalculate')->assertStatus(202)->assertJsonPath('queued', true);

        Queue::assertPushedOn('spectra', RecalculateCostsJob::class);
        $this->getJson('/spectra/api/maintenance/pricing')->assertJsonPath('pending', true);
        expect($request->refresh()->total_cost_in_cents)->toEqualWithDelta(0.0, 1e-9);
    });

    it('shows what the queued recalculation changed once the job has run', function () {
        config(['spectra.queue.enabled' => true, 'spectra.queue.connection' => 'sync']);
        $request = storedRequest(['total_cost_in_cents' => 0]);

        $this->postJson('/spectra/api/maintenance/pricing/recalculate')->assertStatus(202);

        $this->getJson('/spectra/api/maintenance/pricing')
            ->assertJsonPath('pending', false)
            ->assertJsonPath('last_recalculation.requests', 1)
            ->assertJsonPath('last_recalculation.updated', 1);
        // GPT X (in 100, out 400): (1,000×100 + 500×400) / 1M
        expect($request->refresh()->total_cost_in_cents)->toEqualWithDelta(0.3, 1e-9);
    });

    it('recalculates during the request when Spectra\'s queue is off', function () {
        Queue::fake();
        config(['spectra.queue.enabled' => false]);
        $request = storedRequest(['total_cost_in_cents' => 0]);

        $this->getJson('/spectra/api/maintenance/pricing')->assertJsonPath('runs_on_queue', false);
        $response = $this->postJson('/spectra/api/maintenance/pricing/recalculate');

        $response->assertOk()->assertJsonPath('queued', false)->assertJsonPath('updated', 1);
        Queue::assertNothingPushed();
        $this->getJson('/spectra/api/maintenance/pricing')
            ->assertJsonPath('pending', false)
            ->assertJsonPath('last_recalculation.updated', 1);
        // GPT X (in 100, out 400): (1,000×100 + 500×400) / 1M
        expect($request->refresh()->total_cost_in_cents)->toEqualWithDelta(0.3, 1e-9);
    });
});
