<?php

declare(strict_types=1);

use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;

/**
 * A Cohere v1 chat response recorded through Spectra::track(), where the
 * handler is picked by the response shape, with its model, tokens and cost.
 */
it('records a tracked cohere chat response with its model, tokens and cost', function () {
    $response = $this->loadMockResponse('cohere/chat.json');

    Spectra::track('cohere', 'command-r-plus', fn () => $response);

    $record = SpectraRequest::query()->sole();

    // Command R+ 04-2024 (in 300, out 1500): (12×300 + 18×1500) / 1M = 0.0306¢
    expect($record->provider)->toBe('cohere')
        ->and($record->model)->toBe('Command R+ 04-2024')
        ->and($record->model_type)->toBe('text')
        ->and($record->prompt_tokens)->toBe(12)
        ->and($record->completion_tokens)->toBe(18)
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.0306, 1e-9);
});
