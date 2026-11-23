<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Spectra\Models\SpectraRequest;

/**
 * Together AI: a chat completion sent to api.together.ai or api.together.xyz
 * is recorded under `together` with its tokens and a cost from the catalog.
 * Cached tokens are read from the top of `usage` or from its details.
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
