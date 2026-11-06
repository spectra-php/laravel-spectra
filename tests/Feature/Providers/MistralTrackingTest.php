<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;

/**
 * A Mistral chat response recorded through Spectra::track(), where the
 * handler is picked by the response shape, and an embeddings request tracked
 * through the HTTP client, each with its model, tokens and cost.
 */
it('records a tracked mistral chat response with its model, tokens and cost', function () {
    $response = $this->loadMockResponse('mistral/chat.json');

    Spectra::track('mistral', 'mistral-large-latest', fn () => $response);

    $record = SpectraRequest::query()->sole();

    // Mistral Large 3 (in 50, out 150): (25×50 + 15×150) / 1M = 0.0035¢
    expect($record->provider)->toBe('mistral')
        ->and($record->model)->toBe('Mistral Large 3')
        ->and($record->model_type)->toBe('text')
        ->and($record->prompt_tokens)->toBe(25)
        ->and($record->completion_tokens)->toBe(15)
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.0035, 1e-9);
});

it('records a mistral embeddings request through the HTTP client with its prompt tokens and cost', function () {
    Http::fake(['api.mistral.ai/v1/embeddings' => Http::response($this->loadMockResponse('mistral/embeddings.json'))]);

    Http::post('https://api.mistral.ai/v1/embeddings', [
        'model' => 'mistral-embed',
        'input' => ['Embed this sentence.', 'As well as this one.'],
    ]);

    $record = SpectraRequest::query()->sole();

    // Mistral Embed standard: 15 prompt tokens × 10 cents per 1M = 0.00015¢; total_tokens equals prompt_tokens, so no completion tokens
    expect($record->provider)->toBe('mistral')
        ->and($record->model)->toBe('Mistral Embed')
        ->and($record->model_type)->toBe('embedding')
        ->and($record->prompt_tokens)->toBe(15)
        ->and($record->completion_tokens)->toBe(0)
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.00015, 1e-12);
});
