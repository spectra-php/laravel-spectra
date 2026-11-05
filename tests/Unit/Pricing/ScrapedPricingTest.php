<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spectra\Pricing\ScrapedPricing;
use Spectra\SpectraServiceProvider;
use Spectra\Support\Pricing\PricingLookup;
use Spectra\Support\Pricing\ScrapedPricesFile;
use Spectra\Tests\Fixtures\FakeOpenAIExtraPricing;
use Spectra\Tests\Fixtures\FakeOpenAIPricing;

/**
 * spectra.costs.use_scraped_prices lets the models in the scraped prices
 * file replace built-in models with the same id; everything else keeps its
 * built-in price. Off (the default), the file is ignored.
 */
beforeEach(function () {
    $this->path = storage_path('framework/testing/pricing-'.uniqid().'.json');

    config([
        'spectra.costs.pricing' => ['openai' => FakeOpenAIPricing::class],
        'spectra.costs.scraped_prices_path' => $this->path,
    ]);

    $this->writeScrapedPricesFile = function (array $providers): void {
        (new ScrapedPricesFile($this->path))->write(['providers' => $providers]);
        $this->app->forgetInstance(PricingLookup::class);
    };

    $this->overrides = [
        'openai' => [
            'currency' => 'USD',
            'models' => [
                'gpt-x' => [
                    'display_name' => 'GPT X',
                    'capabilities' => ['text'],
                    'aliases' => ['gpt-x-latest'],
                    'tiers' => [
                        ['tier' => 'standard', 'input_price' => 90, 'output_price' => 360],
                    ],
                ],
                'gpt-new' => [
                    'display_name' => 'GPT New',
                    'tiers' => [['tier' => 'standard', 'input_price' => 5, 'output_price' => 20]],
                ],
            ],
        ],
    ];
});

afterEach(function () {
    File::delete($this->path);
});

it('ignores the file while scraped prices are off', function () {
    config(['spectra.costs.use_scraped_prices' => false]);
    ($this->writeScrapedPricesFile)($this->overrides);

    $lookup = app(PricingLookup::class);

    expect($lookup->get('openai', 'gpt-x')['input'])->toBe(100)
        ->and($lookup->knows('openai', 'gpt-new'))->toBeFalse();
});

it('keeps every configured catalog, even two for the same provider', function (bool $useScrapedPrices) {
    config([
        'spectra.costs.use_scraped_prices' => $useScrapedPrices,
        'spectra.costs.pricing' => ['openai' => FakeOpenAIPricing::class, 'openai-fine-tunes' => FakeOpenAIExtraPricing::class],
    ]);
    ($this->writeScrapedPricesFile)($this->overrides);

    $lookup = app(PricingLookup::class);

    expect($lookup->get('openai', 'gpt-y')['input'])->toBe(30)
        ->and($lookup->get('openai', 'ft:gpt-x:acme')['input'])->toBe(300);
})->with(['scraped prices off' => false, 'scraped prices on' => true]);

it('replaces built-in models with the file and keeps the rest', function () {
    config(['spectra.costs.use_scraped_prices' => true]);
    ($this->writeScrapedPricesFile)($this->overrides);

    $lookup = app(PricingLookup::class);

    expect($lookup->get('openai', 'gpt-x')['input'])->toBe(90)
        ->and($lookup->get('openai', 'gpt-x')['output'])->toBe(360)
        // The file's entry replaces the whole model, long-context rows included.
        ->and($lookup->get('openai', 'gpt-x', promptTokens: 300_000)['input'])->toBe(90)
        ->and($lookup->get('openai', 'gpt-x-latest')['input'])->toBe(90)
        ->and($lookup->get('openai', 'gpt-x-2026-01-01')['input'])->toBe(90)
        ->and($lookup->get('openai', 'gpt-new')['output'])->toBe(20)
        ->and($lookup->get('openai', 'gpt-y')['input'])->toBe(30)
        ->and($lookup->getUnit('openai', 'image-x'))->toBe('image');
});

it('adds providers that have no built-in catalog', function () {
    config(['spectra.costs.use_scraped_prices' => true]);
    ($this->writeScrapedPricesFile)(['acme' => ['models' => [
        'acme-1' => ['tiers' => [['tier' => 'standard', 'input_price' => 1, 'output_price' => 2]]],
    ]]]);

    $lookup = app(PricingLookup::class);

    expect($lookup->get('acme', 'acme-1')['output'])->toBe(2)
        ->and($lookup->currency('acme'))->toBe('USD');
});

it('falls back to the built-in prices when the file is missing', function () {
    config(['spectra.costs.use_scraped_prices' => true]);
    Log::spy();

    expect(app(PricingLookup::class)->get('openai', 'gpt-x')['input'])->toBe(100);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => Str::contains($message, "doesn't exist"));
});

it('falls back to the built-in prices when the file is not valid JSON', function () {
    config(['spectra.costs.use_scraped_prices' => true]);
    File::ensureDirectoryExists(dirname($this->path));
    File::put($this->path, '{"providers": ');
    Log::spy();

    expect(app(PricingLookup::class)->get('openai', 'gpt-x')['input'])->toBe(100);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => Str::contains($message, 'not valid JSON'));
});

it('skips a provider whose currency differs from its built-in catalog', function () {
    config(['spectra.costs.use_scraped_prices' => true]);
    $this->overrides['openai']['currency'] = 'EUR';
    ($this->writeScrapedPricesFile)($this->overrides);
    Log::spy();

    $lookup = app(PricingLookup::class);

    expect($lookup->get('openai', 'gpt-x')['input'])->toBe(100)
        ->and($lookup->currency('openai'))->toBe('USD');

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => Str::contains($message, 'in EUR'));
});

it('skips a broken model and loads the others', function (array $broken, string $reason) {
    config(['spectra.costs.use_scraped_prices' => true]);
    $this->overrides['openai']['models']['gpt-y'] = $broken;
    ($this->writeScrapedPricesFile)($this->overrides);
    Log::spy();

    $lookup = app(PricingLookup::class);

    expect($lookup->get('openai', 'gpt-x')['input'])->toBe(90)
        ->and($lookup->get('openai', 'gpt-y')['input'])->toBe(30);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => Str::contains($message, "'gpt-y'") && Str::contains($message, $reason));
})->with([
    'no tiers' => [['tiers' => []], 'no tiers'],
    'unknown tier' => [['tiers' => [['tier' => 'gold', 'input_price' => 1]]], "unknown tier 'gold'"],
    'non-numeric price' => [['tiers' => [['tier' => 'standard', 'input_price' => 'cheap']]], 'input_price must be a number'],
    'bad unit' => [['pricing_unit' => 'liters', 'tiers' => [['tier' => 'standard', 'price_per_unit' => 1]]], 'liters'],
    'bad date' => [['tiers' => [['tier' => 'standard', 'input_price' => 1, 'since' => 'soon']]], 'Y-m-d'],
    'unknown capability' => [['capabilities' => ['telepathy'], 'tiers' => [['tier' => 'standard']]], 'telepathy'],
    'no standard tier' => [['tiers' => [['tier' => 'batch', 'input_price' => 1, 'output_price' => 2]]], 'no standard tier'],
]);

it('round-trips every built-in model through its JSON form', function () {
    $classes = (require dirname(__DIR__, 3).'/config/spectra.php')['costs']['pricing'];

    foreach ($classes as $class) {
        foreach ((new $class)->models() as $definition) {
            $json = json_decode(json_encode(ScrapedPricing::definitionToArray($definition), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            $copy = ScrapedPricing::definitionFromArray($definition->getInternalName(), $json);

            expect($copy->toTiersArray())->toEqual($definition->toTiersArray(), "{$class}: {$definition->getInternalName()}")
                ->and($copy->toModelArray())->toBe($definition->toModelArray());
        }
    }
});

it('writes compact entries without empty fields', function () {
    $definition = collect((new FakeOpenAIPricing)->models())->first(fn ($d) => $d->getInternalName() === 'gpt-y');

    expect(ScrapedPricing::definitionToArray($definition))->toBe([
        'display_name' => 'GPT Y',
        'type' => 'text',
        'pricing_unit' => 'tokens',
        'capabilities' => ['text'],
        'tiers' => [['tier' => 'standard', 'input_price' => 30.0, 'output_price' => 120.0]],
    ]);
});

it('fills the scraped prices keys into a config published before they existed', function () {
    config(['spectra.costs' => ['enabled' => true, 'pricing' => ['openai' => FakeOpenAIPricing::class]]]);
    putenv('SPECTRA_USE_SCRAPED_PRICES=true');

    try {
        (new SpectraServiceProvider($this->app))->register();
    } finally {
        putenv('SPECTRA_USE_SCRAPED_PRICES');
    }

    expect(config('spectra.costs.use_scraped_prices'))->toBeTrue()
        ->and(config('spectra.costs.scraped_prices_path'))->toBe(resource_path('spectra/scraped-prices.json'))
        ->and(config('spectra.costs.firecrawl.url'))->toBe('https://api.firecrawl.dev/v2')
        ->and(config('spectra.costs.pricing.openai'))->toBe(FakeOpenAIPricing::class);
});

it('keeps scraped prices keys the app config already has', function () {
    config(['spectra.costs.use_scraped_prices' => false, 'spectra.costs.firecrawl' => ['api_key' => 'mine']]);

    (new SpectraServiceProvider($this->app))->register();

    expect(config('spectra.costs.use_scraped_prices'))->toBeFalse()
        ->and(config('spectra.costs.firecrawl'))->toBe(['api_key' => 'mine']);
});
