<?php

declare(strict_types=1);

use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;

/**
 * A Mistral chat response recorded through Spectra::track(), where the
 * handler is picked by the response shape, with its model, tokens and cost.
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
