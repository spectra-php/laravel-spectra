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

    // 1,000 × $1.00 / 1M + 500 × $4.00 / 1M, in cents.
    expect($result)->toBe(['requests' => 1, 'updated' => 1, 'difference' => 0.3])
        ->and($request->refresh()->total_cost_in_cents)->toBe(0.3)
        ->and($request->prompt_cost)->toBe(0.1)
        ->and($request->completion_cost)->toBe(0.2);
});

it('corrects a recorded cost with the cached tokens, the tier and the long-prompt row', function () {
    $cached = storedRequest(['cached_tokens' => 400, 'total_cost_in_cents' => 9]);
    $batch = storedRequest(['pricing_tier' => 'batch', 'total_cost_in_cents' => 9]);
    $long = storedRequest(['prompt_tokens' => 300_000, 'completion_tokens' => 0, 'total_cost_in_cents' => 9]);
    $image = storedRequest(['model' => 'Image X', 'snapshot' => 'image-x', 'pricing_tier' => 'hd', 'prompt_tokens' => 0, 'completion_tokens' => 0, 'image_count' => 3, 'total_cost_in_cents' => 9]);

    app(RecalculateCosts::class)();

    expect($cached->refresh()->total_cost_in_cents)->toBe(0.264)
        ->and($batch->refresh()->total_cost_in_cents)->toBe(0.15)
        ->and($long->refresh()->total_cost_in_cents)->toBe(60.0)
        ->and($image->refresh()->total_cost_in_cents)->toBe(24.0);
});

it('falls back to the stored model when the row has no snapshot', function () {
    $request = storedRequest(['model' => 'gpt-y', 'snapshot' => null, 'total_cost_in_cents' => 0]);

    app(RecalculateCosts::class)();

    expect($request->refresh()->total_cost_in_cents)->toBe(0.09);
});

it('leaves a request whose cost is already the catalog\'s', function () {
    $request = storedRequest(['prompt_cost' => 0.1, 'completion_cost' => 0.2, 'total_cost_in_cents' => 0.3]);

    expect(app(RecalculateCosts::class)())->toBe(['requests' => 1, 'updated' => 0, 'difference' => 0.0])
        ->and($request->refresh()->total_cost_in_cents)->toBe(0.3);
});

it('keeps the recorded cost when the catalog cannot say better', function (array $attributes) {
    $request = storedRequest(['total_cost_in_cents' => 7.5, ...$attributes]);

    expect(app(RecalculateCosts::class)()['updated'])->toBe(0)
        ->and($request->refresh()->total_cost_in_cents)->toBe(7.5);
})->with([
    'the provider reported the cost' => [['provider' => 'xai', 'model' => 'grok-x', 'snapshot' => 'grok-x']],
    'the provider has no catalog' => [['provider' => 'openrouter', 'model' => 'vendor/model', 'snapshot' => 'vendor/model']],
    'the model has no price' => [['model' => 'gpt-unknown', 'snapshot' => 'gpt-unknown']],
    'it was recorded in another currency' => [['currency' => 'EUR']],
    'a regional surcharge is not on the row' => [['provider' => 'anthropic', 'model' => 'Claude X', 'snapshot' => 'claude-x']],
    'audio tokens are not stored apart' => [['model' => 'audio-x', 'snapshot' => 'audio-x']],
    '1-hour cache writes are not stored apart' => [['model' => 'cache-x', 'snapshot' => 'cache-x', 'cache_write_tokens' => 200]],
    'megapixels are not stored' => [['model' => 'pixel-x', 'snapshot' => 'pixel-x', 'image_count' => 1]],
]);

it('prices an unpriced request from the usage the row keeps, even when part of it is not stored', function (array $attributes, float $cost) {
    $request = storedRequest(['total_cost_in_cents' => 0, ...$attributes]);

    app(RecalculateCosts::class)();

    expect($request->refresh()->total_cost_in_cents)->toBe($cost);
})->with([
    'anthropic' => [['provider' => 'anthropic', 'model' => 'Claude X', 'snapshot' => 'claude-x'], 1.05],
    'a model with audio prices' => [['model' => 'audio-x', 'snapshot' => 'audio-x'], 0.3],
]);

it('never prices a provider-reported request, even one recorded at $0', function () {
    $request = storedRequest(['provider' => 'xai', 'model' => 'grok-x', 'snapshot' => 'grok-x', 'total_cost_in_cents' => 0]);

    expect(app(RecalculateCosts::class)())->toBe(['requests' => 0, 'updated' => 0, 'difference' => 0.0])
        ->and($request->refresh()->total_cost_in_cents)->toBe(0.0);
});

it('recalculates a cache-write request whose model has one cache-write price', function () {
    $request = storedRequest(['cached_tokens' => 0, 'cache_write_tokens' => 0, 'model' => 'cache-x', 'snapshot' => 'cache-x', 'total_cost_in_cents' => 9]);

    app(RecalculateCosts::class)();

    expect($request->refresh()->total_cost_in_cents)->toBe(0.3);
});

it('prices a request at the price of its own date', function () {
    $sections = ['openai' => ['currency' => 'USD', 'models' => ['gpt-x' => ['tiers' => [
        ['tier' => 'standard', 'input_price' => 100, 'output_price' => 400],
        ['tier' => 'standard', 'input_price' => 200, 'output_price' => 800, 'since' => '2026-06-01'],
    ]]]]];
    File::delete($this->bundled);
    $this->bundled = FakeCatalogs::useBundled($sections);

    $before = storedRequest(['total_cost_in_cents' => 0, 'created_at' => '2026-05-01 10:00:00', 'started_at' => '2026-05-01 10:00:00']);
    $after = storedRequest(['total_cost_in_cents' => 0, 'created_at' => '2026-07-01 10:00:00', 'started_at' => '2026-07-01 10:00:00']);

    app(RecalculateCosts::class)();

    expect($before->refresh()->total_cost_in_cents)->toBe(0.3)
        ->and($after->refresh()->total_cost_in_cents)->toBe(0.6);
});

it('rebuilds the daily stats of the days it changed', function () {
    storedRequest(['total_cost_in_cents' => 0, 'created_at' => now()->subDay()]);

    app(RecalculateCosts::class)();

    expect((float) SpectraDailyStat::query()->whereNull('trackable_type')->sum('total_cost_in_cents'))->toBe(0.3);
});

it('changes nothing when cost tracking is off', function () {
    config(['spectra.costs.enabled' => false]);
    $request = storedRequest(['total_cost_in_cents' => 0]);

    expect(app(RecalculateCosts::class)())->toBe(['requests' => 0, 'updated' => 0, 'difference' => 0.0])
        ->and($request->refresh()->total_cost_in_cents)->toBe(0.0);
});

describe('from the dashboard', function () {
    it('tells the card whether the user may recalculate costs', function (bool $allowed) {
        Gate::define('manageSpectra', fn ($user = null) => $allowed);

        $this->getJson('/spectra/api/maintenance/pricing')
            ->assertOk()
            ->assertJsonPath('can_manage', $allowed)
            ->assertJsonPath('pending', false)
            ->assertJsonPath('last_recalculation', null);
    })->with([true, false]);

    it('refuses a recalculation without the manageSpectra gate', function () {
        Queue::fake();
        Gate::define('manageSpectra', fn ($user = null) => false);

        $this->postJson('/spectra/api/maintenance/pricing/recalculate')->assertForbidden();

        Queue::assertNothingPushed();
    });

    it('refuses a recalculation when dashboard.maintenance is off', function () {
        Queue::fake();
        config(['spectra.dashboard.maintenance' => false]);

        $this->postJson('/spectra/api/maintenance/pricing/recalculate')->assertForbidden();

        Queue::assertNothingPushed();
    });

    it('queues a recalculation when Spectra\'s queue is on, and shows it as pending', function () {
        Queue::fake();
        config(['spectra.queue.enabled' => true, 'spectra.queue.queue' => 'spectra']);
        $request = storedRequest(['total_cost_in_cents' => 0]);

        $this->getJson('/spectra/api/maintenance/pricing')
            ->assertJsonPath('runs_on_queue', true)
            ->assertJsonPath('queue_name', 'spectra');

        $this->postJson('/spectra/api/maintenance/pricing/recalculate')->assertStatus(202)->assertExactJson(['queued' => true]);

        Queue::assertPushedOn('spectra', RecalculateCostsJob::class);
        $this->getJson('/spectra/api/maintenance/pricing')->assertJsonPath('pending', true);
        expect($request->refresh()->total_cost_in_cents)->toBe(0.0);
    });

    it('runs the queued recalculation and records the run', function () {
        config(['spectra.queue.enabled' => true, 'spectra.queue.connection' => 'sync']);
        $request = storedRequest(['total_cost_in_cents' => 0]);

        $this->postJson('/spectra/api/maintenance/pricing/recalculate')->assertStatus(202);

        expect($request->refresh()->total_cost_in_cents)->toBe(0.3);

        $this->getJson('/spectra/api/maintenance/pricing')
            ->assertJsonPath('pending', false)
            ->assertJsonPath('last_recalculation.updated', 1);
    });

    it('recalculates during the request when Spectra\'s queue is off, writes no pricing file and records the run', function () {
        Queue::fake();
        config(['spectra.queue.enabled' => false]);
        $request = storedRequest(['total_cost_in_cents' => 0]);
        storedRequest(['provider' => 'anthropic', 'model' => 'Claude X', 'snapshot' => 'claude-x', 'total_cost_in_cents' => 5]);
        $catalog = File::get($this->bundled);

        $this->getJson('/spectra/api/maintenance/pricing')
            ->assertJsonPath('runs_on_queue', false)
            ->assertJsonPath('queue_name', null);

        $this->postJson('/spectra/api/maintenance/pricing/recalculate')
            ->assertOk()
            ->assertExactJson(['queued' => false, 'requests' => 2, 'updated' => 1, 'difference' => 0.3]);

        Queue::assertNothingPushed();

        expect($request->refresh()->total_cost_in_cents)->toBe(0.3)
            ->and(File::get($this->bundled))->toBe($catalog)
            ->and(File::exists(resource_path('spectra/pricing.json')))->toBeFalse();

        $this->getJson('/spectra/api/maintenance/pricing')
            ->assertJsonPath('pending', false)
            ->assertJsonPath('last_recalculation.requests', 2)
            ->assertJsonPath('last_recalculation.updated', 1)
            ->assertJsonPath('last_recalculation.difference', 0.3);
    });
});
