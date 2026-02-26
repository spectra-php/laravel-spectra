<?php

use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;

it('records groq chat response to database', function () {
    $response = $this->loadMockResponse('groq/chat.json');

    Spectra::track('groq', 'llama-3.3-70b-versatile', function ($context) use ($response) {
        return $response;
    });

    $record = SpectraRequest::latest()->first();

    expect($record)->not->toBeNull()
        ->and($record->provider)->toBe('groq')
        ->and($record->model)->toBe('Llama 3.3 70B Versatile')
        ->and($record->status_code)->toBe(200)
        ->and($record->prompt_tokens)->toBe(25)
        ->and($record->completion_tokens)->toBe(15)
        ->and($record->total_tokens)->toBe(40)
        ->and($record->total_cost_in_cents)->toBeGreaterThan(0);
});
