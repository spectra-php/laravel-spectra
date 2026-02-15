<?php

use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\Groq\Groq;
use Spectra\Providers\Groq\Handlers\ChatHandler;

function groqProvider(): Groq
{
    return new Groq;
}

it('returns groq as provider', function () {
    expect(groqProvider()->getProvider())->toBe('groq');
});

it('returns correct display name', function () {
    expect(app(\Spectra\Support\ProviderRegistry::class)->displayName('groq'))->toBe('Groq');
});

it('returns correct hosts', function () {
    expect(groqProvider()->getHosts())->toBe(['api.groq.com']);
});

it('resolves chat handler for groq endpoint', function () {
    $handler = groqProvider()->resolveHandler('/openai/v1/chat/completions');

    expect($handler)->toBeInstanceOf(ChatHandler::class);
});

it('chat handler returns text model type', function () {
    $handler = new ChatHandler;

    expect($handler->modelType())->toBe(ModelType::Text);
});

it('extracts usage from groq response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/groq/chat.json'), true);

    $handler = new ChatHandler;
    $metrics = $handler->extractMetrics([], $response);

    expect($metrics)->toBeInstanceOf(Metrics::class);
    expect($metrics->tokens)->toBeInstanceOf(TokenMetrics::class);
    expect($metrics->tokens->promptTokens)->toBe(25);
    expect($metrics->tokens->completionTokens)->toBe(15);
});

it('extracts model from groq response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/groq/chat.json'), true);

    $handler = new ChatHandler;

    expect($handler->extractModel($response))->toBe('llama-3.3-70b-versatile');
});

it('extracts response content from groq response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/groq/chat.json'), true);

    $handler = new ChatHandler;

    expect($handler->extractResponse($response))->toContain('Groq');
});

it('extracts finish reason from groq response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/groq/chat.json'), true);

    $handler = new ChatHandler;

    expect($handler->extractFinishReason($response))->toBe('stop');
});

it('matches groq response shape', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/groq/chat.json'), true);

    $handler = new ChatHandler;

    expect($handler->matchesResponse($response))->toBeTrue();
});

it('extracts metrics via provider', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../responses/groq/chat.json'), true);

    $metrics = groqProvider()->extractMetrics($response, '/openai/v1/chat/completions');

    expect($metrics->tokens->promptTokens)->toBe(25);
    expect($metrics->tokens->completionTokens)->toBe(15);
});
