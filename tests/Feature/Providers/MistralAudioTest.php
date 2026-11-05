<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Spectra\Models\SpectraRequest;
use Spectra\Spectra;

/**
 * Mistral Voxtral audio recorded by hand: transcription is billed per
 * minute, speech per character.
 */
it('bills a voxtral transcription per minute of audio', function () {
    $manager = app(Spectra::class);

    $context = $manager->startRequest('mistral', 'voxtral-mini-latest', [
        'endpoint' => '/v1/audio/transcriptions',
        'request_data' => ['model' => 'voxtral-mini-latest'],
    ]);

    $manager->recordSuccess($context, $this->loadMockResponse('mistral/transcription.json'));

    $record = SpectraRequest::query()->sole();

    // voxtral-mini-latest: 0.3 cents/minute × (120s / 60) = 0.6 cents
    expect($record->provider)->toBe('mistral')
        ->and($record->model)->toBe('Voxtral Mini Transcribe')
        ->and($record->model_type)->toBe('stt')
        ->and((float) $record->duration_seconds)->toBe(120.0)
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.6, 0.001);
});

it('bills voxtral speech per input character without storing the audio', function () {
    $manager = app(Spectra::class);

    $input = Str::repeat('a', 1000);

    $context = $manager->startRequest('mistral', 'voxtral-mini-tts-latest', [
        'endpoint' => '/v1/audio/speech',
        'request_data' => ['model' => 'voxtral-mini-tts-latest', 'input' => $input],
    ]);

    $manager->recordSuccess($context, [
        'audio_data' => base64_encode(str_repeat('x', 2000)),
    ]);

    $record = SpectraRequest::query()->sole();

    // voxtral-mini-tts-latest: 1600 cents per 1M chars × 1000 chars = 1.6 cents
    expect($record->provider)->toBe('mistral')
        ->and($record->model)->toBe('Voxtral Mini TTS')
        ->and($record->model_type)->toBe('tts')
        ->and($record->input_characters)->toBe(1000)
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(1.6, 0.001)
        ->and($record->response['audio_data'] ?? null)->toBe('[stripped]');
});
