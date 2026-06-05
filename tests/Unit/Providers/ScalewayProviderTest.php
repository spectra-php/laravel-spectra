<?php

use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\Scaleway\Handlers\ChatHandler;
use Spectra\Providers\Scaleway\Handlers\EmbeddingHandler;
use Spectra\Providers\Scaleway\Handlers\RerankHandler;
use Spectra\Providers\Scaleway\Handlers\TranscriptionHandler;
use Spectra\Support\ProviderRegistry;

it('returns scaleway as provider', function () {
    expect($this->scalewayProvider()->getProvider())->toBe('scaleway');
});

it('returns correct display name', function () {
    expect(app(ProviderRegistry::class)->displayName('scaleway'))->toBe('Scaleway');
});

it('returns correct hosts', function () {
    expect($this->scalewayProvider()->getHosts())->toBe(['api.scaleway.ai']);
});

it('resolves chat handler for scaleway endpoint', function () {
    $handler = $this->scalewayProvider()->resolveHandler('/proj-abc-123/v1/chat/completions');

    expect($handler)->toBeInstanceOf(ChatHandler::class);
});

it('resolves embedding handler for scaleway endpoint', function () {
    $handler = $this->scalewayProvider()->resolveHandler('/proj-abc-123/v1/embeddings');

    expect($handler)->toBeInstanceOf(EmbeddingHandler::class);
});

it('resolves transcription handler for scaleway endpoint', function () {
    $handler = $this->scalewayProvider()->resolveHandler('/proj-abc-123/v1/audio/transcriptions');

    expect($handler)->toBeInstanceOf(TranscriptionHandler::class);
});

it('resolves rerank handler for scaleway endpoint', function () {
    $handler = $this->scalewayProvider()->resolveHandler('/proj-abc-123/v1/rerank');

    expect($handler)->toBeInstanceOf(RerankHandler::class);
});

it('resolves chat handler for scaleway responses endpoint', function () {
    $handler = $this->scalewayProvider()->resolveHandler('/proj-abc-123/v1/responses');

    expect($handler)->toBeInstanceOf(ChatHandler::class);
});

it('chat handler returns text model type', function () {
    $handler = new ChatHandler;

    expect($handler->modelType())->toBe(ModelType::Text);
});

it('embedding handler returns embedding model type', function () {
    $handler = new EmbeddingHandler;

    expect($handler->modelType())->toBe(ModelType::Embedding);
});

it('transcription handler returns stt model type', function () {
    $handler = new TranscriptionHandler;

    expect($handler->modelType())->toBe(ModelType::Stt);
});

it('extracts usage from scaleway response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/scaleway/chat.json'), true);

    $handler = new ChatHandler;
    $metrics = $handler->extractMetrics([], $response);

    expect($metrics)->toBeInstanceOf(Metrics::class);
    expect($metrics->tokens)->toBeInstanceOf(TokenMetrics::class);
    expect($metrics->tokens->promptTokens)->toBe(30);
    expect($metrics->tokens->completionTokens)->toBe(18);
});

it('extracts model from scaleway response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/scaleway/chat.json'), true);

    $handler = new ChatHandler;

    expect($handler->extractModelFromResponse($response))->toBe('llama-3.3-70b-instruct');
});

it('extracts response content from scaleway response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/scaleway/chat.json'), true);

    $handler = new ChatHandler;

    expect($handler->extractResponse($response))->toContain('Scaleway');
});

it('extracts finish reason from scaleway response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/scaleway/chat.json'), true);

    $handler = new ChatHandler;

    expect($handler->extractFinishReason($response))->toBe('stop');
});

it('matches scaleway response shape', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/scaleway/chat.json'), true);

    $handler = new ChatHandler;

    expect($handler->matchesResponse($response))->toBeTrue();
});

it('extracts usage from scaleway embedding response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/scaleway/embedding.json'), true);

    $handler = new EmbeddingHandler;
    $metrics = $handler->extractMetrics([], $response);

    expect($metrics->tokens->promptTokens)->toBe(8);
    expect($metrics->tokens->completionTokens)->toBe(0);
});

it('matches scaleway embedding response shape', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/scaleway/embedding.json'), true);

    $handler = new EmbeddingHandler;

    expect($handler->matchesResponse($response))->toBeTrue();
});

it('extracts duration from scaleway transcription response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/scaleway/transcription.json'), true);

    $handler = new TranscriptionHandler;
    $metrics = $handler->extractMetrics([], $response);

    expect($metrics->audio)->not->toBeNull();
    expect($metrics->audio->durationSeconds)->toBe(4.2);
});

it('extracts metrics via provider', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/scaleway/chat.json'), true);

    $metrics = $this->scalewayProvider()->extractMetrics($response, '/proj-abc-123/v1/chat/completions');

    expect($metrics->tokens->promptTokens)->toBe(30);
    expect($metrics->tokens->completionTokens)->toBe(18);
});

it('extracts usage from scaleway responses api', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/scaleway/responses.json'), true);

    $handler = new ChatHandler;
    $metrics = $handler->extractMetrics([], $response);

    expect($metrics->tokens->promptTokens)->toBe(22);
    expect($metrics->tokens->completionTokens)->toBe(12);
});

it('extracts response content from scaleway responses api', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/scaleway/responses.json'), true);

    $handler = new ChatHandler;

    expect($handler->extractResponse($response))->toContain('Scaleway Responses API');
});

it('extracts finish reason from scaleway responses api', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/scaleway/responses.json'), true);

    $handler = new ChatHandler;

    expect($handler->extractFinishReason($response))->toBe('completed');
});

it('matches scaleway responses api shape', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/scaleway/responses.json'), true);

    $handler = new ChatHandler;

    expect($handler->matchesResponse($response))->toBeTrue();
});

it('extracts usage from scaleway rerank response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/scaleway/rerank.json'), true);

    $handler = new RerankHandler;
    $metrics = $handler->extractMetrics([], $response);

    expect($metrics->tokens->promptTokens)->toBe(64);
    expect($metrics->tokens->completionTokens)->toBe(0);
});

it('extracts response content from scaleway rerank response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/scaleway/rerank.json'), true);

    $handler = new RerankHandler;

    expect($handler->extractResponse($response))->toBe('[rerank: 3 results]');
});

it('extracts model from scaleway rerank response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/scaleway/rerank.json'), true);

    $handler = new RerankHandler;

    expect($handler->extractModelFromResponse($response))->toBe('rerank-model');
});
