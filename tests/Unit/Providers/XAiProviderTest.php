<?php

use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\XAi\Handlers\ChatHandler;

it('returns xai as provider', function () {
    expect($this->xAiProvider()->getProvider())->toBe('xai');
});

it('returns correct display name', function () {
    expect(app(\Spectra\Support\ProviderRegistry::class)->displayName('xai'))->toBe('xAI');
});

it('returns correct hosts', function () {
    expect($this->xAiProvider()->getHosts())->toBe(['api.x.ai']);
});

it('resolves chat handler for xai endpoint', function () {
    $handler = $this->xAiProvider()->resolveHandler('/v1/chat/completions');

    expect($handler)->toBeInstanceOf(ChatHandler::class);
});

it('chat handler returns text model type', function () {
    $handler = new ChatHandler;

    expect($handler->modelType())->toBe(ModelType::Text);
});

it('extracts usage from xai response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/xai/chat.json'), true);

    $handler = new ChatHandler;
    $metrics = $handler->extractMetrics([], $response);

    expect($metrics)->toBeInstanceOf(Metrics::class)
        ->and($metrics->tokens)->toBeInstanceOf(TokenMetrics::class)
        ->and($metrics->tokens->promptTokens)->toBe(10)
        ->and($metrics->tokens->completionTokens)->toBe(12)
        ->and($metrics->tokens->cachedTokens)->toBe(5);
});

it('extracts model from xai response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/xai/chat.json'), true);

    $handler = new ChatHandler;

    expect($handler->extractModelFromResponse($response))->toBe('grok-2');
});

it('extracts response content from xai response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/xai/chat.json'), true);

    $handler = new ChatHandler;

    expect($handler->extractResponse($response))->toContain('Grok');
});

it('extracts finish reason from xai response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/xai/chat.json'), true);

    $handler = new ChatHandler;

    expect($handler->extractFinishReason($response))->toBe('stop');
});

it('matches xai response shape', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/xai/chat.json'), true);

    $handler = new ChatHandler;

    expect($handler->matchesResponse($response))->toBeTrue();
});

it('extracts metrics via provider', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/xai/chat.json'), true);

    $metrics = $this->xAiProvider()->extractMetrics($response, '/v1/chat/completions');

    expect($metrics->tokens->promptTokens)->toBe(10)
        ->and($metrics->tokens->completionTokens)->toBe(12)
        ->and($metrics->tokens->cachedTokens)->toBe(5);
});
