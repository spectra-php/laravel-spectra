<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spectra\Pricing\ProviderPricing;
use Spectra\SpectraServiceProvider;
use Spectra\Support\Pricing\PricingCatalog;
use Spectra\Support\Pricing\PricingFile;
use Spectra\Support\Pricing\PricingLookup;
use Spectra\Tests\Fixtures\FakeCatalogs;

/**
 * Prices come from the bundled catalog. With spectra.costs.pricing_path
 * set, the models in that file replace bundled models with the same id;
 * everything else keeps its bundled price.
 */
beforeEach(function () {
    $this->bundled = FakeCatalogs::useBundled();
    $this->path = storage_path('framework/testing/pricing-'.uniqid().'.json');

    config([
        'spectra.costs.pricing_path' => $this->path,
        'spectra.costs.cache.enabled' => false,
    ]);

    $this->writePricingFile = function (array $providers, ?string $path = null): void {
        (new PricingFile($path ?? $this->path))->write(['providers' => $providers]);
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
    File::delete([$this->path, $this->bundled]);
});

it('bills with the bundled catalog alone when no pricing path is set', function () {
    config(['spectra.costs.pricing_path' => null]);
    ($this->writePricingFile)($this->overrides);

    $lookup = app(PricingLookup::class);

    expect($lookup->get('openai', 'gpt-x')['input'])->toBe(100)
        ->and($lookup->get('anthropic', 'claude-x')['output'])->toBe(1500)
        ->and($lookup->knows('openai', 'gpt-new'))->toBeFalse();
});

it('replaces bundled models with the file and keeps the rest', function () {
    ($this->writePricingFile)($this->overrides);

    $lookup = app(PricingLookup::class);

    expect($lookup->get('openai', 'gpt-x')['input'])->toBe(90)
        ->and($lookup->get('openai', 'gpt-x')['output'])->toBe(360)
        // The file's entry replaces the whole model, long-context rows included.
        ->and($lookup->get('openai', 'gpt-x', promptTokens: 300_000)['input'])->toBe(90)
        ->and($lookup->get('openai', 'gpt-x-latest')['input'])->toBe(90)
        ->and($lookup->get('openai', 'gpt-x-2026-01-01')['input'])->toBe(90)
        ->and($lookup->get('openai', 'gpt-new')['output'])->toBe(20)
        ->and($lookup->get('openai', 'gpt-y')['input'])->toBe(30)
        ->and($lookup->getUnit('openai', 'image-x'))->toBe('image')
        ->and($lookup->get('anthropic', 'claude-x')['input'])->toBe(300);
});

it('resolves a relative pricing path from the app root', function () {
    $relative = 'spectra-test/pricing-'.uniqid().'.json';
    config(['spectra.costs.pricing_path' => $relative]);
    ($this->writePricingFile)($this->overrides, base_path($relative));

    try {
        expect(app(PricingLookup::class)->get('openai', 'gpt-x')['input'])->toBe(90);
    } finally {
        File::deleteDirectory(base_path('spectra-test'));
    }
});

it('reads the 3.x scraped prices keys of a published config when pricing_path is not set', function () {
    config([
        'spectra.costs.pricing_path' => null,
        'spectra.costs.use_scraped_prices' => true,
        'spectra.costs.scraped_prices_path' => $this->path,
    ]);
    ($this->writePricingFile)($this->overrides);

    expect(app(PricingLookup::class)->get('openai', 'gpt-x')['input'])->toBe(90);
});

it('adds providers that have no bundled catalog', function () {
    ($this->writePricingFile)(['acme' => ['models' => [
        'acme-1' => ['tiers' => [['tier' => 'standard', 'input_price' => 1, 'output_price' => 2]]],
    ]]]);

    $lookup = app(PricingLookup::class);

    expect($lookup->get('acme', 'acme-1')['output'])->toBe(2)
        ->and($lookup->currency('acme'))->toBe('USD');
});

it('keeps the bundled prices when the file is missing', function () {
    Log::spy();

    expect(app(PricingLookup::class)->get('openai', 'gpt-x')['input'])->toBe(100);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => Str::contains($message, "doesn't exist"));
});

it('keeps the bundled prices when the file is not valid JSON', function () {
    File::ensureDirectoryExists(dirname($this->path));
    File::put($this->path, '{"providers": ');
    Log::spy();

    expect(app(PricingLookup::class)->get('openai', 'gpt-x')['input'])->toBe(100);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => Str::contains($message, 'not valid JSON'));
});

it('skips a provider whose currency differs from the bundled catalog', function () {
    $this->overrides['openai']['currency'] = 'EUR';
    ($this->writePricingFile)($this->overrides);
    Log::spy();

    $lookup = app(PricingLookup::class);

    expect($lookup->get('openai', 'gpt-x')['input'])->toBe(100)
        ->and($lookup->currency('openai'))->toBe('USD');

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => Str::contains($message, 'in EUR'));
});

it('skips a broken model and loads the others', function (array $broken, string $reason) {
    $this->overrides['openai']['models']['gpt-y'] = $broken;
    ($this->writePricingFile)($this->overrides);
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
    'variants that are not an object' => [['variants' => 'hd', 'tiers' => [['tier' => 'standard', 'input_price' => 1]]], 'variants must be an object'],
]);

it('keeps the bundled unit and type of a hand-written entry that leaves them out', function () {
    $this->overrides['openai']['models']['image-x'] = ['tiers' => [['tier' => 'standard', 'price_per_unit' => 6]]];
    ($this->writePricingFile)($this->overrides);

    $lookup = app(PricingLookup::class);

    expect($lookup->getUnit('openai', 'image-x'))->toBe('image')
        ->and($lookup->getModelType('openai', 'image-x'))->toBe('image')
        ->and($lookup->get('openai', 'image-x')['price_per_unit'])->toBe(6);
});

it('skips an entry whose pricing unit differs from the bundled model and keeps the bundled price', function () {
    $this->overrides['openai']['models']['image-x'] = ['pricing_unit' => 'tokens', 'tiers' => [['tier' => 'standard', 'input_price' => 1, 'output_price' => 2]]];
    ($this->writePricingFile)($this->overrides);
    Log::spy();

    $lookup = app(PricingLookup::class);

    expect($lookup->getUnit('openai', 'image-x'))->toBe('image')
        ->and($lookup->get('openai', 'image-x')['price_per_unit'])->toBe(4);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => Str::contains($message, ["'image-x'", "pricing_unit 'tokens'", "'image'"]));
});

it('round-trips every model of the bundled catalog through its JSON form', function () {
    PricingCatalog::useBundledFile(null);

    $catalogs = PricingCatalog::bundled();

    expect($catalogs)->not->toBeEmpty();

    foreach ($catalogs as $provider => $catalog) {
        foreach ($catalog->models() as $definition) {
            $json = json_decode(json_encode(ProviderPricing::definitionToArray($definition), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            $copy = ProviderPricing::definitionFromArray($definition->getInternalName(), $json);

            expect($copy->toTiersArray())->toEqual($definition->toTiersArray(), "{$provider}: {$definition->getInternalName()}")
                ->and($copy->toModelArray())->toBe($definition->toModelArray());
        }
    }
});

it('writes compact entries without empty fields', function () {
    $definition = collect(FakeCatalogs::openai()->models())->first(fn ($d) => $d->getInternalName() === 'gpt-y');

    expect(ProviderPricing::definitionToArray($definition))->toBe([
        'display_name' => 'GPT Y',
        'type' => 'text',
        'pricing_unit' => 'tokens',
        'capabilities' => ['text'],
        'tiers' => [['tier' => 'standard', 'input_price' => 30.0, 'output_price' => 120.0]],
    ]);
});

it('fills the pricing keys into a config published before they existed', function () {
    config(['spectra.costs' => ['enabled' => true]]);
    putenv('SPECTRA_PRICING_PATH=prices/mine.json');

    try {
        (new SpectraServiceProvider($this->app))->register();
    } finally {
        putenv('SPECTRA_PRICING_PATH');
    }

    expect(config('spectra.costs.pricing_path'))->toBe('prices/mine.json')
        ->and(config('spectra.costs.cache'))->toBe(['enabled' => true, 'store' => null, 'ttl' => 86400])
        ->and(config('spectra.costs.firecrawl.url'))->toBe('https://api.firecrawl.dev/v2');
});

it('keeps pricing keys the app config already has', function () {
    config(['spectra.costs.cache' => ['enabled' => false], 'spectra.costs.firecrawl' => ['api_key' => 'mine']]);

    (new SpectraServiceProvider($this->app))->register();

    expect(config('spectra.costs.cache'))->toBe(['enabled' => false])
        ->and(config('spectra.costs.firecrawl'))->toBe(['api_key' => 'mine']);
});

it('warns once when a published config still maps pricing classes, and prices as usual', function () {
    PricingCatalog::resetWarnings();
    config(['spectra.costs.pricing_path' => null, 'spectra.costs.pricing' => ['openai' => 'App\\Pricing\\MyOpenAIPricing']]);
    Log::spy();

    expect(app(PricingLookup::class)->get('openai', 'gpt-x')['input'])->toBe(100);
    $this->app->forgetInstance(PricingLookup::class);
    expect(app(PricingLookup::class)->get('openai', 'gpt-y')['input'])->toBe(30);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => Str::contains($message, 'costs.pricing'))->once();
});
