<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Spectra\Models\SpectraRequest;

/**
 * Groq requests as real apps send them: through Laravel's HTTP client, with
 * Spectra's watcher installed as global middleware.
 */
function groqSse(array ...$events): string
{
    return collect($events)
        ->map(fn (array $event) => 'data: '.json_encode($event, JSON_THROW_ON_ERROR)."\n\n")
        ->implode('')."data: [DONE]\n\n";
}

it('prices prefixed production ids with cached input at half price', function () {
    Http::fake(['api.groq.com/*' => Http::response($this->loadMockResponse('groq/chat-cached.json'))]);

    Http::post('https://api.groq.com/openai/v1/chat/completions', ['model' => 'openai/gpt-oss-120b', 'messages' => []]);

    $row = SpectraRequest::query()->sole();

    // GPT OSS 120B: 33 uncached × 15 + 4,608 cached × 7.5 + 1,817 × 60 = 144,075 / 1M cents.
    expect($row->provider)->toBe('groq')
        ->and($row->model)->toBe('GPT OSS 120B')
        ->and($row->model_type)->toBe('text')
        ->and($row->prompt_tokens)->toBe(4_641)
        ->and($row->cached_tokens)->toBe(4_608)
        ->and($row->completion_tokens)->toBe(1_817)
        ->and($row->reasoning_tokens)->toBe(512)
        ->and($row->pricing_tier)->toBe('standard')
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(0.144075, 1e-9);
});

it('reads streamed usage from x_groq.usage', function () {
    $chunk = fn (array $delta, ?string $finish = null, ?array $xGroq = null) => Arr::where([
        'id' => 'chatcmpl-groq-stream', 'object' => 'chat.completion.chunk', 'created' => 1760536800, 'model' => 'openai/gpt-oss-20b',
        'system_fingerprint' => 'fp_a09bde29de',
        'choices' => [['index' => 0, 'delta' => $delta, 'logprobs' => null, 'finish_reason' => $finish]],
        'x_groq' => $xGroq,
    ], fn ($value) => $value !== null);

    $sse = groqSse(
        $chunk(['role' => 'assistant', 'content' => ''], null, ['id' => 'req_01k3stream']),
        $chunk(['content' => 'Fast inference']),
        $chunk(['content' => ' matters.']),
        $chunk([], 'stop', ['id' => 'req_01k3stream', 'usage' => [
            'queue_time' => 0.02, 'prompt_tokens' => 2_000, 'prompt_time' => 0.01, 'completion_tokens' => 100,
            'completion_time' => 0.1, 'total_tokens' => 2_100, 'total_time' => 0.11,
            'prompt_tokens_details' => ['cached_tokens' => 1_024],
        ]]),
    );

    Http::fake(['api.groq.com/*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);

    Http::post('https://api.groq.com/openai/v1/chat/completions', ['model' => 'openai/gpt-oss-20b', 'stream' => true, 'messages' => []])->body();

    $row = SpectraRequest::query()->sole();

    // GPT OSS 20B: 976 uncached × 7.5 + 1,024 cached × 3.75 + 100 × 30 = 14,160 / 1M cents.
    expect($row->is_streaming)->toBeTrue()
        ->and($row->response['content'])->toBe('Fast inference matters.')
        ->and($row->prompt_tokens)->toBe(2_000)
        ->and($row->cached_tokens)->toBe(1_024)
        ->and($row->completion_tokens)->toBe(100)
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(0.01416, 1e-9);
});

it('bills the 10-second minimum when a json transcription has no duration', function () {
    Http::fake(['api.groq.com/*' => Http::response($this->loadMockResponse('groq/transcription-json.json'))]);

    Http::attach('file', 'fake-audio-bytes', 'clip.m4a')
        ->post('https://api.groq.com/openai/v1/audio/transcriptions', ['model' => 'whisper-large-v3-turbo', 'response_format' => 'json']);

    $row = SpectraRequest::query()->sole();

    // Turbo: $0.04 / hour = 4/60 cents per minute; 10 s billed = 4/60 × 10/60 cents.
    expect($row->model)->toBe('Whisper Large v3 Turbo')
        ->and($row->model_type)->toBe('stt')
        ->and($row->response_id)->toBe('req_01k3hj8v7xf2r9g4p6m1n0q3ts')
        ->and((float) $row->duration_seconds)->toBe(10.0)
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta((4 / 60) * (10 / 60), 1e-12);
});

it('bills a verbose transcription shorter than 10 seconds at the 10-second minimum', function () {
    $body = $this->loadMockResponse('groq/transcription.json');
    $body['duration'] = 3.2;
    Http::fake(['api.groq.com/*' => Http::response($body)]);

    Http::attach('file', 'a', 'a.wav')->post('https://api.groq.com/openai/v1/audio/transcriptions', ['model' => 'whisper-large-v3', 'response_format' => 'verbose_json']);

    $row = SpectraRequest::query()->sole();

    // Whisper Large v3: $0.111 / hour = 0.185 cents per minute × 10 s billed.
    expect((float) $row->duration_seconds)->toBe(10.0)
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(0.185 * 10 / 60, 1e-12);
});

it('bills a translation by its audio length', function () {
    Http::fake(['api.groq.com/*' => Http::response($this->loadMockResponse('groq/transcription.json'))]);

    Http::attach('file', 'b', 'b.wav')->post('https://api.groq.com/openai/v1/audio/translations', ['model' => 'whisper-large-v3', 'response_format' => 'verbose_json']);

    $row = SpectraRequest::query()->sole();

    // Whisper Large v3: 0.185 cents per minute × (120.5 s / 60).
    expect($row->provider)->toBe('groq')
        ->and($row->model)->toBe('Whisper Large v3')
        ->and($row->model_type)->toBe('stt')
        ->and((float) $row->duration_seconds)->toBe(120.5)
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(0.185 * 120.5 / 60, 1e-12);
});

it('tracks Orpheus text to speech per character', function () {
    Http::fake(['api.groq.com/*' => Http::response('RIFF$'.str_repeat("\0", 64), 200, ['Content-Type' => 'audio/wav'])]);

    $input = 'Welcome to Orpheus text-to-speech. [cheerful] This is an example.';

    Http::post('https://api.groq.com/openai/v1/audio/speech', ['model' => 'canopylabs/orpheus-v1-english', 'voice' => 'troy', 'input' => $input, 'response_format' => 'wav']);

    $row = SpectraRequest::query()->sole();

    // $22 per 1M characters = 2,200 cents per 1M characters.
    expect($row->provider)->toBe('groq')
        ->and($row->model)->toBe('Orpheus V1 English')
        ->and($row->model_type)->toBe('tts')
        ->and($row->input_characters)->toBe(mb_strlen($input))
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(mb_strlen($input) * 2200 / 1_000_000, 1e-12);
});

/**
 * A completed Groq Responses API body: reasoning, then the message, served
 * at the flex tier.
 *
 * @return array<string, mixed>
 */
function groqResponse(): array
{
    return [
        'id' => 'resp_01k3hgcytaf7vsyqqdk1932swk', 'object' => 'response', 'status' => 'completed', 'model' => 'openai/gpt-oss-20b',
        'output' => [
            ['type' => 'reasoning', 'id' => 'resp_r1', 'status' => 'completed', 'content' => [['type' => 'reasoning_text', 'text' => 'Need brief explanation.']], 'summary' => []],
            ['type' => 'message', 'id' => 'msg_1', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Models learn from examples.', 'annotations' => [], 'logprobs' => null]]],
        ],
        'reasoning' => ['effort' => 'low'],
        'service_tier' => 'flex',
        'usage' => ['input_tokens' => 80, 'input_tokens_details' => ['cached_tokens' => 0, 'reasoning_tokens' => 0], 'output_tokens' => 213, 'output_tokens_details' => ['cached_tokens' => 0, 'reasoning_tokens' => 0], 'total_tokens' => 293],
    ];
}

it('bills a flex Responses API call like on-demand', function () {
    Http::fake(['api.groq.com/*' => Http::response(groqResponse())]);

    Http::post('https://api.groq.com/openai/v1/responses', ['model' => 'openai/gpt-oss-20b', 'input' => 'How are models trained?', 'service_tier' => 'flex']);

    $row = SpectraRequest::query()->sole();

    // Flex bills like on-demand: 80 × 7.5 + 213 × 30 = 6,990 / 1M cents.
    expect($row->pricing_tier)->toBe('flex')
        ->and($row->prompt_tokens)->toBe(80)
        ->and($row->completion_tokens)->toBe(213)
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(0.00699, 1e-12);
});

it('records a streamed Responses API call from its completed event', function () {
    $sse = 'event: response.output_text.delta'."\n".'data: '.json_encode(['type' => 'response.output_text.delta', 'delta' => 'Models learn from examples.'], JSON_THROW_ON_ERROR)."\n\n"
        .'event: response.completed'."\n".'data: '.json_encode(['type' => 'response.completed', 'response' => array_merge(groqResponse(), ['id' => 'resp_streamed'])], JSON_THROW_ON_ERROR)."\n\n";

    Http::fake(['api.groq.com/*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);

    Http::post('https://api.groq.com/openai/v1/responses', ['model' => 'openai/gpt-oss-20b', 'input' => 'How are models trained?', 'stream' => true])->body();

    $row = SpectraRequest::query()->sole();

    // 80 × 7.5 + 213 × 30 = 6,990 / 1M cents.
    expect($row->is_streaming)->toBeTrue()
        ->and($row->response_id)->toBe('resp_streamed')
        ->and($row->response['content'])->toBe('Models learn from examples.')
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(0.00699, 1e-12);
});
