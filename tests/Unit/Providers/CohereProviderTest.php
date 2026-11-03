<?php

declare(strict_types=1);

use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\Cohere\Handlers\ChatHandler;
use Spectra\Providers\Cohere\Handlers\ParseHandler;
use Spectra\Support\ProviderRegistry;

/**
 * The Cohere provider: its hosts, handler resolution and what the chat
 * handler extracts from a response.
 */
it('returns cohere as provider', function () {
    expect($this->cohereProvider()->getProvider())->toBe('cohere');
});

it('returns correct display name', function () {
    expect(app(ProviderRegistry::class)->displayName('cohere'))->toBe('Cohere');
});

it('returns both API hosts', function () {
    expect($this->cohereProvider()->getHosts())->toBe(['api.cohere.com', 'api.cohere.ai']);
});

it('resolves the parse handler', function () {
    expect($this->cohereProvider()->resolveHandler('/v2/parse'))->toBeInstanceOf(ParseHandler::class);
});

it('streams chat through the cohere stream handler', function () {
    $stream = (new ChatHandler)->streamingHandler();

    expect($stream->text(['type' => 'content-delta', 'delta' => ['message' => ['content' => ['text' => 'Hi']]]]))->toBe('Hi')
        ->and($stream->text(['type' => 'content-delta', 'delta' => ['message' => ['content' => ['thinking' => 'hmm']]]]))->toBeNull()
        ->and($stream->usage(['type' => 'message-end', 'delta' => ['usage' => ['billed_units' => ['input_tokens' => 5, 'output_tokens' => 26]]]], [])['completion_tokens'])->toBe(26)
        ->and($stream->model(['type' => 'message-start', 'id' => 'abc']))->toBe(['id' => 'abc']);
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
