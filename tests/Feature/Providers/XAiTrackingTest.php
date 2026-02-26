<?php

use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;

it('records xai chat response to database', function () {
    $response = $this->loadMockResponse('xai/chat.json');

    Spectra::track('xai', 'grok-2', function ($context) use ($response) {
        return $response;
    });

    $record = SpectraRequest::latest()->first();

    expect($record)->not->toBeNull()
        ->and($record->provider)->toBe('xai')
        ->and($record->status_code)->toBe(200)
        ->and($record->prompt_tokens)->toBe(10)
        ->and($record->completion_tokens)->toBe(12)
        ->and($record->total_tokens)->toBe(22);
});
