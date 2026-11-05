<?php

declare(strict_types=1);

/**
 * How the service provider treats a user's published config: built-in
 * providers and pricing classes stay active under it, and a bad watcher
 * entry is reported instead of breaking the app's boot.
 */

use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Spectra\Pricing\OpenAIPricing;
use Spectra\Providers\Groq\Groq;
use Spectra\Providers\OpenAI\OpenAI;
use Spectra\SpectraServiceProvider;
use Spectra\Support\ProviderRegistry;

it('keeps built-in providers active under a published providers list', function () {
    config(['spectra.providers' => ['custom' => ['class' => OpenAI::class, 'name' => 'Custom']]]);

    (new SpectraServiceProvider($this->app))->register();

    expect(config('spectra.providers.groq.class'))->toBe(Groq::class)
        ->and(config('spectra.providers.custom.name'))->toBe('Custom');
});

it('lets the published config override a built-in provider', function () {
    config(['spectra.providers' => ['openai' => ['class' => OpenAI::class, 'name' => 'My OpenAI']]]);

    (new SpectraServiceProvider($this->app))->register();

    expect(config('spectra.providers.openai.name'))->toBe('My OpenAI');
});

it('turns a built-in provider off when the published config sets it to false', function () {
    config(['spectra.providers' => ['groq' => false]]);

    (new SpectraServiceProvider($this->app))->register();

    $registry = new ProviderRegistry(config('spectra.providers'));

    expect($registry->slugs())->not->toContain('groq')
        ->toContain('openai');
});

it('keeps built-in pricing classes active under a published pricing list', function () {
    config(['spectra.costs.pricing' => ['groq' => false]]);

    (new SpectraServiceProvider($this->app))->register();

    expect(config('spectra.costs.pricing.openai'))->toBe(OpenAIPricing::class)
        ->and(config('spectra.costs.pricing.groq'))->toBeFalse();
});

it('reports a watcher class that does not exist and keeps booting', function () {
    Exceptions::fake();
    config(['spectra.watcher.watchers' => ['App\\Watchers\\Misspelled']]);

    (new SpectraServiceProvider($this->app))->boot();

    Exceptions::assertReported(fn (InvalidArgumentException $e) => Str::contains($e->getMessage(), 'App\\Watchers\\Misspelled'));
});
