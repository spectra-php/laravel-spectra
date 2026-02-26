<?php

use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;

it('records mistral chat response to database', function () {
    $response = $this->loadMockResponse('mistral/chat.json');

    Spectra::track('mistral', 'mistral-large-latest', function ($context) use ($response) {
        return $response;
    });

    $record = SpectraRequest::latest()->first();

    expect($record)->not->toBeNull()
        ->and($record->provider)->toBe('mistral')
        ->and($record->model)->toBe('Mistral Large 3')
        ->and($record->status_code)->toBe(200)
        ->and($record->prompt_tokens)->toBe(25)
        ->and($record->completion_tokens)->toBe(15)
        ->and($record->total_tokens)->toBe(40)
        ->and($record->total_cost_in_cents)->toBeGreaterThan(0);
});
