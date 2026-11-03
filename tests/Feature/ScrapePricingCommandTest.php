<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Pricing\PricingLookup;
use Spectra\Support\Pricing\ScrapedPricesFile;
use Spectra\Tests\Fixtures\FakeAnthropicPricing;
use Spectra\Tests\Fixtures\FakeOpenAIPricing;

/**
 * spectra:pricing-scrape reads each provider's pricing pages with Firecrawl
 * and saves the prices that differ from the catalog to the scraped prices file.
 */
beforeEach(function () {
    $this->path = storage_path('framework/testing/pricing-'.uniqid().'.json');

    config([
        'spectra.costs.pricing' => ['openai' => FakeOpenAIPricing::class],
        'spectra.costs.scraped_prices_path' => $this->path,
        'spectra.costs.firecrawl.api_key' => 'fc-test',
        'spectra.costs.firecrawl.providers' => ['openai'],
    ]);

    // The page text every scraped price is checked against.
    $this->markdown = implode("\n", [
        '| Model | Input | Output | Cached input |',
        '| --- | --- | --- | --- |',
        '| gpt-x | $0.90 | $4.00 | $0.10 |',
        '| gpt-y | $0.30 | $1.20 | - |',
        '| gpt-z | $2.00 | $8.00 | - |',
        'Prices per 1M tokens.',
    ]);

    $this->firecrawl = fn (array $models, ?string $markdown = null) => Http::fake([
        'api.firecrawl.dev/*' => Http::response(['success' => true, 'data' => [
            'markdown' => $markdown ?? $this->markdown,
            'json' => ['models' => $models],
        ]]),
    ]);

    $this->readFile = fn () => json_decode(File::get($this->path), true);
});

afterEach(function () {
    File::delete($this->path);
});

it('saves the changed and new models to the scraped prices file', function () {
    ($this->firecrawl)([
        ['model_id' => 'gpt-x', 'tier' => 'standard', 'input' => 0.9, 'output' => 4],
        ['model_id' => 'gpt-y', 'tier' => 'standard', 'input' => 0.3, 'output' => 1.2],
        ['model_id' => 'gpt-z', 'tier' => 'standard', 'input' => 2, 'output' => 8],
    ]);

    $this->artisan('spectra:pricing-scrape')
        ->expectsOutputToContain('2 catalog models found')
        ->expectsTable(['Model', 'Tier', 'Price', 'Ours', 'Scraped'], [
            ['gpt-x', 'standard', 'input', '1 /1M', '0.9 /1M'],
            ['gpt-z <fg=green>(new)</>', 'standard', 'input', '—', '2 /1M'],
            ['gpt-z <fg=green>(new)</>', 'standard', 'output', '—', '8 /1M'],
        ])
        ->expectsOutputToContain('SPECTRA_USE_SCRAPED_PRICES=true')
        ->assertSuccessful();

    $file = ($this->readFile)();

    expect(array_keys($file['providers']['openai']['models']))->toBe(['gpt-x', 'gpt-z'])
        ->and($file['providers']['openai']['currency'])->toBe('USD')
        ->and($file['providers']['openai']['sources'])->toBe(['https://example.test/pricing'])
        ->and($file['providers']['openai']['models']['gpt-x']['tiers'][0])->toBe(
            ['tier' => 'standard', 'input_price' => 90, 'output_price' => 400, 'cached_input_price' => 10],
        );

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.firecrawl.dev/v2/scrape'
        && $request->hasHeader('Authorization', 'Bearer fc-test')
        && $request['url'] === 'https://example.test/pricing'
        && $request['formats'][0] === 'markdown'
        && $request['formats'][1]['type'] === 'json'
        && $request['formats'][1]['schema']['properties']['models']['items']['properties']['tier']['enum'] === ['batch', 'standard', 'hd']
        && str_contains($request['formats'][1]['prompt'], 'gpt-x, gpt-y, image-x (per image)'));
});

it('prices requests from the saved file once scraped prices are on', function () {
    ($this->firecrawl)([
        ['model_id' => 'gpt-x', 'tier' => 'standard', 'input' => 0.9],
    ]);

    $this->artisan('spectra:pricing-scrape')->assertSuccessful();

    config(['spectra.costs.use_scraped_prices' => true]);
    $this->app->forgetInstance(PricingLookup::class);

    expect(app(PricingLookup::class)->get('openai', 'gpt-x')['input'])->toBe(90)
        ->and(app(PricingLookup::class)->get('openai', 'gpt-x', promptTokens: 300_000)['input'])->toBe(200);
});

it('removes a provider from the file once its prices match the catalog', function () {
    (new ScrapedPricesFile($this->path))->write(['providers' => [
        'openai' => ['models' => ['gpt-x' => ['tiers' => [['tier' => 'standard', 'input_price' => 1]]]]],
        'acme' => ['models' => ['acme-1' => ['tiers' => [['tier' => 'standard', 'input_price' => 1]]]]],
    ]]);
    ($this->firecrawl)([
        ['model_id' => 'gpt-x', 'tier' => 'standard', 'input' => 1, 'output' => 4],
    ], '| gpt-x | $1.00 | $4.00 |');

    $this->artisan('spectra:pricing-scrape')->assertSuccessful();

    expect(array_keys(($this->readFile)()['providers']))->toBe(['acme']);
});

it('keeps the file entries of a provider Firecrawl could not read', function () {
    (new ScrapedPricesFile($this->path))->write(['providers' => [
        'openai' => ['models' => ['gpt-x' => ['tiers' => [['tier' => 'standard', 'input_price' => 1]]]]],
    ]]);
    Http::fake(['api.firecrawl.dev/*' => Http::response(['success' => false, 'error' => 'Payment required'], 402)]);

    $this->artisan('spectra:pricing-scrape')
        ->expectsOutputToContain('Payment required')
        ->expectsOutputToContain('Nothing was written')
        ->assertFailed();

    expect(array_keys(($this->readFile)()['providers']['openai']['models']))->toBe(['gpt-x']);
});

it('ignores prices the page does not show next to the model', function () {
    ($this->firecrawl)([
        ['model_id' => 'gpt-x', 'tier' => 'standard', 'input' => 0.9, 'output' => 4],
        // Invented by the extraction: not on the page.
        ['model_id' => 'gpt-y', 'tier' => 'standard', 'input' => 0.25, 'output' => 1.2],
    ]);

    $this->artisan('spectra:pricing-scrape')
        ->expectsOutputToContain('gpt-y: standard input 0.25 not found next to the model on the page, ignored.')
        ->assertSuccessful();

    expect(array_keys(($this->readFile)()['providers']['openai']['models']))->toBe(['gpt-x']);
});

it('writes nothing on a dry run', function () {
    ($this->firecrawl)([
        ['model_id' => 'gpt-x', 'tier' => 'standard', 'input' => 0.9],
    ]);

    $this->artisan('spectra:pricing-scrape', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect(File::exists($this->path))->toBeFalse();
});

it('scrapes the given pages for one provider', function () {
    ($this->firecrawl)([]);

    $this->artisan('spectra:pricing-scrape', ['provider' => ['openai'], '--url' => ['https://example.test/a', 'https://example.test/b']])
        ->assertSuccessful();

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => $request['url'] === 'https://example.test/b');
});

describe('choosing providers', function () {
    beforeEach(function () {
        config([
            'spectra.costs.pricing' => ['openai' => FakeOpenAIPricing::class, 'anthropic' => FakeAnthropicPricing::class],
            'spectra.costs.firecrawl.providers' => [],
        ]);
        ($this->firecrawl)([]);
    });

    $scrapedUrls = fn () => Http::recorded()->map(fn (array $pair) => $pair[0]['url'])->all();

    it('scrapes only the providers your app has tracked requests for', function () use ($scrapedUrls) {
        SpectraRequest::factory()->create(['provider' => 'openai']);
        SpectraRequest::factory()->create(['provider' => 'openai']);
        // Tracked, but without a pricing catalog: nothing to scrape.
        SpectraRequest::factory()->create(['provider' => 'ollama']);

        $this->artisan('spectra:pricing-scrape')
            ->expectsOutputToContain('Scraping the providers your app has tracked requests for: openai.')
            ->assertSuccessful();

        expect($scrapedUrls())->toBe(['https://example.test/pricing']);
    });

    it('scrapes the configured providers instead of the tracked ones', function () use ($scrapedUrls) {
        config(['spectra.costs.firecrawl.providers' => ['anthropic']]);
        SpectraRequest::factory()->create(['provider' => 'openai']);

        $this->artisan('spectra:pricing-scrape')
            ->expectsOutputToContain('Scraping the providers in SPECTRA_SCRAPE_PROVIDERS: anthropic.')
            ->assertSuccessful();

        expect($scrapedUrls())->toBe(['https://example.test/anthropic-pricing']);
    });

    it('scrapes the providers named on the command line over everything else', function () use ($scrapedUrls) {
        config(['spectra.costs.firecrawl.providers' => ['openai']]);

        $this->artisan('spectra:pricing-scrape', ['provider' => ['anthropic']])->assertSuccessful();

        expect($scrapedUrls())->toBe(['https://example.test/anthropic-pricing']);
    });

    it('scrapes every provider with --all', function () use ($scrapedUrls) {
        $this->artisan('spectra:pricing-scrape', ['--all' => true])->assertSuccessful();

        expect($scrapedUrls())->toBe(['https://example.test/pricing', 'https://example.test/anthropic-pricing']);
    });

    it('scrapes nothing when there is nothing to go by', function () {
        $this->artisan('spectra:pricing-scrape')
            ->expectsOutputToContain('Spectra has not tracked requests for a provider with pricing pages yet')
            ->assertFailed();

        Http::assertNothingSent();
    });

    it('rejects an unknown configured provider', function () {
        config(['spectra.costs.firecrawl.providers' => ['nope']]);

        $this->artisan('spectra:pricing-scrape')
            ->expectsOutputToContain("No pricing catalog for 'nope'")
            ->assertFailed();

        Http::assertNothingSent();
    });
});

it('fails without a Firecrawl API key', function () {
    config(['spectra.costs.firecrawl.api_key' => null]);
    Http::fake();

    $this->artisan('spectra:pricing-scrape')
        ->expectsOutputToContain('FIRECRAWL_API_KEY')
        ->assertFailed();

    Http::assertNothingSent();
});

it('rejects an unknown provider named on the command line', function () {
    Http::fake();

    $this->artisan('spectra:pricing-scrape', ['provider' => ['nope']])
        ->expectsOutputToContain("No pricing catalog for 'nope'")
        ->assertFailed();
});

it('fails when --url is not given exactly one provider', function () {
    Http::fake();

    $this->artisan('spectra:pricing-scrape', ['--url' => ['https://example.test/a']])
        ->expectsOutputToContain('--url needs exactly one provider')
        ->assertFailed();
});

it('refuses to overwrite a file it cannot read', function () {
    File::ensureDirectoryExists(dirname($this->path));
    File::put($this->path, 'not json');
    Http::fake();

    $this->artisan('spectra:pricing-scrape')
        ->expectsOutputToContain('not valid JSON')
        ->assertFailed();

    expect(File::get($this->path))->toBe('not json');
    Http::assertNothingSent();
});
