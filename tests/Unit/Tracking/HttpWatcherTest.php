<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Spectra\Models\SpectraRequest;
use Spectra\Providers\OpenAI\OpenAI;
use Spectra\Support\ProviderRegistry;
use Spectra\Watchers\HttpWatcher;

/**
 * HttpWatcher detects the provider of an HTTP client request by host, tracks
 * only provider calls, and reads the OpenAI pricing tier.
 */
it('detects openai requests', function () {
    $registry = app(ProviderRegistry::class);

    expect($registry->detectProvider('api.openai.com'))->toBe('openai');
});

it('detects anthropic requests', function () {
    $registry = app(ProviderRegistry::class);

    expect($registry->detectProvider('api.anthropic.com'))->toBe('anthropic');
});

it('detects google requests', function () {
    $registry = app(ProviderRegistry::class);

    expect($registry->detectProvider('generativelanguage.googleapis.com'))->toBe('google');
});

it('returns null for unknown hosts', function () {
    $registry = app(ProviderRegistry::class);

    expect($registry->detectProvider('example.com'))->toBeNull();
    expect($registry->detectProvider('api.unknown.com'))->toBeNull();
});

it('detects all supported providers', function () {
    $registry = app(ProviderRegistry::class);

    $providers = [
        'api.openai.com' => 'openai',
        'api.anthropic.com' => 'anthropic',
        'generativelanguage.googleapis.com' => 'google',
        'openrouter.ai' => 'openrouter',
        'api.cohere.com' => 'cohere',
        'api.groq.com' => 'groq',
        'api.x.ai' => 'xai',
        'api.elevenlabs.io' => 'elevenlabs',
        'api.replicate.com' => 'replicate',
    ];

    foreach ($providers as $host => $expectedProvider) {
        expect($registry->detectProvider($host))->toBe($expectedProvider, "Failed for host: {$host}");
    }
});

it('tracks http client requests to provider hosts and ignores other hosts', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(['id' => 'chatcmpl-1', 'object' => 'chat.completion', 'model' => 'gpt-4o', 'choices' => [], 'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2]]),
        'example.com/*' => Http::response(['ok' => true]),
    ]);

    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o', 'messages' => []]);
    Http::post('https://example.com/v1/chat/completions', ['model' => 'gpt-4o']);

    expect(SpectraRequest::count())->toBe(1)
        ->and(SpectraRequest::first()->provider)->toBe('openai');
});

it('registers its middleware on the http client only once', function () {
    (new HttpWatcher)->register();
    (new HttpWatcher)->register();

    Http::fake(['api.openai.com/*' => Http::response(['id' => 'chatcmpl-2', 'model' => 'gpt-4o', 'choices' => [], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]])]);
    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o']);

    expect(SpectraRequest::count())->toBe(1);
});

it('skips requests marked with withoutAITracking', function () {
    Http::fake(['api.openai.com/*' => Http::response(['id' => 'chatcmpl-3', 'model' => 'gpt-4o', 'choices' => []])]);

    Http::withoutAITracking()->post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o']);

    expect(SpectraRequest::count())->toBe(0);
});

it('extracts openai pricing tier from request', function () {
    $provider = new OpenAI;

    expect($provider->extractPricingTierFromRequest(['service_tier' => 'flex']))->toBe('flex');
    expect($provider->extractPricingTierFromRequest(['service_tier' => 'priority']))->toBe('priority');
    expect($provider->extractPricingTierFromRequest(['service_tier' => 'batch']))->toBe('batch');
    expect($provider->extractPricingTierFromRequest(['service_tier' => 'standard']))->toBe('standard');

    config(['spectra.costs.provider_settings.openai.default_tier' => 'standard']);
    expect($provider->extractPricingTierFromRequest(['service_tier' => 'auto']))->toBe('standard');
    expect($provider->extractPricingTierFromRequest(['service_tier' => 'default']))->toBe('standard');

    expect($provider->extractPricingTierFromRequest([]))->toBeNull();
    expect($provider->extractPricingTierFromRequest(['model' => 'gpt-4o']))->toBeNull();
});

it('extracts openai pricing tier from response', function () {
    $provider = new OpenAI;

    expect($provider->extractPricingTierFromResponse(['service_tier' => 'flex']))->toBe('flex');
    expect($provider->extractPricingTierFromResponse(['service_tier' => 'priority']))->toBe('priority');
    expect($provider->extractPricingTierFromResponse(['service_tier' => 'batch']))->toBe('batch');
    expect($provider->extractPricingTierFromResponse(['service_tier' => 'standard']))->toBe('standard');
    expect($provider->extractPricingTierFromResponse([]))->toBeNull();
});
