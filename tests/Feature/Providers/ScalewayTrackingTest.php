<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;
use Spectra\Spectra as SpectraManager;

/**
 * Scaleway requests through the HTTP client: chat, rerank and streamed calls
 * are recorded, and euro prices are converted with the configured exchange
 * rate.
 */
it('records scaleway chat response to database', function () {
    $response = $this->loadMockResponse('scaleway/chat.json');

    Spectra::track('scaleway', 'llama-3.3-70b-instruct', function ($context) use ($response) {
        return $response;
    });

    $record = SpectraRequest::latest()->first();

    expect($record)->not->toBeNull()
        ->and($record->provider)->toBe('scaleway')
        ->and($record->status_code)->toBe(200)
        ->and($record->prompt_tokens)->toBe(30)
        ->and($record->completion_tokens)->toBe(18)
        ->and($record->total_cost_in_cents)->toBeGreaterThan(0);
});

it('records scaleway rerank requests through the parametric endpoint', function () {
    $manager = app(SpectraManager::class);

    $context = $manager->startRequest('scaleway', 'rerank-model', [
        'endpoint' => '/my-project-id/v1/rerank',
        'request_data' => ['model' => 'rerank-model'],
    ]);

    $manager->recordSuccess($context, $this->loadMockResponse('scaleway/rerank.json'));

    $record = SpectraRequest::latest()->first();

    expect($record)->not->toBeNull()
        ->and($record->provider)->toBe('scaleway')
        ->and($record->prompt_tokens)->toBe(64);
});

it('tracks the default-project endpoints without a project id', function (string $path, array $body, string $fixture) {
    Http::fake(['api.scaleway.ai/*' => Http::response($this->loadMockResponse($fixture))]);

    Http::post("https://api.scaleway.ai{$path}", $body);

    expect(SpectraRequest::sole()->provider)->toBe('scaleway')
        ->and(SpectraRequest::sole()->endpoint)->toBe($path);
})->with([
    'chat' => ['/v1/chat/completions', ['model' => 'llama-3.3-70b-instruct', 'messages' => []], 'scaleway/chat.json'],
    'responses' => ['/v1/responses', ['model' => 'llama-3.3-70b-instruct', 'input' => 'Bonjour'], 'scaleway/responses.json'],
    'embeddings' => ['/v1/embeddings', ['model' => 'bge-multilingual-gemma2', 'input' => 'Bonjour'], 'scaleway/embedding.json'],
    'rerank' => ['/v1/rerank', ['model' => 'qwen3-embedding-8b', 'query' => 'q', 'documents' => ['a']], 'scaleway/rerank-results.json'],
]);

it('converts euro prices with the configured exchange rate', function () {
    config(['spectra.costs.exchange_rates.EUR' => 1.17]);

    Http::fake(['api.scaleway.ai/*' => Http::response($this->loadMockResponse('scaleway/chat-cached.json'))]);

    Http::post('https://api.scaleway.ai/a1b2c3d4-e5f6-7890-abcd-ef1234567890/v1/chat/completions', [
        'model' => 'deepseek-v4.1-flash',
        'messages' => [['role' => 'user', 'content' => 'Résume ce document.']],
    ]);

    $record = SpectraRequest::sole();

    // deepseek-v4.1-flash, EUR cents per 1M: 4,000 uncached × 60 + 6,000 cached × 12
    // + 1,500 output × 180 = 0.582 EUR cents → × 1.17 = 0.68094 USD cents
    expect($record->model)->toBe('DeepSeek V4.1 Flash')
        ->and($record->prompt_tokens)->toBe(10000)
        ->and($record->cached_tokens)->toBe(6000)
        ->and($record->completion_tokens)->toBe(1500)
        ->and($record->reasoning_tokens)->toBe(500)
        ->and($record->currency)->toBe('USD')
        ->and($record->total_cost_in_cents)->toEqualWithDelta(0.68094, 0.000001);
});

it('counts euro prices one to one when no exchange rate is set', function () {
    Http::fake(['api.scaleway.ai/*' => Http::response($this->loadMockResponse('scaleway/chat-cached.json'))]);

    Http::post('https://api.scaleway.ai/v1/chat/completions', ['model' => 'deepseek-v4.1-flash', 'messages' => []]);

    // 0.582 EUR cents, unconverted
    expect(SpectraRequest::sole()->total_cost_in_cents)->toEqualWithDelta(0.582, 0.000001);
});

it('records a streamed chat completion with cached tokens', function (string $path) {
    $sse = file_get_contents(__DIR__.'/../../responses/scaleway/chat-stream.txt');

    Http::fake(['api.scaleway.ai/*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);

    $response = Http::post("https://api.scaleway.ai{$path}", [
        'model' => 'qwen3.8-27b',
        'stream' => true,
        'stream_options' => ['include_usage' => true],
        'messages' => [['role' => 'user', 'content' => 'Capital of France?']],
    ]);

    expect($response->body())->toBe($sse);

    $record = SpectraRequest::sole();

    // qwen3.8-27b, EUR cents per 1M: (1,000 × 60 + 1,000 cached × 12 + 100 × 330) / 1M = 0.105
    expect($record->is_streaming)->toBeTrue()
        ->and($record->endpoint)->toBe($path)
        ->and($record->model)->toBe('Qwen 3.8 27B')
        ->and($record->prompt_tokens)->toBe(2000)
        ->and($record->cached_tokens)->toBe(1000)
        ->and($record->completion_tokens)->toBe(100)
        ->and($record->finish_reason)->toBe('stop')
        ->and($record->total_cost_in_cents)->toEqualWithDelta(0.105, 0.000001);
})->with([
    'default address' => '/v1/chat/completions',
    'project address' => '/a1b2c3d4-e5f6-7890-abcd-ef1234567890/v1/chat/completions',
]);

it('records a streamed responses api call from its completed event', function () {
    $sse = file_get_contents(__DIR__.'/../../responses/scaleway/responses-stream.txt');

    Http::fake(['api.scaleway.ai/*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);

    Http::post('https://api.scaleway.ai/v1/responses', ['model' => 'gpt-oss-120b', 'stream' => true, 'input' => 'Bonjour'])->body();

    $record = SpectraRequest::sole();

    // gpt-oss-120b, EUR cents per 1M: (400 × 15 + 120 × 60) / 1M = 0.0132
    expect($record->prompt_tokens)->toBe(400)
        ->and($record->completion_tokens)->toBe(120)
        ->and($record->reasoning_tokens)->toBe(100)
        ->and($record->response_id)->toBe('resp_scw_5b8e1f')
        ->and($record->finish_reason)->toBe('completed')
        ->and($record->total_cost_in_cents)->toEqualWithDelta(0.0132, 0.000001);
});

it('prices rerank calls as embedding tokens', function () {
    Http::fake(['api.scaleway.ai/*' => Http::response($this->loadMockResponse('scaleway/rerank-results.json'))]);

    Http::post('https://api.scaleway.ai/v1/rerank', ['model' => 'qwen3-embedding-8b', 'query' => 'Biggest ocean?', 'documents' => ['a', 'b', 'c']]);

    $record = SpectraRequest::sole();

    // qwen3-embedding-8b: 99 tokens × 10 EUR cents per 1M
    expect($record->prompt_tokens)->toBe(99)
        ->and($record->response_id)->toBe('rerank-a89e6d7b8b97492ea81569c65fbfff49')
        ->and($record->total_cost_in_cents)->toEqualWithDelta(0.00099, 0.000001);
});
