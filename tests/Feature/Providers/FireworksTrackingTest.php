<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Spectra\Models\SpectraRequest;

/**
 * Fireworks AI: a chat completion sent to api.fireworks.ai is recorded under
 * `fireworks` with its tokens, cached tokens and a cost from the catalog.
 */
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
