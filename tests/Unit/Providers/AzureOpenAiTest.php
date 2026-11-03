<?php

declare(strict_types=1);

use Spectra\Providers\OpenAI\OpenAI;
use Spectra\Support\ApiKeyResolver;
use Spectra\Support\ProviderRegistry;

/**
 * Azure OpenAI hosts are detected as OpenAI and their paths normalized to
 * the OpenAI /v1 endpoints, without changing the OpenAI base URL.
 */
it('normalizes azure paths to the openai v1 endpoints', function (string $path, string $expected) {
    expect((new OpenAI)->normalizeEndpoint($path))->toBe($expected);
})->with([
    'deployment chat' => ['/openai/deployments/gpt-41-prod/chat/completions', '/v1/chat/completions'],
    'deployment embeddings' => ['/openai/deployments/embed/embeddings', '/v1/embeddings'],
    'deployment images' => ['/openai/deployments/image/images/generations', '/v1/images/generations'],
    'deployment transcription' => ['/openai/deployments/whisper/audio/transcriptions', '/v1/audio/transcriptions'],
    'v1 api' => ['/openai/v1/chat/completions', '/v1/chat/completions'],
    'v1 api responses by id' => ['/openai/v1/responses/resp_123', '/v1/responses/resp_123'],
    'responses preview' => ['/openai/responses', '/v1/responses'],
    'openai path untouched' => ['/v1/chat/completions', '/v1/chat/completions'],
    'unrelated path untouched' => ['/models/chat/completions', '/models/chat/completions'],
]);

it('detects azure resource hosts as openai', function (string $host) {
    $registry = new ProviderRegistry(config('spectra.providers'));

    expect($registry->detectProvider($host))->toBe('openai');
})->with([
    'contoso.openai.azure.com',
    'contoso.cognitiveservices.azure.com',
    'mspa-portal-resource.services.ai.azure.com',
]);

it('does not detect other azure hosts as openai', function (string $host) {
    $registry = new ProviderRegistry(config('spectra.providers'));

    expect($registry->detectProvider($host))->toBeNull();
})->with([
    'openai.azure.com',
    'contoso.blob.core.windows.net',
    'evil.openai.azure.com.example.com',
]);

it('detects the laravel-ai azure url host as openai without changing the openai base url', function () {
    config([
        'ai.providers.openai.url' => 'https://api.openai.com/v1',
        'ai.providers.azure.url' => 'https://ai-gateway.contoso.com',
    ]);

    $resolver = new ApiKeyResolver;
    $registry = new ProviderRegistry(config('spectra.providers'), $resolver);

    expect($registry->detectProvider('ai-gateway.contoso.com'))->toBe('openai')
        ->and($resolver->resolveBaseUrl('openai'))->toBe('https://api.openai.com/v1');
});

it('detects the spectra azure endpoint url host as openai without making it the openai base url', function () {
    config(['spectra.endpoint_urls.azure' => 'https://ai-gateway.contoso.com']);

    $resolver = new ApiKeyResolver;
    $registry = new ProviderRegistry(config('spectra.providers'), $resolver);

    expect($registry->detectProvider('ai-gateway.contoso.com'))->toBe('openai')
        ->and($resolver->resolveBaseUrl('openai'))->toBeNull();
});
