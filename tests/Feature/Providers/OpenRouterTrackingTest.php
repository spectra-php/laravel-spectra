<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Spectra\Facades\Spectra;
use Spectra\Models\SpectraDailyStat;
use Spectra\Models\SpectraRequest;

/**
 * OpenRouter requests through the HTTP client: the cost OpenRouter reports
 * wins over the catalog, with fallbacks to catalog prices, and chat, image,
 * video and embedding calls are each recorded once.
 */
it('records a tracked openrouter chat response with its model, tokens and cost', function () {
    $response = $this->loadMockResponse('openrouter/chat.json');

    Spectra::track('openrouter', 'openai/gpt-5-mini', fn () => $response);

    $record = SpectraRequest::query()->sole();

    // GPT-5 Mini catalog (in 25, out 200): (25×25 + 15×200) / 1M = 0.003625¢
    expect($record->provider)->toBe('openrouter')
        ->and($record->model)->toBe('GPT-5 Mini')
        ->and($record->model_type)->toBe('text')
        ->and($record->prompt_tokens)->toBe(25)
        ->and($record->completion_tokens)->toBe(15)
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.003625, 1e-9);
});

it('resolves image responses to the image handler, not the chat handler', function () {
    // Regression: both OpenRouter handlers match /api/v1/chat/completions, and
    // the generic ChatHandler accepts any choices+usage body — with the wrong
    // registration order it swallowed image responses.
    $response = $this->loadMockResponse('openrouter/image.json');

    Spectra::track('openrouter', 'google/gemini-2.5-flash-image-preview', fn () => $response);

    $record = SpectraRequest::query()->sole();

    // Gemini 2.5 Flash Image Preview: 3 cents per image × 2 images = 6 cents
    expect($record->model_type)->toBe('image')
        ->and($record->image_count)->toBe(2)
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(6.0, 1e-9);
});

it('records the cost openrouter reports, with cache reads, cache writes and reasoning', function () {
    Http::fake(['openrouter.ai/*' => Http::response($this->loadMockResponse('openrouter/chat-cache.json'))]);

    Http::post('https://openrouter.ai/api/v1/chat/completions', [
        'model' => 'anthropic/claude-sonnet-4.5',
        'messages' => [['role' => 'user', 'content' => 'When does the contract renew?']],
    ]);

    $record = SpectraRequest::query()->sole();

    // usage.cost = $0.0249 → 2.49 cents. The catalog agrees: 2,000 uncached × 300
    // + 8,000 cache reads × 30 + 2,000 cache writes × 375 + 600 output × 1500,
    // all per 1M tokens = 2.49 cents.
    expect($record->provider)->toBe('openrouter')
        ->and($record->model)->toBe('Claude Sonnet 4.5')
        ->and($record->model_type)->toBe('text')
        ->and($record->response_id)->toBe('gen-1760000000-9fKq2LmZpX4rTt8YvWcB')
        ->and($record->prompt_tokens)->toBe(12000)
        ->and($record->completion_tokens)->toBe(600)
        ->and($record->cached_tokens)->toBe(8000)
        ->and($record->cache_write_tokens)->toBe(2000)
        ->and($record->reasoning_tokens)->toBe(200)
        ->and($record->pricing_tier)->toBe('standard')
        ->and($record->total_cost_in_cents)->toEqualWithDelta(2.49, 0.000001);
});

it('falls back to the catalog price when the response has no cost', function () {
    $body = $this->loadMockResponse('openrouter/chat-cache.json');
    unset($body['usage']['cost'], $body['usage']['cost_details'], $body['usage']['is_byok']);

    Http::fake(['openrouter.ai/*' => Http::response($body)]);

    Http::post('https://openrouter.ai/api/v1/chat/completions', ['model' => 'anthropic/claude-sonnet-4.5', 'messages' => []]);

    // (2,000 × 300 + 8,000 × 30 + 2,000 × 375 + 600 × 1500) / 1M = 2.49 cents
    expect(SpectraRequest::query()->sole()->total_cost_in_cents)->toEqualWithDelta(2.49, 0.000001);
});

it('adds the upstream provider cost for bring-your-own-key requests', function () {
    Http::fake(['openrouter.ai/*' => Http::response($this->loadMockResponse('openrouter/chat-byok.json'))]);

    Http::post('https://openrouter.ai/api/v1/chat/completions', ['model' => 'openai/gpt-5-mini', 'messages' => []]);

    $record = SpectraRequest::query()->sole();

    // OpenRouter fee $0.00005 + upstream_inference_cost $0.001 = $0.00105 → 0.105 cents
    expect($record->total_cost_in_cents)->toEqualWithDelta(0.105, 0.000001)
        ->and($record->prompt_tokens)->toBe(2000)
        ->and($record->completion_tokens)->toBe(250)
        ->and($record->reasoning_tokens)->toBe(128);
});

it('keeps openrouter/auto as the model and the served model as the snapshot', function () {
    Http::fake(['openrouter.ai/*' => Http::response($this->loadMockResponse('openrouter/chat-auto.json'))]);

    Http::post('https://openrouter.ai/api/v1/chat/completions', ['model' => 'openrouter/auto', 'messages' => []]);

    $record = SpectraRequest::query()->sole();

    // usage.cost = $0.02223 (tokens plus the web search) → 2.223 cents
    expect($record->model)->toBe('openrouter/auto')
        ->and($record->snapshot)->toBe('google/gemini-2.5-flash')
        ->and($record->total_cost_in_cents)->toEqualWithDelta(2.223, 0.000001)
        ->and($record->cached_tokens)->toBe(1000)
        ->and($record->has_tool_calls)->toBeTrue()
        ->and($record->tool_call_counts)->toBe(['web_search' => 1]);
});

it('prices the served model when openrouter/auto reports no cost', function () {
    $body = $this->loadMockResponse('openrouter/chat-auto.json');
    unset($body['usage']['cost']);

    Http::fake(['openrouter.ai/*' => Http::response($body)]);

    Http::post('https://openrouter.ai/api/v1/chat/completions', ['model' => 'openrouter/auto', 'messages' => []]);

    // Gemini 2.5 Flash: (4,000 × 30 + 1,000 cached × 3 + 400 × 250) / 1M = 0.223 cents
    expect(SpectraRequest::query()->sole()->total_cost_in_cents)->toEqualWithDelta(0.223, 0.000001);
});

it('records a streamed chat completion with the cost from the last chunk', function () {
    $sse = file_get_contents(__DIR__.'/../../responses/openrouter/chat-stream.txt');

    Http::fake(['openrouter.ai/*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);

    $response = Http::post('https://openrouter.ai/api/v1/chat/completions', [
        'model' => 'anthropic/claude-sonnet-4.5',
        'stream' => true,
        'messages' => [['role' => 'user', 'content' => 'Hi']],
    ]);

    expect($response->body())->toBe($sse);

    $record = SpectraRequest::query()->sole();

    // usage.cost = $0.0042 → 0.42 cents; catalog: (1,000 × 300 + 2,000 × 30 + 40 × 1500) / 1M
    expect($record->is_streaming)->toBeTrue()
        ->and($record->response_id)->toBe('gen-1760000500-Lm4kP8qRsT2vWxYz6aBc')
        ->and($record->prompt_tokens)->toBe(3000)
        ->and($record->completion_tokens)->toBe(40)
        ->and($record->cached_tokens)->toBe(2000)
        ->and($record->finish_reason)->toBe('stop')
        ->and($record->total_cost_in_cents)->toEqualWithDelta(0.42, 0.000001);
});

it('records the image api with its image count and reported cost', function () {
    Http::fake(['openrouter.ai/*' => Http::response($this->loadMockResponse('openrouter/images.json'))]);

    Http::post('https://openrouter.ai/api/v1/images', [
        'model' => 'google/gemini-2.5-flash-image',
        'prompt' => 'a red panda astronaut floating in space',
    ]);

    $record = SpectraRequest::query()->sole();

    // usage.cost = $0.0387036 → 3.87036 cents; catalog: (12 × 30 + 1,290 image tokens × 3000) / 1M
    expect($record->model)->toBe('Gemini 2.5 Flash Image')
        ->and($record->model_type)->toBe('image')
        ->and($record->image_count)->toBe(1)
        ->and($record->completion_tokens)->toBe(1290)
        ->and($record->total_cost_in_cents)->toEqualWithDelta(3.87036, 0.000001)
        ->and($record->response['data'][0]['b64_json'])->toBe('[stripped]');
});

it('prices image output tokens at the image rate when the image api reports no cost', function () {
    $body = $this->loadMockResponse('openrouter/images.json');
    unset($body['usage']['cost'], $body['usage']['cost_details']);

    Http::fake(['openrouter.ai/*' => Http::response($body)]);

    Http::post('https://openrouter.ai/api/v1/images', ['model' => 'google/gemini-2.5-flash-image', 'prompt' => 'x']);

    // (12 × 30 + 1,290 × 3000) / 1M = 3.87036 cents
    expect(SpectraRequest::query()->sole()->total_cost_in_cents)->toEqualWithDelta(3.87036, 0.000001);
});

it('records a video job once, when it finishes, however often it is polled', function () {
    $job = 'gen-vid-1760000400-Ab3dEf9hIjKlMnOpQrSt';

    Http::fake([
        'openrouter.ai/api/v1/videos' => Http::response($this->loadMockResponse('openrouter/video-pending.json'), 202),
        'openrouter.ai/api/v1/videos/*' => Http::sequence()
            ->push($this->loadMockResponse('openrouter/video-in-progress.json'))
            ->push($this->loadMockResponse('openrouter/video-completed.json'))
            ->push($this->loadMockResponse('openrouter/video-completed.json')),
    ]);

    Http::post('https://openrouter.ai/api/v1/videos', ['model' => 'google/veo-3.1', 'prompt' => 'A serene lake', 'duration' => 8]);
    Http::get("https://openrouter.ai/api/v1/videos/{$job}");

    expect(SpectraRequest::query()->count())->toBe(0);

    Http::get("https://openrouter.ai/api/v1/videos/{$job}");
    Http::get("https://openrouter.ai/api/v1/videos/{$job}");

    $record = SpectraRequest::query()->sole();

    // usage.cost = $0.40 → 40 cents
    expect($record->response_id)->toBe($job)
        ->and($record->model_type)->toBe('video')
        ->and($record->video_count)->toBe(1)
        ->and($record->finish_reason)->toBe('completed')
        ->and($record->total_cost_in_cents)->toEqualWithDelta(40.0, 0.000001)
        ->and((int) SpectraDailyStat::query()->sum('request_count'))->toBe(1)
        ->and((float) SpectraDailyStat::query()->sum('total_cost_in_cents'))->toEqualWithDelta(40.0, 0.000001);
});

it('records a streamed image api call from its completed event', function () {
    $sse = file_get_contents(__DIR__.'/../../responses/openrouter/images-stream.txt');

    Http::fake(['openrouter.ai/*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);

    Http::post('https://openrouter.ai/api/v1/images', ['model' => 'openai/gpt-image-1', 'prompt' => 'a lighthouse', 'stream' => true])->body();

    $record = SpectraRequest::query()->sole();

    // usage.cost = $0.16651 → 16.651 cents; catalog: (20 × 500 + 4,160 image tokens × 4000) / 1M = 16.65
    expect($record->is_streaming)->toBeTrue()
        ->and($record->model)->toBe('GPT Image 1')
        ->and($record->model_type)->toBe('image')
        ->and($record->completion_tokens)->toBe(4160)
        ->and($record->total_cost_in_cents)->toEqualWithDelta(16.651, 0.000001);
});

it('records openrouter embeddings with the cost openrouter reports', function () {
    Http::fake(['openrouter.ai/*' => Http::response($this->loadMockResponse('openrouter/embeddings.json'))]);

    Http::post('https://openrouter.ai/api/v1/embeddings', [
        'model' => 'qwen/qwen3-embedding-8b',
        'input' => ['When does the contract renew?', 'Invoices are sent monthly.'],
    ]);

    $record = SpectraRequest::query()->sole();

    // usage.cost = $0.000012 → 0.0012 cents
    expect($record->provider)->toBe('openrouter')
        ->and($record->model_type)->toBe('embedding')
        ->and($record->endpoint)->toBe('/api/v1/embeddings')
        ->and($record->prompt_tokens)->toBe(1200)
        ->and($record->completion_tokens)->toBe(0)
        ->and($record->total_cost_in_cents)->toEqualWithDelta(0.0012, 0.0000001);
});

it('records openrouter reranks with the cost openrouter reports', function () {
    Http::fake(['openrouter.ai/*' => Http::response($this->loadMockResponse('openrouter/rerank.json'))]);

    Http::post('https://openrouter.ai/api/v1/rerank', [
        'model' => 'cohere/rerank-4-pro',
        'query' => 'When does the contract renew?',
        'documents' => ['Invoices are sent monthly.', 'The office is closed on Sundays.', 'The contract renews every January.'],
        'top_n' => 3,
    ]);

    $record = SpectraRequest::query()->sole();

    // usage.cost = $0.0025 → 0.25 cents
    expect($record->provider)->toBe('openrouter')
        ->and($record->endpoint)->toBe('/api/v1/rerank')
        ->and($record->model)->toBe('cohere/rerank-4-pro')
        ->and($record->total_cost_in_cents)->toEqualWithDelta(0.25, 0.0000001);
});
