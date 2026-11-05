<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Spectra\Support\Pricing\ScrapedPricesFile;
use Spectra\Tests\Fixtures\FakeOpenAIPricing;

/**
 * The maintenance screen's prices card is read-only: it shows whether
 * requests are billed with the scraped prices file and what the file holds.
 * Prices are checked and written with spectra:pricing-scrape where you
 * develop; the dashboard runs in production and never writes them.
 */
beforeEach(function () {
    Gate::define('viewSpectra', fn ($user = null) => true);
    Gate::define('manageSpectra', fn ($user = null) => true);

    $this->path = storage_path('framework/testing/pricing-'.uniqid().'.json');

    config([
        'spectra.costs.pricing' => ['openai' => FakeOpenAIPricing::class],
        'spectra.costs.scraped_prices_path' => $this->path,
        'spectra.costs.firecrawl.api_key' => 'fc-test',
        'spectra.costs.firecrawl.providers' => ['openai'],
    ]);
});

afterEach(function () {
    File::delete($this->path);
});

it('shows the built-in prices and no file before anything is scraped', function () {
    $this->getJson('/spectra/api/maintenance/pricing')
        ->assertOk()
        ->assertJsonPath('uses_scraped_prices', false)
        ->assertJsonPath('file.exists', false)
        ->assertJsonPath('file.providers', []);
});

it('summarises the deployed scraped prices file', function () {
    config(['spectra.costs.use_scraped_prices' => true]);
    (new ScrapedPricesFile($this->path))->write(['providers' => [
        'openai' => ['currency' => 'USD', 'scraped_at' => '2026-11-05', 'models' => [
            'gpt-x' => ['tiers' => [['tier' => 'standard', 'input_price' => 90, 'output_price' => 400]]],
            'gpt-z' => ['tiers' => [['tier' => 'standard', 'input_price' => 200, 'output_price' => 800]]],
        ]],
    ]]);

    $this->getJson('/spectra/api/maintenance/pricing')
        ->assertOk()
        ->assertJsonPath('uses_scraped_prices', true)
        ->assertJsonPath('file.exists', true)
        ->assertJsonPath('file.error', null)
        ->assertJsonPath('file.providers.openai', ['scraped_at' => '2026-11-05', 'models' => 2]);
});

it('says why the file cannot be read', function () {
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
