<?php

declare(strict_types=1);

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Spectra\Models\SpectraRequest;

/**
 * Gemini API cost tracking, end to end. Requests go through Laravel's HTTP
 * client so the global watcher records them as in a real app. Expected costs
 * are worked out by hand from GooglePricing (US cents per 1M tokens).
 * Several models change price on a known date, so the clock is fixed.
 */
beforeEach(function () {
    $this->travelTo('2026-10-15 12:00:00');
});

function geminiStream(string $name): string
{
    return File::get(__DIR__.'/../../responses/google/streams/'.$name);
}

function postGemini(string $model, array $body = [], string $action = 'generateContent', string $query = ''): Response
{
    return Http::withHeaders(['x-goog-api-key' => 'test-key'])
        ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:{$action}{$query}", $body + [
            'contents' => [['role' => 'user', 'parts' => [['text' => 'Hello']]]],
        ]);
}

it('bills thinking tokens at the output rate', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->loadMockResponse('google/generate-content-thinking.json'))]);

    postGemini('gemini-3.8-flash');

    $row = SpectraRequest::query()->sole();

    // Gemini 3.8 Flash (until 2026-12-31): $0.75 in, $3.75 out.
    // candidatesTokenCount leaves the 900 thinking tokens out; both are output:
    // (1200×75 + (300 + 900)×375) / 1M = 0.54¢
    expect($row->provider)->toBe('google')
        ->and($row->model)->toBe('Gemini 3.8 Flash')
        ->and($row->model_type)->toBe('text')
        ->and($row->prompt_tokens)->toBe(1200)
        ->and($row->completion_tokens)->toBe(1200)
        ->and($row->reasoning_tokens)->toBe(900)
        ->and($row->is_reasoning)->toBeTrue()
        ->and($row->pricing_tier)->toBe('standard')
        ->and($row->response_id)->toBe('mF3vaOPkLqPh7M8Px8OxwQo')
        ->and($row->total_cost_in_cents)->toEqualWithDelta(0.54, 0.000001);
});

it('prices Gemini 3.8 Flash at the doubled rates from 2027', function () {
    $this->travelTo('2027-01-02 09:00:00');
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->loadMockResponse('google/generate-content-thinking.json'))]);

    postGemini('gemini-3.8-flash');

    // $1.50 in, $7.50 out: (1200×150 + 1200×750) / 1M = 1.08¢
    expect(SpectraRequest::query()->sole()->total_cost_in_cents)->toEqualWithDelta(1.08, 0.000001);
});

it('adds tool-use prompt tokens to the input and counts search grounding without pricing it', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->loadMockResponse('google/generate-content-grounding.json'))]);

    postGemini('gemini-2.5-flash', ['tools' => [['google_search' => (object) []]]]);

    $row = SpectraRequest::query()->sole();

    // Gemini 2.5 Flash: $0.30 in, $2.50 out, $0.03 cached.
    // Input = 5000 prompt (2000 of them cached) + 1500 tool-use prompt:
    // (4500×30 + 2000×3 + (400 + 600)×250) / 1M = 0.391¢
    expect($row->prompt_tokens)->toBe(6500)
        ->and($row->cached_tokens)->toBe(2000)
        ->and($row->completion_tokens)->toBe(1000)
        ->and($row->tool_call_counts)->toEqual(['google_search' => 2])
        ->and($row->total_cost_in_cents)->toEqualWithDelta(0.391, 0.000001);
});

it('bills uncached audio input at the audio rate', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->loadMockResponse('google/generate-content-audio-input.json'))]);

    postGemini('gemini-2.5-flash');

    // Gemini 2.5 Flash: audio $1.00, text $0.30, cached $0.03, output $2.50.
    // 4800 audio tokens, 1000 of them read from cache; 200 text tokens:
    // (3800×100 + 1000×3 + 200×30 + 150×250) / 1M = 0.4265¢
    expect(SpectraRequest::query()->sole()->total_cost_in_cents)->toEqualWithDelta(0.4265, 0.000001);
});

it('uses the flex tier the response reports', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->loadMockResponse('google/generate-content-flex.json'))]);

    postGemini('gemini-3-flash-preview', ['serviceTier' => 'flex']);

    $row = SpectraRequest::query()->sole();

    // Gemini 3 Flash Preview flex: $0.25 in, $1.50 out:
    // (10000×25 + 2000×150) / 1M = 0.55¢
    expect($row->pricing_tier)->toBe('flex')
        ->and($row->total_cost_in_cents)->toEqualWithDelta(0.55, 0.000001);
});

it('bills a priority request that was downgraded at standard prices', function () {
    $response = $this->loadMockResponse('google/generate-content-flex.json');
    $response['usageMetadata']['serviceTier'] = 'standard';
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response($response)]);

    postGemini('gemini-3-flash-preview', ['serviceTier' => 'priority']);

    // Standard: (10000×50 + 2000×300) / 1M = 1.1¢
    expect(SpectraRequest::query()->sole()->pricing_tier)->toBe('standard')
        ->and(SpectraRequest::query()->sole()->total_cost_in_cents)->toEqualWithDelta(1.1, 0.000001);
});

it('falls back to the requested tier when the response does not report one', function () {
    $response = $this->loadMockResponse('google/generate-content-flex.json');
    unset($response['usageMetadata']['serviceTier']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response($response)]);

    postGemini('gemini-3-flash-preview', ['serviceTier' => 'priority']);

    // Priority: $0.90 in, $5.40 out: (10000×90 + 2000×540) / 1M = 1.98¢
    expect(SpectraRequest::query()->sole()->pricing_tier)->toBe('priority')
        ->and(SpectraRequest::query()->sole()->total_cost_in_cents)->toEqualWithDelta(1.98, 0.000001);
});

it('prices prompts over 200K tokens at the long-context rates', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->loadMockResponse('google/generate-content-long-context.json'))]);

    postGemini('gemini-3.1-pro-preview');

    // Gemini 3.1 Pro Preview, prompt > 200K: $4 in, $18 out:
    // (250000×400 + 1000×1800) / 1M = 101.8¢
    expect(SpectraRequest::query()->sole()->total_cost_in_cents)->toEqualWithDelta(101.8, 0.000001);
});

it('bills image tokens at the image rate and text and thinking at the text rate', function () {
    config()->set('spectra.storage.media.enabled', false);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->loadMockResponse('google/generate-content-image.json'))]);

    postGemini('gemini-3.1-flash-image');

    $row = SpectraRequest::query()->sole();

    // Gemini 3.1 Flash Image: $0.50 in, $3 text/thinking out, $60 image out:
    // (20×50 + (30 + 200)×300 + 1120×6000) / 1M = 6.79¢
    expect($row->provider)->toBe('google')
        ->and($row->model_type)->toBe('image')
        ->and($row->image_count)->toBe(1)
        ->and($row->completion_tokens)->toBe(1350)
        ->and($row->total_cost_in_cents)->toEqualWithDelta(6.79, 0.000001);
});

it('treats the candidate tokens of an image response without a breakdown as image tokens', function () {
    config()->set('spectra.storage.media.enabled', false);
    $response = $this->loadMockResponse('google/generate-content-image.json');
    unset($response['usageMetadata']['candidatesTokensDetails'], $response['usageMetadata']['thoughtsTokenCount']);
    $response['modelVersion'] = 'gemini-2.5-flash-image';
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response($response)]);

    postGemini('gemini-2.5-flash-image');

    // Gemini 2.5 Flash Image: $0.30 in, $30 image out: (20×30 + 1150×3000) / 1M = 3.4506¢
    expect(SpectraRequest::query()->sole()->total_cost_in_cents)->toEqualWithDelta(3.4506, 0.000001);
});

it('prices text-to-speech output tokens at the audio rate', function () {
    config()->set('spectra.storage.media.enabled', false);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->loadMockResponse('google/generate-content-tts.json'))]);

    postGemini('gemini-3.8-flash-tts');

    $row = SpectraRequest::query()->sole();

    // Gemini 3.8 Flash TTS: $0.50 text in, $9 audio out: (50×50 + 250×900) / 1M = 0.2275¢
    expect($row->provider)->toBe('google')
        ->and($row->model_type)->toBe('tts')
        ->and($row->total_cost_in_cents)->toEqualWithDelta(0.2275, 0.000001);
});

/*
|--------------------------------------------------------------------------
| Streaming
|--------------------------------------------------------------------------
*/

it('records a server-sent-events stream with thinking and the flex tier', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiStream('thinking-flex.sse'), 200, ['Content-Type' => 'text/event-stream'])]);

    $response = postGemini('gemini-3.8-flash', ['serviceTier' => 'flex'], 'streamGenerateContent', '?alt=sse');

    expect(SpectraRequest::query()->count())->toBe(0);

    $response->body();

    $row = SpectraRequest::query()->sole();

    // Usage is cumulative; the last chunk wins. Gemini 3.8 Flash flex:
    // (800×37.5 + (200 + 500)×187.5) / 1M = 0.16125¢
    expect($row->is_streaming)->toBeTrue()
        ->and($row->model)->toBe('Gemini 3.8 Flash')
        ->and($row->response_id)->toBe('s9_vaMHeB4yR7M8Pq-i3wQo')
        ->and($row->prompt_tokens)->toBe(800)
        ->and($row->completion_tokens)->toBe(700)
        ->and($row->reasoning_tokens)->toBe(500)
        ->and($row->pricing_tier)->toBe('flex')
        ->and($row->response['content'])->toBe('Take the A10 and leave before 8 am.')
        ->and($row->total_cost_in_cents)->toEqualWithDelta(0.16125, 0.000001)
        // The first token is timed on the same (frozen) clock as the start, not
        // the wall clock: otherwise this is the days since 2026-10-15, which
        // overflows PostgreSQL's integer column after 24.8 days.
        ->and($row->time_to_first_token_ms)->toBe(0);
});

it('records a streamed JSON array (streamGenerateContent without alt=sse)', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiStream('thinking-flex.json'), 200, ['Content-Type' => 'application/json; charset=UTF-8'])]);

    postGemini('gemini-3.8-flash', ['serviceTier' => 'flex'], 'streamGenerateContent')->body();

    $row = SpectraRequest::query()->sole();

    // Same usage as the server-sent-events stream, Gemini 3.8 Flash flex:
    // (800×37.5 + (200 + 500)×187.5) / 1M = 0.16125¢
    expect($row->model)->toBe('Gemini 3.8 Flash')
        ->and($row->response_id)->toBe('s9_vaMHeB4yR7M8Pq-i3wQo')
        ->and($row->prompt_tokens)->toBe(800)
        ->and($row->completion_tokens)->toBe(700)
        ->and($row->reasoning_tokens)->toBe(500)
        ->and($row->pricing_tier)->toBe('flex')
        ->and($row->finish_reason)->toBe('STOP')
        ->and($row->total_cost_in_cents)->toEqualWithDelta(0.16125, 0.000001);
});

/*
|--------------------------------------------------------------------------
| Veo
|--------------------------------------------------------------------------
*/

it('records a Veo job from the operation poll that finishes it', function () {
    config()->set('spectra.storage.media.enabled', false);
    $operation = 'models/veo-3.1-fast-generate-preview/operations/r8fv1yq3x2tk';

    Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
        ->push($this->loadMockResponse('google/veo-operation-running.json'))
        ->push(['name' => $operation, 'done' => false])
        ->push($this->loadMockResponse('google/veo-operation-done.json')),
    ]);

    Http::withHeaders(['x-goog-api-key' => 'test-key'])->post(
        'https://generativelanguage.googleapis.com/v1beta/models/veo-3.1-fast-generate-preview:predictLongRunning',
        ['instances' => [['prompt' => 'A lighthouse at dusk']], 'parameters' => ['durationSeconds' => 8, 'resolution' => '1080p']],
    );
    Http::withHeaders(['x-goog-api-key' => 'test-key'])->get("https://generativelanguage.googleapis.com/v1beta/{$operation}");

    expect(SpectraRequest::query()->count())->toBe(0);

    Http::withHeaders(['x-goog-api-key' => 'test-key'])->get("https://generativelanguage.googleapis.com/v1beta/{$operation}");

    $row = SpectraRequest::query()->sole();

    // The submit's 8 seconds at 1080p reach the poll through the remembered job:
    // 12 cents/second (hd) × 8 seconds.
    expect($row->provider)->toBe('google')
        ->and($row->model)->toBe('Veo 3.1 Fast')
        ->and($row->model_type)->toBe('video')
        ->and($row->video_count)->toBe(1)
        ->and($row->response_id)->toBe($operation)
        ->and($row->pricing_tier)->toBe('hd')
        ->and($row->total_cost_in_cents)->toEqualWithDelta(96.0, 0.000001);
});
