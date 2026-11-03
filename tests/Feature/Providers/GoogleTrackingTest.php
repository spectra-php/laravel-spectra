<?php

declare(strict_types=1);

use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;

/**
 * A Gemini response recorded through Spectra::track(), where the handler is
 * picked by the response shape, with its model, tokens and cost.
 */
it('records a tracked gemini response with its model, tokens and cost', function () {
    $response = $this->loadMockResponse('google/response.json');

    Spectra::track('google', 'gemini-2.0-flash', fn () => $response);

    $record = SpectraRequest::sole();

    // Gemini 2.0 Flash standard (in 10, out 40): (15×10 + 42×40) / 1M = 0.00183¢
    expect($record->provider)->toBe('google')
        ->and($record->model)->toBe('Gemini 2.0 Flash')
        ->and($record->model_type)->toBe('text')
        ->and($record->prompt_tokens)->toBe(15)
        ->and($record->completion_tokens)->toBe(42)
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.00183, 1e-9);
});
