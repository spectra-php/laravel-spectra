<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Spectra\Support\Pricing\PricingFile;
use Spectra\Tests\Fixtures\FakeCatalogs;

/**
 * The maintenance screen's prices card is read-only: it shows the bundled
 * catalog, the app's own pricing file when it has one, and the cache.
 * Prices are checked and written with spectra:pricing-scrape where you
 * develop; the dashboard runs in production and never writes them.
 */
beforeEach(function () {
    Gate::define('viewSpectra', fn ($user = null) => true);
    Gate::define('manageSpectra', fn ($user = null) => true);

    $this->bundled = FakeCatalogs::useBundled();
    $this->path = storage_path('framework/testing/pricing-'.uniqid().'.json');

    config([
        'spectra.costs.pricing_path' => null,
        'spectra.costs.firecrawl.api_key' => 'fc-test',
        'spectra.costs.firecrawl.providers' => ['openai'],
    ]);
});

afterEach(function () {
    File::delete([$this->path, $this->bundled]);
});

it('shows the bundled catalog, no file of your own and the cache', function () {
    $this->getJson('/spectra/api/maintenance/pricing')
        ->assertOk()
        ->assertJsonPath('bundled.exists', true)
        ->assertJsonPath('bundled.generated_at', '2026-11-01T00:00:00+00:00')
        ->assertJsonPath('bundled.models', 4)
        ->assertJsonPath('bundled.providers.openai.models', 3)
        ->assertJsonPath('file', null)
        ->assertJsonPath('cache.enabled', true)
        ->assertJsonPath('cache.store', null);
});

it('summarises your pricing file', function () {
    config(['spectra.costs.pricing_path' => $this->path]);
    (new PricingFile($this->path))->write(['providers' => [
        'openai' => ['currency' => 'USD', 'scraped_at' => '2026-11-05', 'models' => [
            'gpt-x' => ['tiers' => [['tier' => 'standard', 'input_price' => 90, 'output_price' => 400]]],
            'gpt-z' => ['tiers' => [['tier' => 'standard', 'input_price' => 200, 'output_price' => 800]]],
        ]],
    ]]);

    $this->getJson('/spectra/api/maintenance/pricing')
        ->assertOk()
        ->assertJsonPath('file.exists', true)
        ->assertJsonPath('file.error', null)
        ->assertJsonPath('file.models', 2)
        ->assertJsonPath('file.providers.openai', ['scraped_at' => '2026-11-05', 'models' => 2]);
});

it('says when your pricing file is missing', function () {
    config(['spectra.costs.pricing_path' => $this->path]);

    $this->getJson('/spectra/api/maintenance/pricing')
        ->assertOk()
        ->assertJsonPath('file.exists', false)
        ->assertJsonPath('file.providers', []);
});

it('says why the file cannot be read', function () {
    config(['spectra.costs.pricing_path' => $this->path]);
    File::put($this->path, '{not json');

    expect($this->getJson('/spectra/api/maintenance/pricing')->assertOk()->json('file.error'))->not->toBeNull();
});

it('cannot check, apply or dismiss prices from the dashboard, even for managers', function () {
    Http::fake();

    foreach ([['post', 'check'], ['post', 'apply'], ['delete', 'review']] as [$method, $path]) {
        expect($this->json($method, "/spectra/api/maintenance/pricing/{$path}", ['models' => ['openai' => ['gpt-x']]])->status())->toBeIn([404, 405]);
    }

    Http::assertNothingSent();
    expect(File::exists($this->path))->toBeFalse();
});
