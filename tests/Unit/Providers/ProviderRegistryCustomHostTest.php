<?php

declare(strict_types=1);

use Spectra\Support\ApiKeyResolver;
use Spectra\Support\ProviderRegistry;

/**
 * Custom and proxy hosts from spectra or ai.php config are registered for
 * their provider next to the default hosts.
 */
it('registers custom host from endpoint url config', function () {
    config(['spectra.endpoint_urls.openai' => 'https://my-proxy.example.com/v1']);

    $registry = new ProviderRegistry(config('spectra.providers'), new ApiKeyResolver);

    expect($registry->detectProvider('my-proxy.example.com'))->toBe('openai');
});

it('registers custom host from ai.php config', function () {
    config(['ai.providers.anthropic.url' => 'https://anthropic-gateway.example.com']);

    $registry = new ProviderRegistry(config('spectra.providers'), new ApiKeyResolver);

    expect($registry->detectProvider('anthropic-gateway.example.com'))->toBe('anthropic');
});

it('registers custom host with port', function () {
    config(['spectra.endpoint_urls.ollama' => 'http://remote-ollama:9999']);

    $registry = new ProviderRegistry(config('spectra.providers'), new ApiKeyResolver);

    expect($registry->detectProvider('remote-ollama:9999'))->toBe('ollama');
});
