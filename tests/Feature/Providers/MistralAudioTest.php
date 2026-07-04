<?php

declare(strict_types=1);

use Spectra\Models\SpectraRequest;
use Spectra\Spectra;

it('records mistral voxtral transcription with per-minute cost', function () {
    $manager = app(Spectra::class);

    $context = $manager->startRequest('mistral', 'voxtral-mini-latest', [
        'endpoint' => '/v1/audio/transcriptions',
        'request_data' => ['model' => 'voxtral-mini-latest'],
    ]);

    $manager->recordSuccess($context, $this->loadMockResponse('mistral/transcription.json'));

    $record = SpectraRequest::latest()->first();

    // voxtral-mini-latest: 0.3 cents/minute × (120s / 60) = 0.6 cents
    expect($record)->not->toBeNull()
        ->and($record->provider)->toBe('mistral')
        ->and($record->model_type)->toBe('stt')
        ->and((float) $record->duration_seconds)->toBe(120.0)
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.6, 0.001);
});

it('records mistral voxtral tts with per-character cost', function () {
    $manager = app(Spectra::class);

    $input = str_repeat('a', 1000);

    $context = $manager->startRequest('mistral', 'voxtral-mini-tts-latest', [
        'endpoint' => '/v1/audio/speech',
        'request_data' => ['model' => 'voxtral-mini-tts-latest', 'input' => $input],
    ]);

    $manager->recordSuccess($context, [
        'audio_data' => base64_encode(str_repeat('x', 2000)),
    ]);

    $record = SpectraRequest::latest()->first();

    // voxtral-mini-tts-latest: 1600 cents per 1M chars × 1000 chars = 1.6 cents
    expect($record)->not->toBeNull()
        ->and($record->provider)->toBe('mistral')
        ->and($record->model_type)->toBe('tts')
        ->and($record->input_characters)->toBe(1000)
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(1.6, 0.001)
        ->and($record->response['audio_data'] ?? null)->toBe('[stripped]');
});
