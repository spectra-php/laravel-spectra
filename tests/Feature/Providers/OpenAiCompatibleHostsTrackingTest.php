<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Spectra\Models\SpectraRequest;

/**
 * Together AI, Fireworks AI, DeepInfra and Cerebras: chat completions sent
 * to each provider's own host and path are recorded under its slug, with the
 * tokens it reports and a cost from its catalog section, or from the cost
 * the provider reports itself (DeepInfra).
 */
it('records a together chat completion with its tokens and cost', function () {
    Http::fake(['api.together.ai/*' => Http::response($this->loadMockResponse('together/chat.json'))]);

    Http::post('https://api.together.ai/v1/chat/completions', ['model' => 'Qwen/Qwen3.5-9B', 'messages' => [['role' => 'user', 'content' => 'What is fun in New York?']]]);

    $record = SpectraRequest::query()->sole();

    // Qwen3.5 9B, cents per 1M (in 17, out 25): (24×17 + 26×25) / 1M = 0.001058
    expect($record->provider)->toBe('together')
        ->and($record->model)->toBe('Qwen3.5 9B')
        ->and($record->model_type)->toBe('text')
        ->and($record->prompt_tokens)->toBe(24)
        ->and($record->completion_tokens)->toBe(26)
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.001058, 1e-9);
});

it('reads together cached tokens from the top of usage or from its details', function (array $usage) {
    $response = [...$this->loadMockResponse('together/chat.json'), 'model' => 'deepseek-ai/DeepSeek-V4-Pro-0813', 'usage' => ['prompt_tokens' => 4000, 'completion_tokens' => 100, 'total_tokens' => 4100, ...$usage]];
    Http::fake(['api.together.xyz/*' => Http::response($response)]);

    Http::post('https://api.together.xyz/v1/chat/completions', ['model' => 'deepseek-ai/DeepSeek-V4-Pro-0813', 'messages' => []]);

    $record = SpectraRequest::query()->sole();

    // DeepSeek V4 Pro 0813, cents per 1M (in 132, cached 13, out 396):
    // (1,000×132 + 3,000×13 + 100×396) / 1M = 0.2106
    expect($record->provider)->toBe('together')
        ->and($record->cached_tokens)->toBe(3000)
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.2106, 1e-9);
})->with([
    'top of usage' => [['cached_tokens' => 3000]],
    'prompt_tokens_details' => [['prompt_tokens_details' => ['cached_tokens' => 3000]]],
]);

it('records a fireworks chat completion with its cached tokens and cost', function () {
    Http::fake(['api.fireworks.ai/*' => Http::response($this->loadMockResponse('fireworks/chat.json'))]);

    Http::post('https://api.fireworks.ai/inference/v1/chat/completions', ['model' => 'accounts/fireworks/models/deepseek-v4p1-flash', 'messages' => []]);

    $record = SpectraRequest::query()->sole();

    // DeepSeek V4.1 Flash, cents per 1M (in 30, cached 0.6, out 120):
    // (1,200×30 + 3,000×0.6 + 320×120) / 1M = 0.0762
    expect($record->provider)->toBe('fireworks')
        ->and($record->model)->toBe('DeepSeek V4.1 Flash')
        ->and($record->model_type)->toBe('text')
        ->and($record->prompt_tokens)->toBe(4200)
        ->and($record->cached_tokens)->toBe(3000)
        ->and($record->completion_tokens)->toBe(320)
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.0762, 1e-9);
});

it('records the cost deepinfra reports for a chat completion', function () {
    Http::fake(['api.deepinfra.com/*' => Http::response($this->loadMockResponse('deepinfra/chat.json'))]);

    Http::post('https://api.deepinfra.com/v1/openai/chat/completions', ['model' => 'deepseek-ai/DeepSeek-V4-Flash-0731', 'messages' => []]);

    $record = SpectraRequest::query()->sole();

    // usage.estimated_cost: $0.0000268 = 0.00268 cents
    expect($record->provider)->toBe('deepinfra')
        ->and($record->model)->toBe('DeepSeek V4 Flash 0731')
        ->and($record->model_type)->toBe('text')
        ->and($record->prompt_tokens)->toBe(15)
        ->and($record->completion_tokens)->toBe(16)
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.00268, 1e-9);
});

it('prices a deepinfra chat completion from the catalog when it reports no cost', function () {
    $response = $this->loadMockResponse('deepinfra/chat.json');
    $response['usage']['estimated_cost'] = null;
    Http::fake(['api.deepinfra.com/*' => Http::response($response)]);

    Http::post('https://api.deepinfra.com/v1/openai/chat/completions', ['model' => 'deepseek-ai/DeepSeek-V4-Flash-0731', 'messages' => []]);

    // DeepSeek V4 Flash 0731, cents per 1M (in 6, out 18): (15×6 + 16×18) / 1M = 0.000378
    expect((float) SpectraRequest::query()->sole()->total_cost_in_cents)->toEqualWithDelta(0.000378, 1e-9);
});

it('records a cerebras chat completion with its tokens and cost', function () {
    Http::fake(['api.cerebras.ai/*' => Http::response($this->loadMockResponse('cerebras/chat.json'))]);

    Http::post('https://api.cerebras.ai/v1/chat/completions', ['model' => 'qwen-3.8-27b', 'messages' => [['role' => 'user', 'content' => 'Hello!']]]);

    $record = SpectraRequest::query()->sole();

    // Qwen3.8 27B, cents per 1M (in 99, out 149): (69×99 + 45×149) / 1M = 0.013536
    expect($record->provider)->toBe('cerebras')
        ->and($record->model)->toBe('Qwen3.8 27B')
        ->and($record->model_type)->toBe('text')
        ->and($record->prompt_tokens)->toBe(69)
        ->and($record->completion_tokens)->toBe(45)
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.013536, 1e-9);
});

it('records a streamed deepinfra chat completion with the cost of its last chunk', function () {
    $chunk = fn (array $fields) => 'data: '.json_encode(['id' => 'chatcmpl-9f1c', 'object' => 'chat.completion.chunk', 'created' => 1794572500, 'model' => 'deepseek-ai/DeepSeek-V4-Flash-0731', ...$fields], JSON_THROW_ON_ERROR)."\n\n";
    $sse = $chunk(['choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Paris'], 'finish_reason' => null]]])
        .$chunk(['choices' => [['index' => 0, 'delta' => ['content' => ' is the capital.'], 'finish_reason' => 'stop']]])
        .$chunk(['choices' => [], 'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 5, 'total_tokens' => 17, 'estimated_cost' => 0.0000016]])
        ."data: [DONE]\n\n";
    Http::fake(['api.deepinfra.com/*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);

    Http::post('https://api.deepinfra.com/v1/openai/chat/completions', ['model' => 'deepseek-ai/DeepSeek-V4-Flash-0731', 'stream' => true, 'messages' => []])->body();

    $record = SpectraRequest::query()->sole();

    // usage.estimated_cost on the last chunk: $0.0000016 = 0.00016 cents
    expect($record->provider)->toBe('deepinfra')
        ->and($record->is_streaming)->toBeTrue()
        ->and($record->prompt_tokens)->toBe(12)
        ->and($record->completion_tokens)->toBe(5)
        ->and($record->finish_reason)->toBe('stop')
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.00016, 1e-9);
});
