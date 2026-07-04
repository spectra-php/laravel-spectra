<?php

declare(strict_types=1);

use Spectra\Models\SpectraRequest;
use Spectra\Spectra;

it('records groq whisper transcription with per-minute cost', function () {
    $manager = app(Spectra::class);

    $context = $manager->startRequest('groq', 'whisper-large-v3', [
        'endpoint' => '/openai/v1/audio/transcriptions',
        'request_data' => ['model' => 'whisper-large-v3'],
    ]);

    $manager->recordSuccess($context, $this->loadMockResponse('groq/transcription.json'));

    $record = SpectraRequest::latest()->first();

    // whisper-large-v3: 0.185 cents/minute × (120.5s / 60)
    expect($record)->not->toBeNull()
        ->and($record->provider)->toBe('groq')
        ->and($record->model_type)->toBe('stt')
        ->and((float) $record->duration_seconds)->toBe(120.5)
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.185 * (120.5 / 60), 0.001);
});
