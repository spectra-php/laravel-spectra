<?php

use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;

it('records cohere chat response to database', function () {
    $response = $this->loadMockResponse('cohere/chat.json');

    Spectra::track('cohere', 'command-r-plus', function ($context) use ($response) {
        return $response;
    });

    $record = SpectraRequest::latest()->first();

    expect($record)->not->toBeNull()
        ->and($record->provider)->toBe('cohere')
        ->and($record->status_code)->toBe(200)
        ->and($record->prompt_tokens)->toBe(12)
        ->and($record->completion_tokens)->toBe(18);
});
