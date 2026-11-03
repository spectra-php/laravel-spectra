<?php

declare(strict_types=1);

use Spectra\Support\Pricing\PricingLookup;

/**
 * Pricing lookups match a dated model id against its undated catalog entry,
 * whichever date suffix the provider uses.
 */
it('prices a dated snapshot id like its undated catalog entry without an alias', function (string $provider, string $dated, string $undated) {
    $lookup = app(PricingLookup::class);

    expect($lookup->canonicalModel($provider, $dated))->toBe($undated)
        ->and($lookup->get($provider, $dated))->toBe($lookup->get($provider, $undated))
        ->and($lookup->get($provider, $dated, 'batch'))->toBe($lookup->get($provider, $undated, 'batch'));
})->with([
    'anthropic -YYYYMMDD' => ['anthropic', 'claude-sonnet-4-20250514', 'claude-sonnet-4'],
    'openai -YYYY-MM-DD' => ['openai', 'gpt-4-turbo-2024-04-09', 'gpt-4-turbo'],
]);
