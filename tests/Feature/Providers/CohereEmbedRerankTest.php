<?php

declare(strict_types=1);

use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;

it('records cohere embed response with billed tokens and cost', function () {
    $response = $this->loadMockResponse('cohere/embed.json');

    Spectra::track('cohere', 'embed-english-v3.0', function ($context) use ($response) {
        return $response;
    });

    $record = SpectraRequest::latest()->first();

    expect($record)->not->toBeNull()
        ->and($record->provider)->toBe('cohere')
        ->and($record->model_type)->toBe('embedding')
        ->and($record->prompt_tokens)->toBe(5)
        ->and($record->total_cost_in_cents)->toBeGreaterThan(0);
});

it('records cohere rerank response with per-search cost', function () {
    $response = $this->loadMockResponse('cohere/rerank.json');

    Spectra::track('cohere', 'rerank-v3.5', function ($context) use ($response) {
        return $response;
    });

    $record = SpectraRequest::latest()->first();

    // rerank-v3.5 bills 0.2 cents per search unit
    expect($record)->not->toBeNull()
        ->and($record->provider)->toBe('cohere')
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.2, 0.0001);
});
