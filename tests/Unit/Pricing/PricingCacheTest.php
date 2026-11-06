<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spectra\Pricing\ProviderPricing;
use Spectra\Support\Pricing\PricingCatalog;
use Spectra\Support\Pricing\PricingFile;
use Spectra\Support\Pricing\PricingLookup;
use Spectra\Tests\Fixtures\FakeCatalogs;

/**
 * With spectra.costs.cache on (the default), the pricing lookup's index is
 * kept in the cache store and rebuilt when a pricing file changes, so the
 * files are not parsed on every request. A cache that fails never stops
 * cost tracking.
 */
beforeEach(function () {
    config([
        'cache.default' => 'array',
        'spectra.costs.cache' => ['enabled' => true, 'store' => null, 'ttl' => 60],
    ]);
    Cache::flush();

    $this->bundled = FakeCatalogs::useBundled();
    $this->path = storage_path('framework/testing/pricing-'.uniqid().'.json');
});

afterEach(function () {
    File::delete([$this->path, $this->bundled]);
});

it('keeps the lookup in the cache and reuses it', function () {
    $first = PricingCatalog::lookup();

    expect(Cache::has('spectra:pricing'))->toBeTrue()
        ->and(PricingCatalog::lookup())->toBe($first)
        ->and($first->get('openai', 'gpt-x')['input'])->toBe(100);
});

it('rebuilds the lookup when a pricing file changes', function () {
    config(['spectra.costs.pricing_path' => $this->path]);
    (new PricingFile($this->path))->write(['providers' => ['openai' => ['models' => [
        'gpt-x' => ['tiers' => [['tier' => 'standard', 'input_price' => 90, 'output_price' => 360]]],
    ]]]]);

    expect(PricingCatalog::lookup()->get('openai', 'gpt-x')['input'])->toBe(90);

    (new PricingFile($this->path))->write(['providers' => ['openai' => ['models' => [
        'gpt-x' => ['tiers' => [['tier' => 'standard', 'input_price' => 12345, 'output_price' => 360]]],
    ]]]]);

    expect(PricingCatalog::lookup()->get('openai', 'gpt-x')['input'])->toBe(12345);
});

it('stores nothing while the cache is off', function () {
    config(['spectra.costs.cache.enabled' => false]);

    expect(PricingCatalog::lookup()->get('openai', 'gpt-x')['input'])->toBe(100)
        ->and(Cache::has('spectra:pricing'))->toBeFalse();
});

it('serializes to its index and back', function () {
    $lookup = new PricingLookup([
        FakeCatalogs::openai(),
        new ProviderPricing('acme', [
            'acme-video' => ['type' => 'video', 'pricing_unit' => 'second', 'variants' => ['720p' => ['audio' => 40, 'silent' => 20]], 'tiers' => [['tier' => 'standard', 'price_per_unit' => 40]]],
        ], 'EUR', toolCalls: ['web_search_call' => 1.5]),
    ]);

    $copy = unserialize(serialize($lookup));

    expect($copy)->toBeInstanceOf(PricingLookup::class)
        ->and($copy->get('openai', 'gpt-x-latest')['input'])->toBe(100)
        ->and($copy->get('openai', 'gpt-x', promptTokens: 300_000)['input'])->toBe(200)
        ->and($copy->getDisplayName('openai', 'image-x'))->toBe('Image X')
        ->and($copy->currency('acme'))->toBe('EUR')
        ->and($copy->getToolCallPricing('acme'))->toBe(['web_search_call' => 1.5])
        ->and($copy->toolCallCurrency('acme'))->toBe('EUR')
        ->and($copy->getVariants('acme', 'acme-video'))->toBe(['720p' => ['audio' => 40, 'silent' => 20]])
        ->and($copy->hasCatalog('ollama'))->toBeFalse();
});

it('builds the lookup from the files when the cache store is unusable, and warns once', function () {
    config(['spectra.costs.cache.store' => 'nope']);
    Log::spy();

    expect(PricingCatalog::lookup()->get('openai', 'gpt-x')['input'])->toBe(100)
        ->and(PricingCatalog::lookup()->get('openai', 'gpt-y')['input'])->toBe(30);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => Str::contains($message, 'pricing cache'));
});

it('is used by the lookup the container resolves', function () {
    $lookup = app(PricingLookup::class);

    expect(Cache::get('spectra:pricing')['lookup'])->toBe($lookup);
});
