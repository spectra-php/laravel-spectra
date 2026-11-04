<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Spectra\Models\SpectraRequest;

/**
 * ElevenLabs requests through Laravel's HTTP client: text to speech per
 * character, speech to text per minute of audio, and sound effects and music
 * per minute of requested audio.
 */
it('bills text to speech per character of the request text', function () {
    Http::fake(['api.elevenlabs.io/*' => Http::response("\xFF\xFB\x90\x00fake-mp3-bytes", 200, ['Content-Type' => 'audio/mpeg'])]);

    Http::post('https://api.elevenlabs.io/v1/text-to-speech/JBFqnCBsd6RMkjVDRZzb?output_format=mp3_44100_128', [
        'text' => str_repeat('a', 2500),
        'model_id' => 'eleven_flash_v2_5',
    ]);

    $record = SpectraRequest::query()->sole();

    // eleven_flash_v2_5: $0.04 per 1,000 characters → 2,500 × 4000 / 1M = 10 cents
    expect($record->provider)->toBe('elevenlabs')
        ->and($record->model)->toBe('Eleven Flash v2.5')
        ->and($record->model_type)->toBe('tts')
        ->and($record->input_characters)->toBe(2500)
        ->and($record->total_cost_in_cents)->toEqualWithDelta(10.0, 0.000001);
});

it('uses eleven_multilingual_v2 when the request names no model', function () {
    Http::fake(['api.elevenlabs.io/*' => Http::response('fake-mp3-bytes', 200, ['Content-Type' => 'audio/mpeg'])]);

    Http::post('https://api.elevenlabs.io/v1/text-to-speech/JBFqnCBsd6RMkjVDRZzb/stream', ['text' => str_repeat('b', 1000)]);

    // eleven_multilingual_v2: $0.08 per 1,000 characters → 8 cents
    expect(SpectraRequest::query()->sole()->model)->toBe('Eleven Multilingual v2')
        ->and(SpectraRequest::query()->sole()->total_cost_in_cents)->toEqualWithDelta(8.0, 0.000001);
});

it('bills text to speech with timestamps without storing the base64 audio', function () {
    Http::fake(['api.elevenlabs.io/*' => Http::response($this->loadMockResponse('elevenlabs/text-to-speech-with-timestamps.json'))]);

    Http::post('https://api.elevenlabs.io/v1/text-to-speech/JBFqnCBsd6RMkjVDRZzb/with-timestamps', [
        'text' => 'Hello',
        'model_id' => 'eleven_v3',
    ]);

    $record = SpectraRequest::query()->sole();

    // eleven_v3: 5 characters × 8000 / 1M = 0.04 cents
    expect($record->model)->toBe('Eleven v3')
        ->and($record->input_characters)->toBe(5)
        ->and($record->total_cost_in_cents)->toEqualWithDelta(0.04, 0.000001)
        ->and(json_encode($record->response))->not->toContain('SUQzBAAAAAAAI1RTU0');
});

it('bills speech to text per minute of audio', function () {
    Http::fake(['api.elevenlabs.io/*' => Http::response($this->loadMockResponse('elevenlabs/speech-to-text.json'))]);

    Http::asMultipart()->post('https://api.elevenlabs.io/v1/speech-to-text', [
        ['name' => 'model_id', 'contents' => 'scribe_v2'],
        ['name' => 'file', 'contents' => 'fake-audio', 'filename' => 'meeting.mp3'],
    ]);

    $record = SpectraRequest::query()->sole();

    // Scribe v2: $0.22 per hour → 90 s = 1.5 min × 22/60 cents = 0.55 cents
    expect($record->model)->toBe('Scribe v2')
        ->and($record->model_type)->toBe('stt')
        ->and($record->duration_seconds)->toEqualWithDelta(90.0, 0.001)
        ->and($record->total_cost_in_cents)->toEqualWithDelta(0.55, 0.000001);
});

it('falls back to the last word end when the transcript has no audio length', function () {
    $body = $this->loadMockResponse('elevenlabs/speech-to-text.json');
    unset($body['audio_duration_secs']);

    Http::fake(['api.elevenlabs.io/*' => Http::response($body)]);

    Http::asMultipart()->post('https://api.elevenlabs.io/v1/speech-to-text', [
        ['name' => 'model_id', 'contents' => 'scribe_v2'],
        ['name' => 'file', 'contents' => 'fake-audio', 'filename' => 'hello.mp3'],
    ]);

    // Last word ends at 1.2 s → 0.02 min × 22/60 cents
    expect(SpectraRequest::query()->sole()->duration_seconds)->toEqualWithDelta(1.2, 0.001)
        ->and(SpectraRequest::query()->sole()->total_cost_in_cents)->toEqualWithDelta(1.2 / 60 * 22 / 60, 0.000001);
});

it('does not record a speech to text webhook receipt', function () {
    Http::fake(['api.elevenlabs.io/*' => Http::response([
        'message' => 'Request accepted. Results will be sent to your webhook.',
        'request_id' => 'req_123',
        'transcription_id' => 'tr_456',
    ])]);

    Http::asMultipart()->post('https://api.elevenlabs.io/v1/speech-to-text', [
        ['name' => 'model_id', 'contents' => 'scribe_v2'],
        ['name' => 'webhook', 'contents' => 'true'],
        ['name' => 'file', 'contents' => 'fake-audio', 'filename' => 'long.mp3'],
    ]);

    expect(SpectraRequest::query()->count())->toBe(0);
});

it('bills sound effects per minute of requested audio', function () {
    Http::fake(['api.elevenlabs.io/*' => Http::response('fake-mp3-bytes', 200, ['Content-Type' => 'audio/mpeg'])]);

    Http::post('https://api.elevenlabs.io/v1/sound-generation', ['text' => 'Thunder rolling in', 'duration_seconds' => 5]);

    $record = SpectraRequest::query()->sole();

    // Sound effects: $0.12 per minute → 5 s = 1 cent.
    expect($record->model)->toBe('Eleven Sound Effects v2')
        ->and($record->total_cost_in_cents)->toEqualWithDelta(1.0, 0.000001);
});

it('bills music per minute of requested audio', function () {
    Http::fake(['api.elevenlabs.io/*' => Http::response('fake-mp3-bytes', 200, ['Content-Type' => 'audio/mpeg'])]);

    Http::post('https://api.elevenlabs.io/v1/music', ['prompt' => 'Calm piano', 'music_length_ms' => 30000]);

    $record = SpectraRequest::query()->sole();

    // Music: $0.15 per minute → 30 s = 7.5 cents.
    expect($record->model)->toBe('Eleven Music v1')
        ->and($record->total_cost_in_cents)->toEqualWithDelta(7.5, 0.000001);
});
