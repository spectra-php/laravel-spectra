<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Spectra\Models\SpectraRequest;

/**
 * `Http::withAITracking()` and `Http::withoutAITracking()` end to end through
 * Laravel's HTTP client with the HttpWatcher installed as global middleware:
 * a host Spectra does not know is recorded under the given provider with the
 * macro's options, a known host is recorded once, an opted-out request never,
 * the macros keep what was set on the pending request before them, and a
 * stream is recorded once its body has been read.
 */
it('records a request to an unknown host made with withAITracking() under the given provider', function () {
    Http::fake(['ai-proxy.example.test/*' => Http::response($this->loadMockResponse('openai/completion.json'))]);

    Http::withAITracking('openai')->post('https://ai-proxy.example.test/v1/chat/completions', [
        'model' => 'gpt-4.1',
        'messages' => [['role' => 'user', 'content' => 'Hello']],
    ]);

    $record = SpectraRequest::query()->sole();

    // gpt-4.1 standard (in 200, out 800): (19×200 + 10×800) / 1M = 0.0118¢
    expect($record->provider)->toBe('openai')
        ->and($record->model)->toBe('GPT-4.1')
        ->and($record->endpoint)->toBe('/v1/chat/completions')
        ->and($record->prompt_tokens)->toBe(19)
        ->and($record->completion_tokens)->toBe(10)
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.0118, 1e-9);
});

it('stores the tags, metadata and pricing tier given to withAITracking() and bills at that tier', function () {
    Http::fake(['ai-proxy.example.test/*' => Http::response($this->loadMockResponse('openai/completion.json'))]);

    Http::withAITracking('openai', 'gpt-4.1', [
        'tags' => ['proxy', 'nightly-digest'],
        'metadata' => ['tenant_id' => 'acme', 'feature' => 'digest'],
        'pricing_tier' => 'batch',
    ])->post('https://ai-proxy.example.test/v1/chat/completions', ['model' => 'gpt-4.1', 'messages' => []]);

    $record = SpectraRequest::query()->sole();

    // gpt-4.1 batch (in 100, out 400): (19×100 + 10×400) / 1M = 0.0059¢
    expect($record->tags->pluck('name')->all())->toEqualCanonicalizing(['proxy', 'nightly-digest'])
        ->and($record->metadata)->toEqual(['tenant_id' => 'acme', 'feature' => 'digest'])
        ->and($record->pricing_tier)->toBe('batch')
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.0059, 1e-9);
});

it('records a withAITracking() request to a known host once, through the macro alone', function () {
    Http::fake(['api.openai.com/*' => Http::response($this->loadMockResponse('openai/completion.json'))]);

    Http::withAITracking('openai', 'gpt-4.1', ['tags' => ['manual']])
        ->post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4.1', 'messages' => []]);

    $records = SpectraRequest::query()->get();

    // The tag only reaches the row through the macro's middleware, so it
    // proves the global middleware left the request alone.
    expect($records)->toHaveCount(1)
        ->and($records->first()->tags->pluck('name')->all())->toBe(['manual']);
});

it('records nothing for a known host called with withoutAITracking()', function () {
    Http::fake(['api.openai.com/*' => Http::response($this->loadMockResponse('openai/completion.json'))]);

    $response = Http::withoutAITracking()
        ->post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4.1', 'messages' => []]);

    expect($response->json('usage.prompt_tokens'))->toBe(19)
        ->and(SpectraRequest::query()->count())->toBe(0);
});

it('keeps the token and headers set before withAITracking() and withoutAITracking() on the request', function () {
    Http::fake([
        'ai-proxy.example.test/*' => Http::response($this->loadMockResponse('openai/completion.json')),
        'api.openai.com/*' => Http::response($this->loadMockResponse('openai/completion.json')),
    ]);

    Http::withToken('sk-test-proxy-token')->withAITracking('openai', 'gpt-4.1')
        ->post('https://ai-proxy.example.test/v1/chat/completions', ['model' => 'gpt-4.1', 'messages' => []]);
    Http::withHeaders(['X-Request-Id' => 'req-42'])->withoutAITracking()
        ->post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4.1', 'messages' => []]);

    $record = SpectraRequest::query()->sole();

    expect(Http::recorded(fn (Request $request) => $request->url() === 'https://ai-proxy.example.test/v1/chat/completions'
        && $request->hasHeader('Authorization', 'Bearer sk-test-proxy-token')))->toHaveCount(1)
        ->and(Http::recorded(fn (Request $request) => $request->url() === 'https://api.openai.com/v1/chat/completions'
            && $request->hasHeader('X-Request-Id', 'req-42')))->toHaveCount(1)
        ->and($record->provider)->toBe('openai')
        ->and($record->prompt_tokens)->toBe(19);
});

it('records a stream sent through withAITracking() once its body has been read', function () {
    $body = File::get(__DIR__.'/../../responses/openai/chat_stream_with_usage.sse');
    Http::fake(['ai-proxy.example.test/*' => Http::response($body, 200, ['Content-Type' => 'text/event-stream'])]);

    $response = Http::withAITracking('openai', 'gpt-5.4-mini', ['metadata' => ['feature' => 'live-chat']])
        ->withOptions(['stream' => true])
        ->post('https://ai-proxy.example.test/v1/chat/completions', [
            'model' => 'gpt-5.4-mini',
            'stream' => true,
            'stream_options' => ['include_usage' => true],
            'messages' => [['role' => 'user', 'content' => 'Hi']],
        ]);

    expect(SpectraRequest::query()->count())->toBe(0);

    $read = $response->body();

    $record = SpectraRequest::query()->sole();

    // Served at service_tier "flex": gpt-5.4-mini flex in 37.5, cached 3.75, out 225.
    // (1000×37.5 + 2000×3.75 + 400×225) / 1M = 0.135¢
    expect($read)->toBe($body)
        ->and($record->provider)->toBe('openai')
        ->and($record->is_streaming)->toBeTrue()
        ->and($record->metadata)->toEqual(['feature' => 'live-chat'])
        ->and($record->pricing_tier)->toBe('flex')
        ->and($record->prompt_tokens)->toBe(3000)
        ->and($record->completion_tokens)->toBe(400)
        ->and($record->cached_tokens)->toBe(2000)
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.135, 1e-9);
});
