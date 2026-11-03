<?php

declare(strict_types=1);

use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\Groq\Handlers\ChatHandler;
use Spectra\Providers\Groq\Handlers\ResponsesHandler;
use Spectra\Providers\Groq\Handlers\SpeechHandler;
use Spectra\Providers\Groq\Handlers\TranscriptionHandler;
use Spectra\Support\ProviderRegistry;

/**
 * The Groq provider: its hosts, handler resolution and what the chat handler
 * extracts from a response.
 */
it('returns groq as provider', function () {
    expect($this->groqProvider()->getProvider())->toBe('groq');
});

it('returns correct display name', function () {
    expect(app(ProviderRegistry::class)->displayName('groq'))->toBe('Groq');
});

it('returns correct hosts', function () {
    expect($this->groqProvider()->getHosts())->toBe(['api.groq.com']);
});

it('resolves chat handler for groq endpoint', function () {
    $handler = $this->groqProvider()->resolveHandler('/openai/v1/chat/completions');

    expect($handler)->toBeInstanceOf(ChatHandler::class);
});

it('resolves a handler for every tracked endpoint', function (string $endpoint, string $handler) {
    expect($this->groqProvider()->resolveHandler($endpoint))->toBeInstanceOf($handler);
})->with([
    ['/openai/v1/chat/completions', ChatHandler::class],
    ['/openai/v1/responses', ResponsesHandler::class],
    ['/openai/v1/audio/transcriptions', TranscriptionHandler::class],
    ['/openai/v1/audio/translations', TranscriptionHandler::class],
    ['/openai/v1/audio/speech', SpeechHandler::class],
]);

it('uses the x_groq id when the response has no id', function () {
    expect($this->groqProvider()->extractResponseId(['text' => 'hi', 'x_groq' => ['id' => 'req_1']]))->toBe('req_1');
});

it('chat handler returns text model type', function () {
    $handler = new ChatHandler;

    expect($handler->modelType())->toBe(ModelType::Text);
});

it('extracts usage from groq response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/groq/chat.json'), true);

    $handler = new ChatHandler;
    $metrics = $handler->extractMetrics([], $response);

    expect($metrics)->toBeInstanceOf(Metrics::class);
    expect($metrics->tokens)->toBeInstanceOf(TokenMetrics::class);
    expect($metrics->tokens->promptTokens)->toBe(25);
    expect($metrics->tokens->completionTokens)->toBe(15);
});

it('extracts model from groq response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/groq/chat.json'), true);

    $handler = new ChatHandler;

    expect($handler->extractModelFromResponse($response))->toBe('llama-3.3-70b-versatile');
});

it('extracts response content from groq response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/groq/chat.json'), true);

    $handler = new ChatHandler;

    expect($handler->extractResponse($response))->toContain('Groq');
});

it('extracts finish reason from groq response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/groq/chat.json'), true);

    $handler = new ChatHandler;

    expect($handler->extractFinishReason($response))->toBe('stop');
});

it('matches groq response shape', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/groq/chat.json'), true);

    $handler = new ChatHandler;

    expect($handler->matchesResponse($response))->toBeTrue();
});

it('extracts metrics via provider', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/groq/chat.json'), true);

    $metrics = $this->groqProvider()->extractMetrics($response, '/openai/v1/chat/completions');

    expect($metrics->tokens->promptTokens)->toBe(25);
    expect($metrics->tokens->completionTokens)->toBe(15);
});
