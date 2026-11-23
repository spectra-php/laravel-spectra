<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Spectra\Models\SpectraRequest;

/**
 * Cerebras: a chat completion sent to api.cerebras.ai is recorded under
 * `cerebras` with its tokens and a cost from the catalog.
 */
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
