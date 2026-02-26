<?php

use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\Mistral\Handlers\ChatHandler;

it('returns mistral as provider', function () {
    expect($this->mistralProvider()->getProvider())->toBe('mistral');
});

it('returns correct display name', function () {
    expect(app(\Spectra\Support\ProviderRegistry::class)->displayName('mistral'))->toBe('Mistral');
});

it('returns correct hosts', function () {
    expect($this->mistralProvider()->getHosts())->toBe(['api.mistral.ai', 'codestral.mistral.ai']);
});

it('resolves chat handler for mistral endpoint', function () {
    $handler = $this->mistralProvider()->resolveHandler('/v1/chat/completions');

    expect($handler)->toBeInstanceOf(ChatHandler::class);
});

it('chat handler returns text model type', function () {
    $handler = new ChatHandler;

    expect($handler->modelType())->toBe(ModelType::Text);
});

it('extracts usage from mistral response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/mistral/chat.json'), true);

    $handler = new ChatHandler;
    $metrics = $handler->extractMetrics([], $response);

    expect($metrics)->toBeInstanceOf(Metrics::class);
    expect($metrics->tokens)->toBeInstanceOf(TokenMetrics::class);
    expect($metrics->tokens->promptTokens)->toBe(25);
    expect($metrics->tokens->completionTokens)->toBe(15);
});

it('extracts model from mistral response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/mistral/chat.json'), true);

    $handler = new ChatHandler;

    expect($handler->extractModelFromResponse($response))->toBe('mistral-large-latest');
});

it('extracts response content from mistral response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/mistral/chat.json'), true);

    $handler = new ChatHandler;

    expect($handler->extractResponse($response))->toContain('Mistral');
});

it('extracts finish reason from mistral response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/mistral/chat.json'), true);

    $handler = new ChatHandler;

    expect($handler->extractFinishReason($response))->toBe('stop');
});

it('matches mistral response shape', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/mistral/chat.json'), true);

    $handler = new ChatHandler;

    expect($handler->matchesResponse($response))->toBeTrue();
});

it('extracts metrics via provider', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/mistral/chat.json'), true);

    $metrics = $this->mistralProvider()->extractMetrics($response, '/v1/chat/completions');

    expect($metrics->tokens->promptTokens)->toBe(25);
    expect($metrics->tokens->completionTokens)->toBe(15);
});
