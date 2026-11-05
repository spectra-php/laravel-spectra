<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spectra\Jobs\ScrapePricingJob;
use Spectra\Support\Pricing\ScrapedPricesFile;
use Spectra\Support\Pricing\Scraping\PricingReview;
use Spectra\Tests\Fixtures\FakeOpenAIPricing;

/**
 * The maintenance screen's prices card: a check reads the pricing pages with
 * Firecrawl and keeps the changes for review without writing anything; the
 * reviewer then writes the models they pick to the scraped prices file.
 */
beforeEach(function () {
    Gate::define('viewSpectra', fn ($user = null) => true);
    Gate::define('manageSpectra', fn ($user = null) => true);

    $this->path = storage_path('framework/testing/pricing-'.uniqid().'.json');

    config([
        'queue.default' => 'sync',
        'spectra.costs.pricing' => ['openai' => FakeOpenAIPricing::class],
        'spectra.costs.scraped_prices_path' => $this->path,
        'spectra.costs.firecrawl.api_key' => 'fc-test',
        'spectra.costs.firecrawl.providers' => ['openai'],
    ]);

    // gpt-x is cheaper on the page, gpt-z is new, gpt-y matches the catalog.
    Http::fake(['api.firecrawl.dev/*' => Http::response(['success' => true, 'data' => [
        'markdown' => implode("\n", [
            '| Model | Input | Output | Cached input |',
            '| gpt-x | $0.90 | $4.00 | $0.10 |',
            '| gpt-y | $0.30 | $1.20 | - |',
            '| gpt-z | $2.00 | $8.00 | - |',
        ]),
        'json' => ['models' => [
            ['model_id' => 'gpt-x', 'tier' => 'standard', 'input' => 0.9, 'output' => 4],
            ['model_id' => 'gpt-y', 'tier' => 'standard', 'input' => 0.3, 'output' => 1.2],
            ['model_id' => 'gpt-z', 'tier' => 'standard', 'input' => 2, 'output' => 8],
        ]],
    ]])]);

    $this->readFile = fn () => json_decode(File::get($this->path), true);
});

afterEach(function () {
    File::delete($this->path);
});

it('keeps a check for review without writing the scraped prices file', function () {
    $this->postJson('/spectra/api/maintenance/pricing/check')->assertStatus(202);

    expect(File::exists($this->path))->toBeFalse();

    $response = $this->getJson('/spectra/api/maintenance/pricing')
        ->assertOk()
        ->assertJsonPath('pending', false)
        ->assertJsonPath('firecrawl_configured', true)
        ->assertJsonPath('providers_to_check.providers', ['openai'])
        ->assertJsonPath('review.providers.0.provider', 'openai')
        ->assertJsonPath('review.providers.0.error', null);

    $models = collect($response->json('review.providers.0.models'))->keyBy('id');

    expect($models->keys()->all())->toBe(['gpt-x', 'gpt-z'])
        ->and($models['gpt-x']['is_new'])->toBeFalse()
        ->and($models['gpt-x']['applied'])->toBeFalse()
        ->and($models['gpt-x']['changes'][0])->toMatchArray(['field' => 'input_price', 'label' => 'input', 'old' => 100, 'new' => 90, 'page_line' => '| gpt-x | $0.90 | $4.00 | $0.10 |'])
        ->and($models['gpt-z']['is_new'])->toBeTrue()
        ->and($models['gpt-z']['changes'])->toHaveCount(2);
});

it('queues the check on the Spectra queue', function () {
    Queue::fake();

    $this->postJson('/spectra/api/maintenance/pricing/check')->assertStatus(202);

    Queue::assertPushed(ScrapePricingJob::class);
    $this->getJson('/spectra/api/maintenance/pricing')->assertJsonPath('pending', true);
});

it('refuses a check without a Firecrawl key or a provider to read', function () {
    Queue::fake();

    config(['spectra.costs.firecrawl.api_key' => null]);
    $this->postJson('/spectra/api/maintenance/pricing/check')->assertUnprocessable()->assertJsonPath('message', 'Set SPECTRA_FIRECRAWL_API_KEY to check the pricing pages.');

    config(['spectra.costs.firecrawl.api_key' => 'fc-test', 'spectra.costs.firecrawl.providers' => []]);
    $this->postJson('/spectra/api/maintenance/pricing/check')->assertUnprocessable();

    Queue::assertNothingPushed();
});

it('keeps why a provider could not be read', function () {
    // Replace the page stub from beforeEach: the first matching stub wins.
    Http::swap(new Factory);
    Http::fake(['api.firecrawl.dev/*' => Http::response(['success' => false, 'error' => 'Payment required'], 402)]);

    $this->postJson('/spectra/api/maintenance/pricing/check')->assertStatus(202);

    expect($this->getJson('/spectra/api/maintenance/pricing')->json('review.providers.0.error'))->toContain('Payment required');
});

it('writes only the picked models and keeps the file\'s other models', function () {
    (new ScrapedPricesFile($this->path))->write(['providers' => [
        'openai' => ['currency' => 'USD', 'models' => ['gpt-old' => ['tiers' => [['tier' => 'standard', 'input_price' => 5]]]]],
    ]]);
    $this->postJson('/spectra/api/maintenance/pricing/check')->assertStatus(202);

    $this->postJson('/spectra/api/maintenance/pricing/apply', ['models' => ['openai' => ['gpt-x']]])
        ->assertOk()
        ->assertJsonPath('applied', 1);

    $file = ($this->readFile)();
    expect(array_keys($file['providers']['openai']['models']))->toBe(['gpt-old', 'gpt-x'])
        ->and($file['providers']['openai']['models']['gpt-x']['tiers'][0])->toMatchArray(['tier' => 'standard', 'input_price' => 90, 'output_price' => 400])
        ->and($file['providers']['openai']['sources'])->toBe(['https://example.test/pricing']);

    $models = collect($this->getJson('/spectra/api/maintenance/pricing')->json('review.providers.0.models'))->keyBy('id');
    expect($models['gpt-x']['applied'])->toBeTrue()
        ->and($models['gpt-z']['applied'])->toBeFalse();
    $this->getJson('/spectra/api/maintenance/pricing')->assertJsonPath('last_apply.models', 1);
});

it('ignores models and providers that are not in the last check', function () {
    $this->postJson('/spectra/api/maintenance/pricing/check')->assertStatus(202);

    $this->postJson('/spectra/api/maintenance/pricing/apply', ['models' => ['openai' => ['gpt-y'], 'acme' => ['acme-1']]])
        ->assertOk()
        ->assertJsonPath('applied', 0);

    expect(File::exists($this->path))->toBeFalse();
});

it('refuses to apply without a check', function () {
    $this->postJson('/spectra/api/maintenance/pricing/apply', ['models' => ['openai' => ['gpt-x']]])
        ->assertUnprocessable();
});

it('dismisses the check', function () {
    $this->postJson('/spectra/api/maintenance/pricing/check')->assertStatus(202);

    $this->deleteJson('/spectra/api/maintenance/pricing/review')->assertNoContent();

    expect(PricingReview::get())->toBeNull();
    $this->getJson('/spectra/api/maintenance/pricing')->assertJsonPath('review', null);
});

it('refuses checking, applying and dismissing to a viewer the manageSpectra gate refuses', function () {
    Queue::fake();
    Gate::define('manageSpectra', fn ($user = null) => false);

    $this->postJson('/spectra/api/maintenance/pricing/check')->assertForbidden();
    $this->postJson('/spectra/api/maintenance/pricing/apply', ['models' => ['openai' => ['gpt-x']]])->assertForbidden();
    $this->deleteJson('/spectra/api/maintenance/pricing/review')->assertForbidden();
    $this->getJson('/spectra/api/maintenance/pricing')->assertOk()->assertJsonPath('can_manage', false);

    Queue::assertNothingPushed();
});
