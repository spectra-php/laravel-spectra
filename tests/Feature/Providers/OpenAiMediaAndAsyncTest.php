<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Spectra\Models\SpectraDailyStat;
use Spectra\Models\SpectraRequest;

/**
 * OpenAI background responses, video, images and audio. Real wire-format
 * responses go through the HTTP client and the global tracking middleware.
 * Costs are worked out by hand from OpenAIPricing.
 */
it('records a background response once, when a poll returns it finished', function () {
    $queued = $this->loadMockResponse('openai/response_background_queued.json');
    $inProgress = $this->loadMockResponse('openai/response_background_in_progress.json');
    $completed = $this->loadMockResponse('openai/response_background_completed.json');

    Http::fake([
        'api.openai.com/v1/responses' => Http::response($queued),
        'api.openai.com/v1/responses/*' => Http::sequence()
            ->push($inProgress)
            ->push($completed)
            ->push($completed),
    ]);

    Http::post('https://api.openai.com/v1/responses', ['model' => 'gpt-5.5-pro', 'background' => true, 'input' => 'Prove the bound.']);

    expect(SpectraRequest::query()->count())->toBe(0);

    Http::get('https://api.openai.com/v1/responses/resp_bg_7a6b5c4d3e2f1a0b');

    expect(SpectraRequest::query()->count())->toBe(0);

    Http::get('https://api.openai.com/v1/responses/resp_bg_7a6b5c4d3e2f1a0b');
    Http::get('https://api.openai.com/v1/responses/resp_bg_7a6b5c4d3e2f1a0b');

    $row = SpectraRequest::query()->sole();

    // Served at service_tier "flex": gpt-5.5-pro flex in 1500, out 9000.
    // (6000*1500 + 40000*9000) / 1M = 369.0
    expect($row->response_id)->toBe('resp_bg_7a6b5c4d3e2f1a0b')
        ->and($row->model)->toBe('GPT-5.5 Pro')
        ->and($row->pricing_tier)->toBe('flex')
        ->and($row->prompt_tokens)->toBe(6000)
        ->and($row->completion_tokens)->toBe(40000)
        ->and($row->reasoning_tokens)->toBe(38000)
        ->and($row->finish_reason)->toBe('completed')
        // completed_at - created_at = 180 s, not the duration of the poll.
        ->and($row->latency_ms)->toBe(180_000)
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(369.0, 1e-9)
        ->and((int) SpectraDailyStat::query()->sum('request_count'))->toBe(1)
        ->and((float) SpectraDailyStat::query()->sum('total_cost_in_cents'))->toEqualWithDelta(369.0, 1e-9);
});

it('records a cancelled background response', function () {
    $cancelled = $this->loadMockResponse('openai/response_background_queued.json');
    $cancelled['status'] = 'cancelled';
    Http::fake(['api.openai.com/*' => Http::response($cancelled)]);

    Http::post('https://api.openai.com/v1/responses/resp_bg_7a6b5c4d3e2f1a0b/cancel');

    $row = SpectraRequest::query()->sole();

    expect($row->finish_reason)->toBe('cancelled')
        ->and($row->prompt_tokens)->toBe(0)
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(0.0, 1e-12);
});

it('does not record again a stored response fetched later', function () {
    $stored = $this->loadMockResponse('openai/response_cache_writes_priority.json');
    Http::fake(['api.openai.com/*' => Http::response($stored)]);

    Http::get('https://api.openai.com/v1/responses/resp_0a1b2c3d4e5f60718293a4b5c6d7e8f9');
    Http::get('https://api.openai.com/v1/responses/resp_0a1b2c3d4e5f60718293a4b5c6d7e8f9/input_items');
    Http::delete('https://api.openai.com/v1/responses/resp_0a1b2c3d4e5f60718293a4b5c6d7e8f9');

    expect(SpectraRequest::query()->count())->toBe(0);
});

it('records a finished sora video with its generation time as latency', function () {
    Http::fake(['api.openai.com/*' => Http::response($this->loadMockResponse('openai/video_completed.json'))]);

    Http::get('https://api.openai.com/v1/videos/video_abc123');

    $row = SpectraRequest::query()->sole();

    // sora-2: 10¢ per second, 10 seconds = 100¢. completed_at - created_at = 63 s.
    expect($row->provider)->toBe('openai')
        ->and($row->model)->toBe('Sora 2')
        ->and($row->model_type)->toBe('video')
        ->and($row->latency_ms)->toBe(63_000)
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(100.0, 1e-9);
});

it('bills gpt-image output as image tokens and the text part at the text rate', function () {
    Http::fake(['api.openai.com/*' => Http::response($this->loadMockResponse('openai/image_generation_gpt_image_1_5.json'))]);

    Http::post('https://api.openai.com/v1/images/edits', ['model' => 'gpt-image-1.5', 'prompt' => 'Add a red scarf']);

    $row = SpectraRequest::query()->sole();

    // gpt-image-1.5: text in 500, image in 800, text out 1000, image out 3200.
    // Input 20 text + 100 image; output 444 text + 1056 image.
    // (20*500 + 100*800 + 444*1000 + 1056*3200) / 1M = 3.9132
    expect($row->provider)->toBe('openai')
        ->and($row->model)->toBe('GPT Image 1.5')
        ->and($row->model_type)->toBe('image')
        ->and($row->image_count)->toBe(1)
        ->and($row->prompt_tokens)->toBe(120)
        ->and($row->completion_tokens)->toBe(1500)
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(3.9132, 1e-9);
});

it('bills all gpt-image-1 output as image tokens when there is no breakdown', function () {
    Http::fake(['api.openai.com/*' => Http::response($this->loadMockResponse('openai/image_generation_gpt_image_1.json'))]);

    Http::post('https://api.openai.com/v1/images/generations', ['model' => 'gpt-image-1', 'prompt' => 'A lighthouse', 'quality' => 'high']);

    // gpt-image-1: text in 500, image out 4000 → (50*500 + 4160*4000) / 1M = 16.665
    expect((float) SpectraRequest::query()->sole()->total_cost_in_cents)->toEqualWithDelta(16.665, 1e-9);
});

it('applies the hd tier to dall-e-3 hd images', function () {
    Http::fake(['api.openai.com/*' => Http::response($this->loadMockResponse('openai/image_generation_dall_e_3_hd.json'))]);

    Http::post('https://api.openai.com/v1/images/generations', [
        'model' => 'dall-e-3',
        'prompt' => 'A lighthouse at dusk',
        'quality' => 'hd',
        'response_format' => 'b64_json',
    ]);

    $row = SpectraRequest::query()->sole();

    // dall-e-3 hd: 8¢ per 1024x1024 image.
    expect($row->pricing_tier)->toBe('hd')
        ->and($row->image_count)->toBe(1)
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(8.0, 1e-9);
});

it('bills token-priced transcriptions on audio input tokens', function () {
    Http::fake(['api.openai.com/*' => Http::response($this->loadMockResponse('openai/transcription_tokens_usage.json'))]);

    Http::attach('file', 'RIFF....WAVEfmt ', 'speech.wav')
        ->post('https://api.openai.com/v1/audio/transcriptions', ['model' => 'gpt-4o-transcribe']);

    $row = SpectraRequest::query()->sole();

    // gpt-4o-transcribe (in 250, out 1000): (14*250 + 45*1000) / 1M = 0.0485
    expect($row->provider)->toBe('openai')
        ->and($row->model)->toBe('GPT-4o Transcribe')
        ->and($row->model_type)->toBe('stt')
        ->and($row->prompt_tokens)->toBe(14)
        ->and($row->completion_tokens)->toBe(45)
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(0.0485, 1e-9);
});

it('bills whisper on the seconds in the usage', function () {
    Http::fake(['api.openai.com/*' => Http::response($this->loadMockResponse('openai/transcription_duration_usage.json'))]);

    Http::attach('file', 'RIFF....WAVEfmt ', 'speech.wav')
        ->post('https://api.openai.com/v1/audio/transcriptions', ['model' => 'whisper-1']);

    $row = SpectraRequest::query()->sole();

    // whisper-1: 0.6¢ per minute, 11 s → 11 / 60 * 0.6 = 0.11
    expect($row->duration_seconds)->toEqual(11.0)
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(0.11, 1e-9);
});

it('records a plain-text transcription at zero cost without altering its body', function () {
    Http::fake(['api.openai.com/*' => Http::response("Hello world\n", 200, ['Content-Type' => 'text/plain; charset=utf-8'])]);

    $response = Http::attach('file', 'RIFF....WAVEfmt ', 'speech.wav')
        ->post('https://api.openai.com/v1/audio/transcriptions', ['model' => 'whisper-1', 'response_format' => 'text']);

    expect($response->body())->toBe("Hello world\n");

    $row = SpectraRequest::query()->sole();

    // The text format has no duration, so it can't be priced.
    expect($row->model)->toBe('Whisper')
        ->and($row->status_code)->toBe(200)
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(0.0, 1e-12);
});

it('bills tts-1 per input character', function () {
    Http::fake(['api.openai.com/*' => Http::response('ID3fake-mp3-bytes', 200, ['Content-Type' => 'audio/mpeg'])]);

    Http::post('https://api.openai.com/v1/audio/speech', ['model' => 'tts-1', 'voice' => 'alloy', 'input' => 'Hello world']);

    $row = SpectraRequest::query()->sole();

    // tts-1: $15 per 1M characters, 11 characters → 11 * 1500 / 1M = 0.0165
    expect($row->provider)->toBe('openai')
        ->and($row->model)->toBe('TTS')
        ->and($row->model_type)->toBe('tts')
        ->and($row->input_characters)->toBe(11)
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(0.0165, 1e-9);
});
