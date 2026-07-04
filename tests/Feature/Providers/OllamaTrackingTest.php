<?php

declare(strict_types=1);

use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;

it('records ollama chat response to database at zero cost', function () {
    $response = $this->loadMockResponse('ollama/chat.json');

    Spectra::track('ollama', 'llama3.2', function ($context) use ($response) {
        return $response;
    });

    $record = SpectraRequest::latest()->first();

    // Ollama runs locally, so requests are tracked but deliberately cost $0.
    expect($record)->not->toBeNull()
        ->and($record->provider)->toBe('ollama')
        ->and($record->status_code)->toBe(200)
        ->and($record->prompt_tokens)->toBe(26)
        ->and($record->completion_tokens)->toBe(12)
        ->and((float) $record->total_cost_in_cents)->toBe(0.0);
});
