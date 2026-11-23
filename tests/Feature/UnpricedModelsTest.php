<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spectra\Facades\Spectra;
use Spectra\Support\Pricing\PricingLookup;

/**
 * A priced provider's model that is missing from its catalog is recorded at
 * $0 and reported once per process, so missing spend is visible; a provider
 * without a catalog (Ollama) and a cost the provider reported itself are not.
 */
it('logs once per model when a priced provider\'s model has no price', function () {
    Log::spy();

    Spectra::track('openai', 'ft:gpt-4o-mini-2024-07-18:acme::abc', fn () => ['usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]]);
    Spectra::track('openai', 'ft:gpt-4o-mini-2024-07-18:acme::abc', fn () => ['usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]]);
    Spectra::track('openai', 'gpt-4o', fn () => ['usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]]);

    // Counted by its message: another once-per-process warning may come first.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => Str::containsAll($message, ["'ft:gpt-4o-mini-2024-07-18:acme::abc'", 'no price', 'openai']))
        ->once();
});

it('stays quiet for a provider without a catalog', function () {
    Log::spy();

    Spectra::track('ollama', 'llama3.3:70b', fn () => ['prompt_eval_count' => 10, 'eval_count' => 5]);

    Log::shouldNotHaveReceived('warning');
    expect(app(PricingLookup::class)->isUnpriced('ollama', 'llama3.3:70b'))->toBeFalse();
});

it('stays quiet when the provider reported the cost itself', function () {
    Log::spy();

    Spectra::track('openrouter', 'acme/brand-new-model', fn () => ['choices' => [['message' => ['content' => 'Hi']]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'cost' => 0.0012]]);

    Log::shouldNotHaveReceived('warning');
    expect(app(PricingLookup::class)->isUnpriced('openrouter', 'acme/brand-new-model'))->toBeFalse();
});

it('does not call a dated snapshot of a priced model unpriced', function () {
    expect(app(PricingLookup::class)->isUnpriced('openai', 'gpt-4o-2024-08-06'))->toBeFalse();
});
