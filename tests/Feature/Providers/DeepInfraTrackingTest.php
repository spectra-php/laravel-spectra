<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Spectra\Models\SpectraRequest;

/**
 * DeepInfra: a chat completion sent to api.deepinfra.com is recorded under
 * `deepinfra` at the cost the response reports (`usage.estimated_cost`),
 * streamed or not, and at the catalog price when it reports none.
 */
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
