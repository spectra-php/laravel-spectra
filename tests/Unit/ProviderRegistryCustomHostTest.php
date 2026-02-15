<?php

use Spectra\Support\ApiKeyResolver;
use Spectra\Support\ProviderRegistry;

it('registers custom host from endpoint url config', function () {
    config(['spectra.endpoint_urls.openai' => 'https://my-proxy.example.com/v1']);

    $resolver = new ApiKeyResolver;
    $registry = new ProviderRegistry(config('spectra.providers'), $resolver);

    expect($registry->detectProvider('my-proxy.example.com'))->toBe('openai');
});

it('still detects default host with custom host configured', function () {
    config(['spectra.endpoint_urls.openai' => 'https://my-proxy.example.com/v1']);

    $resolver = new ApiKeyResolver;
    $registry = new ProviderRegistry(config('spectra.providers'), $resolver);

    expect($registry->detectProvider('api.openai.com'))->toBe('openai');
    expect($registry->detectProvider('my-proxy.example.com'))->toBe('openai');
});

it('registers custom host from ai.php config', function () {
    config(['ai.providers.anthropic.url' => 'https://anthropic-gateway.example.com']);

    $resolver = new ApiKeyResolver;
    $registry = new ProviderRegistry(config('spectra.providers'), $resolver);

    expect($registry->detectProvider('anthropic-gateway.example.com'))->toBe('anthropic');
});

it('registers custom host with port', function () {
    config(['spectra.endpoint_urls.ollama' => 'http://remote-ollama:9999']);

    $resolver = new ApiKeyResolver;
    $registry = new ProviderRegistry(config('spectra.providers'), $resolver);

    expect($registry->detectProvider('remote-ollama:9999'))->toBe('ollama');
});

it('does not duplicate host when custom host matches existing', function () {
    config(['spectra.endpoint_urls.openai' => 'https://api.openai.com/v1']);

    $resolver = new ApiKeyResolver;
    $registry = new ProviderRegistry(config('spectra.providers'), $resolver);

    $providerConfig = $registry->all();
    $openaiHosts = $providerConfig['openai']['hosts'];

    // Should not have duplicates
    expect(count(array_unique($openaiHosts)))->toBe(count($openaiHosts));
});

it('works without api key resolver', function () {
    $registry = new ProviderRegistry(config('spectra.providers'));

    expect($registry->detectProvider('api.openai.com'))->toBe('openai');
    expect($registry->detectProvider('api.anthropic.com'))->toBe('anthropic');
});

it('detects proxy hosts for multiple providers', function () {
    config([
        'spectra.endpoint_urls.openai' => 'https://openai.my-proxy.com',
        'spectra.endpoint_urls.anthropic' => 'https://anthropic.my-proxy.com',
        'spectra.endpoint_urls.google' => 'https://google.my-proxy.com',
    ]);

    $resolver = new ApiKeyResolver;
    $registry = new ProviderRegistry(config('spectra.providers'), $resolver);

    expect($registry->detectProvider('openai.my-proxy.com'))->toBe('openai');
    expect($registry->detectProvider('anthropic.my-proxy.com'))->toBe('anthropic');
    expect($registry->detectProvider('google.my-proxy.com'))->toBe('google');
});

it('endpoints remain trackable with custom host', function () {
    config(['spectra.endpoint_urls.openai' => 'https://my-proxy.example.com']);

    $resolver = new ApiKeyResolver;
    $registry = new ProviderRegistry(config('spectra.providers'), $resolver);

    expect($registry->detectProvider('my-proxy.example.com'))->toBe('openai');
    expect($registry->isTrackableEndpoint('openai', '/v1/chat/completions'))->toBeTrue();
    expect($registry->isTrackableEndpoint('openai', '/v1/responses'))->toBeTrue();
});
