<?php

use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;

it('records elevenlabs tts request to database', function () {
    $context = Spectra::startRequest('elevenlabs', 'eleven_multilingual_v2');
    $context->requestData = [
        'text' => 'Hello, this is a test of text to speech.',
        'model_id' => 'eleven_multilingual_v2',
    ];

    $record = Spectra::recordSuccess($context, ['audio' => '[binary]'], [
        'prompt_tokens' => 0,
        'completion_tokens' => 0,
    ]);

    expect($record)->toBeInstanceOf(SpectraRequest::class)
        ->and($record->provider)->toBe('elevenlabs')
        ->and($record->status_code)->toBe(200);
});
