<?php

use Spectra\Support\ApiKeyResolver;

it('resolves api key from spectra config', function () {
    config(['spectra.api_keys.openai' => 'sk-spectra-key']);

    $resolver = new ApiKeyResolver;

    expect($resolver->resolve('openai'))->toBe('sk-spectra-key');
});

it('resolves api key from ai.php config', function () {
    config(['ai.providers.openai.key' => 'sk-ai-key']);

    $resolver = new ApiKeyResolver;

    expect($resolver->resolve('openai'))->toBe('sk-ai-key');
});

it('resolves api key from ai.php for anthropic', function () {
    config(['ai.providers.anthropic.key' => 'sk-ant-ai-key']);

    $resolver = new ApiKeyResolver;

    expect($resolver->resolve('anthropic'))->toBe('sk-ant-ai-key');
});

it('prioritizes spectra config over ai.php for api keys', function () {
    config([
        'spectra.api_keys.openai' => 'sk-spectra-key',
        'ai.providers.openai.key' => 'sk-ai-key',
    ]);

    $resolver = new ApiKeyResolver;

    expect($resolver->resolve('openai'))->toBe('sk-spectra-key');
});

it('resolves base url from spectra endpoint_urls config', function () {
    config(['spectra.endpoint_urls.openai' => 'https://my-proxy.example.com/v1']);

    $resolver = new ApiKeyResolver;

    expect($resolver->resolveBaseUrl('openai'))->toBe('https://my-proxy.example.com/v1');
});

it('resolves base url from ai.php config', function () {
    config(['ai.providers.openai.url' => 'https://ai-proxy.example.com']);

    $resolver = new ApiKeyResolver;

    expect($resolver->resolveBaseUrl('openai'))->toBe('https://ai-proxy.example.com');
});

it('resolves base url from ai.php for anthropic', function () {
    config(['ai.providers.anthropic.url' => 'https://anthropic-proxy.example.com']);

    $resolver = new ApiKeyResolver;

    expect($resolver->resolveBaseUrl('anthropic'))->toBe('https://anthropic-proxy.example.com');
});

it('prioritizes spectra endpoint_urls over ai.php for base url', function () {
    config([
        'spectra.endpoint_urls.openai' => 'https://spectra-proxy.example.com',
        'ai.providers.openai.url' => 'https://ai-proxy.example.com',
    ]);

    $resolver = new ApiKeyResolver;

    expect($resolver->resolveBaseUrl('openai'))->toBe('https://spectra-proxy.example.com');
});

it('resolves base url from openai base_uri config', function () {
    config(['openai.base_uri' => 'https://openai-custom.example.com']);

    $resolver = new ApiKeyResolver;

    expect($resolver->resolveBaseUrl('openai'))->toBe('https://openai-custom.example.com');
});

it('returns null when no base url is configured', function () {
    $resolver = new ApiKeyResolver;

    expect($resolver->resolveBaseUrl('openai'))->toBeNull();
    expect($resolver->resolveBaseUrl('anthropic'))->toBeNull();
});

it('returns null when no api key is configured', function () {
    $resolver = new ApiKeyResolver;

    expect($resolver->resolve('openai'))->toBeNull();
});

it('resolves host from base url', function () {
    config(['spectra.endpoint_urls.openai' => 'https://my-proxy.example.com/v1']);

    $resolver = new ApiKeyResolver;

    expect($resolver->resolveHost('openai'))->toBe('my-proxy.example.com');
});

it('resolves host with port from base url', function () {
    config(['spectra.endpoint_urls.ollama' => 'http://custom-ollama:8080']);

    $resolver = new ApiKeyResolver;

    expect($resolver->resolveHost('ollama'))->toBe('custom-ollama:8080');
});

it('returns null host when no base url is configured', function () {
    $resolver = new ApiKeyResolver;

    expect($resolver->resolveHost('openai'))->toBeNull();
});

it('resolves base url for all configured providers', function () {
    $providers = ['openai', 'anthropic', 'google', 'replicate', 'cohere', 'groq', 'xai', 'elevenlabs', 'openrouter', 'ollama'];

    foreach ($providers as $provider) {
        config(["spectra.endpoint_urls.{$provider}" => "https://{$provider}-proxy.example.com"]);
    }

    $resolver = new ApiKeyResolver;

    foreach ($providers as $provider) {
        expect($resolver->resolveBaseUrl($provider))->toBe("https://{$provider}-proxy.example.com");
    }
});

it('resolves base url for unknown provider using fallback chain', function () {
    config(['spectra.endpoint_urls.custom-provider' => 'https://custom.example.com']);

    $resolver = new ApiKeyResolver;

    expect($resolver->resolveBaseUrl('custom-provider'))->toBe('https://custom.example.com');
});

it('resolves api key for unknown provider from ai.php fallback', function () {
    config(['ai.providers.custom-provider.key' => 'custom-key']);

    $resolver = new ApiKeyResolver;

    expect($resolver->resolve('custom-provider'))->toBe('custom-key');
});
