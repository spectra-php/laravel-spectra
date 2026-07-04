<?php

declare(strict_types=1);

use Spectra\Models\SpectraRequest;
use Spectra\Spectra;

it('records elevenlabs tts with per-character cost', function () {
    $manager = app(Spectra::class);

    $text = str_repeat('a', 1000);

    $context = $manager->startRequest('elevenlabs', 'eleven_multilingual_v2', [
        'endpoint' => '/v1/text-to-speech/JBFqnCBsd6RMkjVDRZzb',
        'request_data' => ['text' => $text, 'model_id' => 'eleven_multilingual_v2'],
    ]);

    $manager->recordSuccess($context, []);

    $record = SpectraRequest::latest()->first();

    // eleven_multilingual_v2: 10000 cents per 1M chars × 1000 chars = 10 cents
    expect($record)->not->toBeNull()
        ->and($record->provider)->toBe('elevenlabs')
        ->and($record->input_characters)->toBe(1000)
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(10.0, 0.001);
});
