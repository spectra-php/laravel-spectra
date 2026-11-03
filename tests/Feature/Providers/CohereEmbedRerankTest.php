<?php

declare(strict_types=1);

use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;

/**
 * Cohere embed and rerank responses recorded through Spectra::track(), where
 * the handler is picked by the response shape: embeddings bill their billed
 * tokens, rerank bills per search.
 */
it('records a tracked cohere embed response with its billed tokens and cost', function () {
    $response = $this->loadMockResponse('cohere/embed.json');

    Spectra::track('cohere', 'embed-english-v3.0', fn () => $response);

    $record = SpectraRequest::sole();

    // Embed v3 English: 5 billed tokens × 10 cents per 1M = 0.00005¢
    expect($record->provider)->toBe('cohere')
        ->and($record->model)->toBe('Embed v3 English')
        ->and($record->model_type)->toBe('embedding')
        ->and($record->prompt_tokens)->toBe(5)
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.00005, 1e-12);
});

it('records a tracked cohere rerank response with its per-search cost', function () {
    $response = $this->loadMockResponse('cohere/rerank.json');

    Spectra::track('cohere', 'rerank-v3.5', fn () => $response);

    $record = SpectraRequest::sole();

    // Rerank 3.5: 1 search unit × 0.2 cents
    expect($record->provider)->toBe('cohere')
        ->and($record->model)->toBe('Rerank 3.5')
        ->and($record->model_type)->toBe('text')
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.2, 1e-9);
});
