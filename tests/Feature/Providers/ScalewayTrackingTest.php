<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;

/**
 * Scaleway requests through Spectra::track() and the HTTP client: chat,
 * embeddings, rerank and streamed calls on the default-project and project
 * addresses, with euro prices converted at the configured exchange rate.
 */
it('records a tracked scaleway chat response with its model, tokens and cost', function () {
    $response = $this->loadMockResponse('scaleway/chat.json');

    Spectra::track('scaleway', 'llama-3.3-70b-instruct', fn () => $response);

    $record = SpectraRequest::query()->sole();

    // Llama 3.3 70B Instruct, EUR cents per 1M (in 90, out 90), no exchange rate set:
    // (30×90 + 18×90) / 1M = 0.00432
    expect($record->provider)->toBe('scaleway')
        ->and($record->model)->toBe('Llama 3.3 70B Instruct')
        ->and($record->model_type)->toBe('text')
        ->and($record->prompt_tokens)->toBe(30)
        ->and($record->completion_tokens)->toBe(18)
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.00432, 1e-9);
});

it('records an embedding sent to the default-project address', function () {
    Http::fake(['api.scaleway.ai/*' => Http::response($this->loadMockResponse('scaleway/embedding.json'))]);

    Http::post('https://api.scaleway.ai/v1/embeddings', ['model' => 'bge-multilingual-gemma2', 'input' => 'Bonjour']);

    $record = SpectraRequest::query()->sole();

    // bge-multilingual-gemma2: 8 tokens × 10 EUR cents per 1M = 0.00008
    expect($record->provider)->toBe('scaleway')
        ->and($record->endpoint)->toBe('/v1/embeddings')
        ->and($record->model)->toBe('BGE Multilingual Gemma 2')
        ->and($record->model_type)->toBe('embedding')
        ->and($record->prompt_tokens)->toBe(8)
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.00008, 1e-12);
});

it('converts euro prices with the configured exchange rate', function () {
    config(['spectra.costs.exchange_rates.EUR' => 1.17]);

    Http::fake(['api.scaleway.ai/*' => Http::response($this->loadMockResponse('scaleway/chat-cached.json'))]);

    Http::post('https://api.scaleway.ai/a1b2c3d4-e5f6-7890-abcd-ef1234567890/v1/chat/completions', [
        'model' => 'deepseek-v4.1-flash',
        'messages' => [['role' => 'user', 'content' => 'Résume ce document.']],
    ]);

    $record = SpectraRequest::query()->sole();

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
    expect(SpectraRequest::query()->sole()->total_cost_in_cents)->toEqualWithDelta(0.582, 0.000001);
});

it('records a streamed chat completion with cached tokens', function () {
    $sse = File::get(__DIR__.'/../../responses/scaleway/chat-stream.txt');

    Http::fake(['api.scaleway.ai/*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);

    $response = Http::post('https://api.scaleway.ai/v1/chat/completions', [
        'model' => 'qwen3.8-27b',
        'stream' => true,
        'stream_options' => ['include_usage' => true],
        'messages' => [['role' => 'user', 'content' => 'Capital of France?']],
    ]);

    expect($response->body())->toBe($sse);

    $record = SpectraRequest::query()->sole();

    // qwen3.8-27b, EUR cents per 1M: (1,000 × 60 + 1,000 cached × 12 + 100 × 330) / 1M = 0.105
    expect($record->is_streaming)->toBeTrue()
        ->and($record->model)->toBe('Qwen 3.8 27B')
        ->and($record->prompt_tokens)->toBe(2000)
        ->and($record->cached_tokens)->toBe(1000)
        ->and($record->completion_tokens)->toBe(100)
        ->and($record->finish_reason)->toBe('stop')
        ->and($record->total_cost_in_cents)->toEqualWithDelta(0.105, 0.000001);
});

it('records a streamed responses api call from its completed event', function () {
    $sse = File::get(__DIR__.'/../../responses/scaleway/responses-stream.txt');

    Http::fake(['api.scaleway.ai/*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);

    Http::post('https://api.scaleway.ai/v1/responses', ['model' => 'gpt-oss-120b', 'stream' => true, 'input' => 'Bonjour'])->body();

    $record = SpectraRequest::query()->sole();

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

    $record = SpectraRequest::query()->sole();

    // qwen3-embedding-8b: 99 tokens × 10 EUR cents per 1M
    expect($record->prompt_tokens)->toBe(99)
        ->and($record->response_id)->toBe('rerank-a89e6d7b8b97492ea81569c65fbfff49')
        ->and($record->total_cost_in_cents)->toEqualWithDelta(0.00099, 0.000001);
});
