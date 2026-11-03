<?php

declare(strict_types=1);

use Spectra\Support\ApiKeyResolver;

/**
 * ApiKeyResolver finds each provider's API key, base URL and hosts in
 * spectra config first and in laravel-ai's ai.php config after.
 */
it('falls back to the ai.php api key when spectra has none', function () {
    config(['ai.providers.openai.key' => 'sk-ai-key']);

    expect((new ApiKeyResolver)->resolve('openai'))->toBe('sk-ai-key');
});

it('prioritizes spectra config over ai.php for api keys', function () {
    config([
        'spectra.api_keys.openai' => 'sk-spectra-key',
        'ai.providers.openai.key' => 'sk-ai-key',
    ]);

    expect((new ApiKeyResolver)->resolve('openai'))->toBe('sk-spectra-key');
});

it('returns null when no api key is configured', function () {
    expect((new ApiKeyResolver)->resolve('openai'))->toBeNull();
});

it('resolves an api key for an unknown provider from ai.php', function () {
    config(['ai.providers.custom-provider.key' => 'custom-key']);

    expect((new ApiKeyResolver)->resolve('custom-provider'))->toBe('custom-key');
});

it('falls back to the ai.php base url when spectra has none', function () {
    config(['ai.providers.openai.url' => 'https://ai-proxy.example.com']);

    expect((new ApiKeyResolver)->resolveBaseUrl('openai'))->toBe('https://ai-proxy.example.com');
});

it('prioritizes spectra endpoint_urls over ai.php for base url', function () {
    config([
        'spectra.endpoint_urls.openai' => 'https://spectra-proxy.example.com',
        'ai.providers.openai.url' => 'https://ai-proxy.example.com',
    ]);

    expect((new ApiKeyResolver)->resolveBaseUrl('openai'))->toBe('https://spectra-proxy.example.com');
});

it('returns null when no base url is configured', function () {
    $resolver = new ApiKeyResolver;

    expect($resolver->resolveBaseUrl('openai'))->toBeNull()
        ->and($resolver->resolveBaseUrl('anthropic'))->toBeNull();
});

it('resolves a base url for an unknown provider from spectra endpoint_urls', function () {
    config(['spectra.endpoint_urls.custom-provider' => 'https://custom.example.com']);

    expect((new ApiKeyResolver)->resolveBaseUrl('custom-provider'))->toBe('https://custom.example.com');
});

it('resolves the host from the base url', function () {
    config(['spectra.endpoint_urls.openai' => 'https://my-proxy.example.com/v1']);

    expect((new ApiKeyResolver)->resolveHost('openai'))->toBe('my-proxy.example.com');
});

it('keeps the port in the host resolved from the base url', function () {
    config(['spectra.endpoint_urls.ollama' => 'http://custom-ollama:8080']);

    expect((new ApiKeyResolver)->resolveHost('ollama'))->toBe('custom-ollama:8080');
});

it('resolves every custom host for a provider', function () {
    config([
        'spectra.endpoint_urls.openai' => 'https://my-proxy.example.com/v1',
        'ai.providers.azure.url' => 'https://contoso.openai.azure.com',
    ]);

    $resolver = new ApiKeyResolver;

    expect($resolver->resolveHosts('openai'))->toBe(['my-proxy.example.com', 'contoso.openai.azure.com'])
        ->and($resolver->resolveHosts('anthropic'))->toBe([]);
});

it('does not repeat a host configured twice', function () {
    config([
        'spectra.endpoint_urls.openai' => 'https://contoso.openai.azure.com/openai/v1',
        'ai.providers.azure.url' => 'https://contoso.openai.azure.com',
    ]);

    expect((new ApiKeyResolver)->resolveHosts('openai'))->toBe(['contoso.openai.azure.com']);
});
