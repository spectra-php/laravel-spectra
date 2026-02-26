<?php

use Spectra\Providers\OpenAI\OpenAI;
use Spectra\Support\ProviderRegistry;

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

it('watches registered providers and rejects unknown providers', function () {
    $watcher = $this->makeHttpWatcher();

    expect($this->callHttpWatcherProtected($watcher, 'shouldWatch', 'openai'))->toBeTrue();
    expect($this->callHttpWatcherProtected($watcher, 'shouldWatch', 'anthropic'))->toBeTrue();
    expect($this->callHttpWatcherProtected($watcher, 'shouldWatch', 'google'))->toBeTrue();

    expect($this->callHttpWatcherProtected($watcher, 'shouldWatch', 'unknown-provider'))->toBeFalse();
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
