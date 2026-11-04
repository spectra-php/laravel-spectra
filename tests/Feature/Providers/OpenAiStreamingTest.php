<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Spectra\Models\SpectraRequest;

/**
 * OpenAI streams through the HTTP client. Server-sent-event bodies in
 * OpenAI's wire format are recorded once the app has read the body. Costs
 * are worked out by hand from OpenAIPricing (cents per 1M tokens).
 */
function openAiStreamFixture(string $name): string
{
    return (string) file_get_contents(__DIR__.'/../../responses/openai/'.$name);
}

function fakeOpenAiStream(string $fixture, string $host = 'api.openai.com'): string
{
    $body = openAiStreamFixture($fixture);

    Http::fake([$host.'/*' => Http::response($body, 200, ['Content-Type' => 'text/event-stream; charset=utf-8'])]);

    return $body;
}

it('records a completed responses stream with cached tokens and the served tier', function () {
    $body = fakeOpenAiStream('responses_stream_completed.sse');

    $response = Http::post('https://api.openai.com/v1/responses', ['model' => 'gpt-5.4', 'stream' => true, 'input' => 'Say hello']);

    expect($response->body())->toBe($body);

    $row = SpectraRequest::sole();

    // Served at service_tier "priority": gpt-5.4 Fast in 500, cached 50, out 3000.
    // (1000*500 + 4000*50 + 300*3000) / 1M = 1.6
    expect($row->is_streaming)->toBeTrue()
        ->and($row->response_id)->toBe('resp_stream_completed01')
        ->and($row->model)->toBe('GPT-5.4')
        ->and($row->pricing_tier)->toBe('priority')
        ->and($row->prompt_tokens)->toBe(5000)
        ->and($row->completion_tokens)->toBe(300)
        ->and($row->cached_tokens)->toBe(4000)
        ->and($row->reasoning_tokens)->toBe(200)
        ->and($row->finish_reason)->toBe('completed')
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(1.6, 1e-9);
});

it('bills the 12h cache writes of a responses stream at the 12h rate', function () {
    $body = str_replace(
        ['gpt-5.4-2026-03-05', '"service_tier":"priority"', '"input_tokens_details":{"cached_tokens":4000}'],
        ['gpt-5.6-sol', '"service_tier":"default"', '"input_tokens_details":{"cached_tokens":1000,"cache_write_tokens":3000,"cache_write_tokens_details":{"ttl_30m_tokens":1000,"ttl_12h_tokens":2000}}'],
        openAiStreamFixture('responses_stream_completed.sse'),
    );
    Http::fake(['api.openai.com/*' => Http::response($body, 200, ['Content-Type' => 'text/event-stream; charset=utf-8'])]);

    Http::post('https://api.openai.com/v1/responses', ['model' => 'gpt-5.6-sol', 'stream' => true, 'input' => 'x'])->body();

    $row = SpectraRequest::sole();

    // gpt-5.6-sol standard: in 400, cached 40, 30m write 500, 12h write 1000, out 2000.
    // Uncached input = 5000 - 1000 cached - 3000 written = 1000.
    // (1000*400 + 1000*40 + 1000*500 + 2000*1000 + 300*2000) / 1M = 3.54
    expect($row->is_streaming)->toBeTrue()
        ->and($row->pricing_tier)->toBe('standard')
        ->and($row->cache_write_tokens)->toBe(3000)
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(3.54, 1e-9);
});

it('records the usage of a responses stream that ends incomplete', function () {
    fakeOpenAiStream('responses_stream_incomplete.sse');

    Http::post('https://api.openai.com/v1/responses', ['model' => 'gpt-5.4', 'stream' => true, 'max_output_tokens' => 1024, 'input' => 'x'])->body();

    $row = SpectraRequest::sole();

    // gpt-5.4 standard (in 250, out 1500): (800*250 + 1024*1500) / 1M = 1.736
    expect($row->prompt_tokens)->toBe(800)
        ->and($row->completion_tokens)->toBe(1024)
        ->and($row->finish_reason)->toBe('max_output_tokens')
        ->and($row->pricing_tier)->toBe('standard')
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(1.736, 1e-9);
});

it('records the usage of a responses stream that fails', function () {
    fakeOpenAiStream('responses_stream_failed.sse');

    Http::post('https://api.openai.com/v1/responses', ['model' => 'gpt-5.4', 'stream' => true, 'input' => 'x'])->body();

    $row = SpectraRequest::sole();

    // gpt-5.4 standard: (1200*250 + 50*1500) / 1M = 0.375
    expect($row->prompt_tokens)->toBe(1200)
        ->and($row->completion_tokens)->toBe(50)
        ->and($row->finish_reason)->toBe('failed')
        ->and($row->error_message)->not->toBeNull()
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(0.375, 1e-9);
});

it('records a chat completions stream with include_usage at the served tier', function () {
    $body = fakeOpenAiStream('chat_stream_with_usage.sse');

    $response = Http::post('https://api.openai.com/v1/chat/completions', [
        'model' => 'gpt-5.4-mini',
        'stream' => true,
        'stream_options' => ['include_usage' => true],
        'messages' => [],
    ]);

    expect($response->body())->toBe($body);

    $row = SpectraRequest::sole();

    // Served at service_tier "flex": gpt-5.4-mini flex in 37.5, cached 3.75, out 225.
    // (1000*37.5 + 2000*3.75 + 400*225) / 1M = 0.135
    expect($row->response_id)->toBe('chatcmpl-DQstream01')
        ->and($row->pricing_tier)->toBe('flex')
        ->and($row->prompt_tokens)->toBe(3000)
        ->and($row->completion_tokens)->toBe(400)
        ->and($row->cached_tokens)->toBe(2000)
        ->and($row->reasoning_tokens)->toBe(256)
        ->and($row->finish_reason)->toBe('stop')
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(0.135, 1e-9);
});

it('records a chat stream without include_usage with zero tokens', function () {
    fakeOpenAiStream('chat_stream_without_usage.sse');

    $response = Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-5.4-mini', 'stream' => true, 'messages' => []]);

    expect($response->body())->toContain('[DONE]');

    $row = SpectraRequest::sole();

    // OpenAI only sends usage when stream_options.include_usage is set.
    expect($row->is_streaming)->toBeTrue()
        ->and($row->prompt_tokens)->toBe(0)
        ->and($row->completion_tokens)->toBe(0)
        ->and($row->finish_reason)->toBe('stop')
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(0.0, 1e-12);
});

it('records a streamed transcription from its final event', function () {
    fakeOpenAiStream('transcription_stream.sse');

    Http::attach('file', 'RIFF....WAVEfmt ', 'speech.wav')
        ->post('https://api.openai.com/v1/audio/transcriptions', ['model' => 'gpt-4o-transcribe', 'stream' => 'true'])
        ->body();

    $row = SpectraRequest::sole();

    // gpt-4o-transcribe (audio in 250, text out 1000): (14*250 + 45*1000) / 1M = 0.0485
    expect($row->model)->toBe('GPT-4o Transcribe')
        ->and($row->model_type)->toBe('stt')
        ->and($row->prompt_tokens)->toBe(14)
        ->and($row->completion_tokens)->toBe(45)
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(0.0485, 1e-9);
});

it('records a streamed image generation from its completed event', function () {
    fakeOpenAiStream('image_generation_stream.sse');

    Http::post('https://api.openai.com/v1/images/generations', [
        'model' => 'gpt-image-1.5',
        'prompt' => 'A lighthouse at dusk',
        'stream' => true,
        'partial_images' => 1,
    ])->body();

    $row = SpectraRequest::sole();

    // The streamed usage has no output breakdown: all 1156 output tokens are image tokens.
    // gpt-image-1.5: text in 500, image out 3200 → (16*500 + 1156*3200) / 1M = 3.7072
    expect($row->model)->toBe('GPT Image 1.5')
        ->and($row->model_type)->toBe('image')
        ->and($row->prompt_tokens)->toBe(16)
        ->and($row->completion_tokens)->toBe(1156)
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(3.7072, 1e-9);
});
