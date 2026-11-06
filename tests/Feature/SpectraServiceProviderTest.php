<?php

declare(strict_types=1);

/**
 * How the service provider treats a user's published config: built-in
 * providers stay active under it, and a bad watcher
 * entry is reported instead of breaking the app's boot.
 */

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
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

it('reports a watcher class that does not exist and keeps booting', function () {
    Exceptions::fake();
    config(['spectra.watcher.watchers' => ['App\\Watchers\\Misspelled']]);

    (new SpectraServiceProvider($this->app))->boot();

    Exceptions::assertReported(fn (InvalidArgumentException $e) => Str::contains($e->getMessage(), 'App\\Watchers\\Misspelled'));
});

/**
 * Build the package config as an app that published it under an older
 * version would have it: only the keys that version's file had.
 *
 * @param  array<int, string>  $keys  Dotted keys of that version's config
 * @return array<string, mixed>
 */
function configPublishedWithKeys(array $keys, ?array $node = null, string $prefix = ''): array
{
    $node ??= require __DIR__.'/../../config/spectra.php';
    $published = [];

    foreach ($node as $key => $value) {
        $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

        if (! in_array($path, $keys, true)) {
            continue;
        }

        $isSection = is_array($value) && $value !== [] && ! array_is_list($value) && $path !== 'providers';
        $published[$key] = $isSection ? configPublishedWithKeys($keys, $value, $path) : $value;
    }

    return $published;
}

it('fills every key a config published under 1.0 lacks, so their env variables work', function () {
    config(['spectra' => configPublishedWithKeys(require __DIR__.'/../Fixtures/config-keys-1.0.php')]);
    putenv('SPECTRA_REDACTION_ENABLED=true');

    try {
        (new SpectraServiceProvider($this->app))->register();
    } finally {
        putenv('SPECTRA_REDACTION_ENABLED');
    }

    $missing = collect(Arr::dot(require __DIR__.'/../../config/spectra.php'))
        ->keys()
        ->reject(fn (string $key) => config()->has("spectra.{$key}"))
        ->all();

    expect($missing)->toBe([])
        ->and(config('spectra.storage.redaction.enabled'))->toBeTrue()
        ->and(config('spectra.storage.sampling'))->toBe(['rate' => 1.0, 'keep_failures' => true])
        ->and(config('spectra.tracking.tool_spans'))->toBeFalse()
        ->and(config('spectra.dashboard.maintenance'))->toBeTrue()
        ->and(config('spectra.tracking.trace_per_request'))->toBeTrue();
});

it('keeps the nested keys a published config sets', function () {
    config(['spectra.storage.redaction' => ['enabled' => false, 'patterns' => ['email']], 'spectra.dashboard.maintenance' => false]);

    (new SpectraServiceProvider($this->app))->register();

    expect(config('spectra.storage.redaction'))->toBe(['enabled' => false, 'patterns' => ['email']])
        ->and(config('spectra.dashboard.maintenance'))->toBeFalse();
});
