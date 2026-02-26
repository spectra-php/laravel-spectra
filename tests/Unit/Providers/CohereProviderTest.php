<?php

use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\Cohere\Handlers\ChatHandler;

it('returns cohere as provider', function () {
    expect($this->cohereProvider()->getProvider())->toBe('cohere');
});

it('returns correct display name', function () {
    expect(app(\Spectra\Support\ProviderRegistry::class)->displayName('cohere'))->toBe('Cohere');
});

it('returns correct hosts', function () {
    expect($this->cohereProvider()->getHosts())->toBe(['api.cohere.com']);
});

it('resolves chat handler for v2 chat endpoint', function () {
    $handler = $this->cohereProvider()->resolveHandler('/v2/chat');

    expect($handler)->toBeInstanceOf(ChatHandler::class);
});

it('chat handler returns text model type', function () {
    $handler = new ChatHandler;

    expect($handler->modelType())->toBe(ModelType::Text);
});

it('extracts usage from cohere response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/cohere/chat.json'), true);

    $handler = new ChatHandler;
    $metrics = $handler->extractMetrics([], $response);

    expect($metrics)->toBeInstanceOf(Metrics::class);
    expect($metrics->tokens)->toBeInstanceOf(TokenMetrics::class);
    expect($metrics->tokens->promptTokens)->toBe(12);
    expect($metrics->tokens->completionTokens)->toBe(18);
});

it('extracts model from cohere response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/cohere/chat.json'), true);

    $handler = new ChatHandler;

    expect($handler->extractModelFromResponse($response))->toBe('command-r-plus');
});

it('extracts response text from cohere response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/cohere/chat.json'), true);

    $handler = new ChatHandler;
    $content = $handler->extractResponse($response);

    expect($content)->toContain('Command R');
});

it('extracts finish reason from cohere response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/cohere/chat.json'), true);

    $handler = new ChatHandler;

    expect($handler->extractFinishReason($response))->toBe('COMPLETE');
});

it('matches cohere response shape', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/cohere/chat.json'), true);

    $handler = new ChatHandler;

    expect($handler->matchesResponse($response))->toBeTrue();
});

it('does not match non-cohere response', function () {
    $handler = new ChatHandler;

    expect($handler->matchesResponse(['choices' => []]))->toBeFalse();
});

it('extracts metrics via provider', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/cohere/chat.json'), true);

    $metrics = $this->cohereProvider()->extractMetrics($response, '/v2/chat');

    expect($metrics->tokens->promptTokens)->toBe(12);
    expect($metrics->tokens->completionTokens)->toBe(18);
});
