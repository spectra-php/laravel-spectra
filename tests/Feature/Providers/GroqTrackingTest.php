<?php

declare(strict_types=1);

use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;

/**
 * A Groq chat response recorded through Spectra::track(), where the handler
 * is picked by the response shape, with its model, tokens and cost.
 */
it('records a tracked groq chat response with its model, tokens and cost', function () {
    $response = $this->loadMockResponse('groq/chat.json');

    Spectra::track('groq', 'llama-3.3-70b-versatile', fn () => $response);

    $record = SpectraRequest::sole();

    // Llama 3.3 70B Versatile (in 59, out 79): (25×59 + 15×79) / 1M = 0.00266¢
    expect($record->provider)->toBe('groq')
        ->and($record->model)->toBe('Llama 3.3 70B Versatile')
        ->and($record->model_type)->toBe('text')
        ->and($record->prompt_tokens)->toBe(25)
        ->and($record->completion_tokens)->toBe(15)
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.00266, 1e-9);
});
