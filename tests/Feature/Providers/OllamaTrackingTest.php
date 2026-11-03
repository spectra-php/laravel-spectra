<?php

declare(strict_types=1);

use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;

/**
 * An Ollama chat response recorded through Spectra::track() with its tokens
 * and no cost, since local models are free.
 */
it('records a tracked ollama chat response with its tokens at zero cost', function () {
    $response = $this->loadMockResponse('ollama/chat.json');

    Spectra::track('ollama', 'llama3.2', fn () => $response);

    $record = SpectraRequest::sole();

    // Ollama runs locally, so requests are tracked but deliberately cost $0.
    expect($record->provider)->toBe('ollama')
        ->and($record->model)->toBe('llama3.2')
        ->and($record->model_type)->toBe('text')
        ->and($record->prompt_tokens)->toBe(26)
        ->and($record->completion_tokens)->toBe(12)
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.0, 1e-12);
});
