<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Spectra\Models\SpectraRequest;

/**
 * xAI requests as real apps send them: through Laravel's HTTP client, with
 * Spectra's watcher installed as global middleware.
 */
function xaiSse(array ...$events): string
{
    return implode('', array_map(fn (array $event) => 'data: '.json_encode($event)."\n\n", $events))."data: [DONE]\n\n";
}

it('bills reasoning tokens on top of completion tokens and uses the reported cost', function () {
    Http::fake(['api.x.ai/*' => Http::response($this->loadMockResponse('xai/chat-reasoning.json'))]);

    Http::post('https://api.x.ai/v1/chat/completions', ['model' => 'grok-4.3', 'messages' => [['role' => 'user', 'content' => 'What is 101*3?']]]);

    $row = SpectraRequest::query()->sole();

    // grok-4.3: 26 uncached × 125 + 6 cached × 20 + (9 + 94) output × 250 = 29,120 / 1M
    // = 0.02912 cents, which matches the reported 2,912,000 ticks (10^8 ticks per cent).
    expect($row->provider)->toBe('xai')
        ->and($row->model)->toBe('Grok 4.3')
        ->and($row->model_type)->toBe('text')
        ->and($row->prompt_tokens)->toBe(32)
        ->and($row->completion_tokens)->toBe(103)
        ->and($row->reasoning_tokens)->toBe(94)
        ->and($row->cached_tokens)->toBe(6)
        ->and($row->pricing_tier)->toBe('standard')
        ->and($row->response_id)->toBe('a3d1008e-4544-40d4-d075-11527e794e4a')
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(0.02912, 1e-9);
});

it('estimates the same cost from the catalog when no cost is reported', function () {
    $body = $this->loadMockResponse('xai/chat-reasoning.json');
    unset($body['usage']['cost_in_usd_ticks']);
    Http::fake(['api.x.ai/*' => Http::response($body)]);

    Http::post('https://api.x.ai/v1/chat/completions', ['model' => 'grok-4.3', 'messages' => []]);

    // 26 × 125 + 6 × 20 + 103 × 250 = 29,120 / 1M cents.
    expect((float) SpectraRequest::query()->sole()->total_cost_in_cents)->toEqualWithDelta(0.02912, 1e-9);
});

it('uses the priority tier only when the response confirms it', function () {
    $body = $this->loadMockResponse('xai/chat-reasoning.json');
    unset($body['usage']['cost_in_usd_ticks']);
    $body['service_tier'] = 'priority';
    // Each completion has its own id; rows are keyed by the response id.
    Http::fake(['api.x.ai/*' => Http::sequence()
        ->push(array_merge($body, ['id' => 'chat-priority']))
        ->push(array_merge($body, ['id' => 'chat-default', 'service_tier' => 'default']))]);

    Http::post('https://api.x.ai/v1/chat/completions', ['model' => 'grok-4.3', 'service_tier' => 'priority', 'messages' => []]);
    Http::post('https://api.x.ai/v1/chat/completions', ['model' => 'grok-4.3', 'service_tier' => 'priority', 'messages' => []]);

    [$granted, $downgraded] = SpectraRequest::query()->orderBy('id')->get()->all();

    // Priority (2x): 26 × 250 + 6 × 40 + 103 × 500 = 58,240 / 1M cents.
    expect($granted->pricing_tier)->toBe('priority')
        ->and((float) $granted->total_cost_in_cents)->toEqualWithDelta(0.05824, 1e-9)
        ->and($downgraded->pricing_tier)->toBe('standard')
        ->and((float) $downgraded->total_cost_in_cents)->toEqualWithDelta(0.02912, 1e-9);
});

it('bills every token at the long-context rate once the prompt reaches 200K', function () {
    $body = $this->loadMockResponse('xai/chat-reasoning.json');
    $body['usage'] = ['prompt_tokens' => 250_000, 'completion_tokens' => 1_000, 'total_tokens' => 251_000,
        'prompt_tokens_details' => ['text_tokens' => 250_000, 'cached_tokens' => 0], 'completion_tokens_details' => ['reasoning_tokens' => 0]];
    Http::fake(['api.x.ai/*' => Http::response($body)]);

    Http::post('https://api.x.ai/v1/chat/completions', ['model' => 'grok-4.3', 'messages' => []]);

    // Long context: 250,000 × 250 + 1,000 × 500 = 63,000,000 / 1M = 63 cents.
    expect((float) SpectraRequest::query()->sole()->total_cost_in_cents)->toEqualWithDelta(63.0, 1e-9);
});

it('tracks the Responses API with server-side tools and their fees', function () {
    $body = $this->loadMockResponse('xai/responses-tools.json');
    unset($body['usage']['cost_in_usd_ticks']);
    Http::fake(['api.x.ai/*' => Http::response($body)]);

    Http::post('https://api.x.ai/v1/responses', ['model' => 'grok-4.7', 'input' => 'Latest SpaceXSI news?', 'tools' => [['type' => 'web_search'], ['type' => 'x_search']]]);

    $row = SpectraRequest::query()->sole();

    // grok-4.7 tokens: 8,000 × 200 + 4,000 × 50 + (500 + 1,500) × 600 = 3,000,000 / 1M = 3 cents.
    // Tools: 2 web searches × 0.5 + 10 X posts × 0.5 + 1 X profile × 1 = 7 cents. Total 10 cents.
    expect($row->model)->toBe('Grok 4.7')
        ->and($row->model_type)->toBe('text')
        ->and($row->prompt_tokens)->toBe(12_000)
        ->and($row->cached_tokens)->toBe(4_000)
        ->and($row->completion_tokens)->toBe(2_000)
        ->and($row->reasoning_tokens)->toBe(1_500)
        ->and($row->tool_call_counts)->toMatchArray([
            'web_search_call' => 2,
            'x_search_call' => 1,
            'x_search_post' => 10,
            'x_search_profile' => 1,
        ])
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(10.0, 1e-9);
});

it('uses the reported cost, which includes the US endpoint surcharge', function () {
    $body = $this->loadMockResponse('xai/responses-tools.json');
    // Tokens at 1.1x (3 × 1.1 = 3.3 cents) plus 7 cents of tool fees = 10.3 cents.
    $body['usage']['cost_in_usd_ticks'] = 1_030_000_000;
    Http::fake(['us.api.x.ai/*' => Http::response($body)]);

    Http::post('https://us.api.x.ai/v1/responses', ['model' => 'grok-4.7', 'input' => 'hi']);

    expect((float) SpectraRequest::query()->sole()->total_cost_in_cents)->toEqualWithDelta(10.3, 1e-9);
});

it('tracks streamed chat completions with the final reported cost', function () {
    $chunk = fn (array $delta, ?array $usage = null, ?array $choices = null) => array_filter([
        'id' => 'chatcmpl-stream-1', 'object' => 'chat.completion.chunk', 'created' => 1760536800, 'model' => 'grok-4.3',
        'choices' => $choices ?? [['index' => 0, 'delta' => $delta]],
        'usage' => $usage,
        'system_fingerprint' => 'fp_3a7881249c',
    ], fn ($value) => $value !== null);

    $sse = xaiSse(
        $chunk(['reasoning_content' => 'Thinking', 'role' => 'assistant'], ['prompt_tokens' => 32, 'completion_tokens' => 1, 'total_tokens' => 33, 'prompt_tokens_details' => ['text_tokens' => 32, 'cached_tokens' => 6]]),
        $chunk(['content' => '101 × 3', 'role' => 'assistant'], ['prompt_tokens' => 32, 'completion_tokens' => 5, 'total_tokens' => 37, 'prompt_tokens_details' => ['text_tokens' => 32, 'cached_tokens' => 6]]),
        $chunk(['content' => ' = 303.'], null, [['index' => 0, 'delta' => ['content' => ' = 303.'], 'finish_reason' => 'stop']]),
        $chunk([], [
            'prompt_tokens' => 32, 'completion_tokens' => 9, 'total_tokens' => 135,
            'prompt_tokens_details' => ['text_tokens' => 32, 'audio_tokens' => 0, 'image_tokens' => 0, 'cached_tokens' => 6],
            'completion_tokens_details' => ['reasoning_tokens' => 94, 'audio_tokens' => 0],
            'num_sources_used' => 0, 'cost_in_usd_ticks' => 2_912_000,
        ], []),
    );

    Http::fake(['api.x.ai/*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);

    $response = Http::post('https://api.x.ai/v1/chat/completions', ['model' => 'grok-4.3', 'stream' => true, 'stream_options' => ['include_usage' => true], 'messages' => []]);

    expect($response->body())->toBe($sse);

    $row = SpectraRequest::query()->sole();

    // Same usage as the non-streamed example: 0.02912 cents reported.
    expect($row->is_streaming)->toBeTrue()
        ->and($row->response['content'])->toBe('101 × 3 = 303.')
        ->and($row->prompt_tokens)->toBe(32)
        ->and($row->completion_tokens)->toBe(103)
        ->and($row->reasoning_tokens)->toBe(94)
        ->and($row->cached_tokens)->toBe(6)
        ->and($row->finish_reason)->toBe('stop')
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(0.02912, 1e-9);
});

it('records a streamed Responses API call at its reported cost', function () {
    $completed = $this->loadMockResponse('xai/responses-tools.json');

    $sse = 'event: response.created'."\n".'data: '.json_encode(['type' => 'response.created', 'sequence_number' => 0, 'response' => ['id' => $completed['id'], 'object' => 'response', 'model' => 'grok-4.7', 'status' => 'in_progress', 'output' => []]])."\n\n"
        .'event: response.output_text.delta'."\n".'data: '.json_encode(['type' => 'response.output_text.delta', 'sequence_number' => 1, 'item_id' => 'msg_1', 'output_index' => 4, 'content_index' => 0, 'delta' => 'SpaceXSI released '])."\n\n"
        .'event: response.output_text.delta'."\n".'data: '.json_encode(['type' => 'response.output_text.delta', 'sequence_number' => 2, 'item_id' => 'msg_1', 'output_index' => 4, 'content_index' => 0, 'delta' => 'Grok 4.7 this week.'])."\n\n"
        .'event: response.completed'."\n".'data: '.json_encode(['type' => 'response.completed', 'sequence_number' => 3, 'response' => $completed])."\n\n";

    Http::fake(['api.x.ai/*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);

    Http::post('https://api.x.ai/v1/responses', ['model' => 'grok-4.7', 'stream' => true, 'input' => 'news'])->body();

    $row = SpectraRequest::query()->sole();

    // Reported: 1,000,000,000 ticks = 10 cents (3 cents of tokens + 7 cents of tools).
    expect($row->is_streaming)->toBeTrue()
        ->and($row->response_id)->toBe($completed['id'])
        ->and($row->prompt_tokens)->toBe(12_000)
        ->and($row->completion_tokens)->toBe(2_000)
        ->and($row->tool_call_counts)->toMatchArray(['web_search_call' => 2, 'x_search_post' => 10])
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(10.0, 1e-9);
});

it('records a video once, from the finished poll, priced by the reported cost', function () {
    $requestId = 'a3d1008e-4544-40d4-d075-11527e794e4a';

    Http::fake([
        'api.x.ai/v1/videos/generations' => Http::response(['request_id' => $requestId]),
        "api.x.ai/v1/videos/{$requestId}" => Http::sequence()
            ->push(['status' => 'pending', 'progress' => 40, 'model' => 'grok-imagine-video-1.5'])
            ->push($this->loadMockResponse('xai/video-done.json'))
            ->push($this->loadMockResponse('xai/video-done.json')),
    ]);

    Http::post('https://api.x.ai/v1/videos/generations', ['model' => 'grok-imagine-video-1.5', 'prompt' => 'A lake at sunrise', 'duration' => 6, 'resolution' => '720p']);
    Http::get("https://api.x.ai/v1/videos/{$requestId}");

    expect(SpectraRequest::query()->count())->toBe(0);

    Http::get("https://api.x.ai/v1/videos/{$requestId}");
    Http::get("https://api.x.ai/v1/videos/{$requestId}");

    $row = SpectraRequest::query()->sole();

    // 8,400,000,000 ticks = 84 cents (6 s at the 720p rate of 14 cents/s).
    expect($row->provider)->toBe('xai')
        ->and($row->model)->toBe('Grok Imagine Video 1.5')
        ->and($row->model_type)->toBe('video')
        ->and($row->response_id)->toBe($requestId)
        ->and($row->video_count)->toBe(1)
        ->and((float) $row->duration_seconds)->toBe(6.0)
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(84.0, 1e-9);
});

it('estimates a video per second when no cost is reported', function () {
    $body = $this->loadMockResponse('xai/video-done.json');
    unset($body['usage']);
    Http::fake(['api.x.ai/*' => Http::response($body)]);

    Http::get('https://api.x.ai/v1/videos/req-123');

    // grok-imagine-video-1.5 standard (480p): 6 s × 8 cents = 48 cents.
    expect((float) SpectraRequest::query()->sole()->total_cost_in_cents)->toEqualWithDelta(48.0, 1e-9);
});

it('tracks image generation with the reported cost', function () {
    Http::fake(['api.x.ai/*' => Http::response($this->loadMockResponse('xai/image.json'))]);

    Http::post('https://api.x.ai/v1/images/generations', ['model' => 'grok-imagine-image-2.0', 'prompt' => 'A collage of London landmarks', 'n' => 2, 'resolution' => '2k']);

    $row = SpectraRequest::query()->sole();

    // 1,600,000,000 ticks = 16 cents (2 images at 2K medium, 8 cents each).
    expect($row->provider)->toBe('xai')
        ->and($row->model)->toBe('Grok Imagine Image 2.0')
        ->and($row->model_type)->toBe('image')
        ->and($row->image_count)->toBe(2)
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(16.0, 1e-9);
});

it('records a deferred chat completion when its result is fetched', function () {
    Http::fake([
        'api.x.ai/v1/chat/completions' => Http::response(['request_id' => 'deferred-1']),
        'api.x.ai/v1/chat/deferred-completion/*' => Http::sequence()
            ->push('', 202)
            ->push($this->loadMockResponse('xai/chat-reasoning.json')),
    ]);

    Http::post('https://api.x.ai/v1/chat/completions', ['model' => 'grok-4.3', 'deferred' => true, 'messages' => []]);
    Http::get('https://api.x.ai/v1/chat/deferred-completion/deferred-1');

    expect(SpectraRequest::query()->count())->toBe(0);

    Http::get('https://api.x.ai/v1/chat/deferred-completion/deferred-1');

    $row = SpectraRequest::query()->sole();

    expect($row->model)->toBe('Grok 4.3')
        ->and($row->completion_tokens)->toBe(103)
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(0.02912, 1e-9);
});
