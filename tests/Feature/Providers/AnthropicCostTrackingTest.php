<?php

declare(strict_types=1);

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;
use Spectra\Providers\Anthropic\Anthropic;

/**
 * Anthropic cost tracking, end to end. Requests go through Laravel's HTTP
 * client, so the global watcher records them as in a real app. Expected
 * costs are worked out by hand from AnthropicPricing (US cents per 1M
 * tokens).
 */
function anthropicStream(string $name): string
{
    return File::get(__DIR__.'/../../responses/claude/streams/'.$name);
}

function fakeAnthropic(array|string $body, array $headers = []): void
{
    Http::fake(['api.anthropic.com/*' => Http::response($body, 200, $headers)]);
}

function postAnthropic(array $request): Response
{
    return Http::withHeaders(['x-api-key' => 'test-key', 'anthropic-version' => '2023-06-01'])
        ->post('https://api.anthropic.com/v1/messages', $request + [
            'max_tokens' => 1024,
            'messages' => [['role' => 'user', 'content' => 'Hello']],
        ]);
}

it('prices cache reads and 5-minute and 1-hour cache writes separately', function () {
    fakeAnthropic($this->loadMockResponse('claude/message-prompt-caching.json'));

    postAnthropic(['model' => 'claude-opus-4-8']);

    $row = SpectraRequest::query()->sole();

    // Opus 4.8: $5 in, $25 out, $0.50 cache read, $6.25 5m write, $10 1h write.
    // 2048×500 + 1800×50 + 148×625 + 100×1000 + 503×2500 = 2,564,000 / 1M = 2.564¢
    expect($row->model)->toBe('Claude Opus 4.8')
        ->and($row->prompt_tokens)->toBe(4096) // 2048 uncached + 1800 read + 248 written
        ->and($row->completion_tokens)->toBe(503)
        ->and($row->cached_tokens)->toBe(1800)
        ->and($row->cache_write_tokens)->toBe(248)
        ->and($row->pricing_tier)->toBe('standard')
        ->and($row->total_cost_in_cents)->toEqualWithDelta(2.564, 0.000001);
});

it('prices fast mode with the fast-mode cache rates and the US-only inference surcharge', function () {
    fakeAnthropic($this->loadMockResponse('claude/message-fast-mode-us.json'));

    postAnthropic(['model' => 'claude-opus-5-5', 'speed' => 'fast', 'inference_geo' => 'us']);

    $row = SpectraRequest::query()->sole();

    // Opus 5.5 fast: $8 in, $40 out, $0.40 cache read, $16 1h write.
    // (1000×800 + 4000×40 + 2000×1600 + 500×4000) / 1M = 6.16¢, × 1.1 (US-only) = 6.776¢
    expect($row->model)->toBe('Claude Opus 5.5')
        ->and($row->pricing_tier)->toBe('fast')
        ->and($row->prompt_tokens)->toBe(7000)
        ->and($row->cached_tokens)->toBe(4000)
        ->and($row->cache_write_tokens)->toBe(2000)
        ->and($row->total_cost_in_cents)->toEqualWithDelta(6.776, 0.000001);
});

it('bills web searches per search and web fetches for free', function () {
    fakeAnthropic($this->loadMockResponse('claude/message-web-search.json'));

    postAnthropic(['model' => 'claude-sonnet-5-5', 'tools' => [
        ['type' => 'web_search_20260209', 'name' => 'web_search'],
        ['type' => 'web_fetch_20260209', 'name' => 'web_fetch'],
    ]]);

    $row = SpectraRequest::query()->sole();

    // Sonnet 5.5: $2 in, $10 out → (18240×200 + 812×1000) / 1M = 4.46¢
    // plus 2 searches × 1¢ ($10 per 1,000); the fetch is free.
    expect($row->model)->toBe('Claude Sonnet 5.5')
        ->and($row->has_tool_calls)->toBeTrue()
        ->and($row->tool_call_counts)->toEqual(['web_search_requests' => 2, 'web_fetch_requests' => 1])
        ->and($row->total_cost_in_cents)->toEqualWithDelta(6.46, 0.000001);
});

it('prices Claude Haiku 5.5 prompts over 100,000 tokens at the long-context rates', function () {
    fakeAnthropic($this->loadMockResponse('claude/message-haiku-5-5-long-context.json'));

    postAnthropic(['model' => 'claude-haiku-5-5']);

    $row = SpectraRequest::query()->sole();

    // 90,000 uncached + 20,000 cache reads = 110,000 > 100,000, so the whole
    // request pays $0.50 in, $2.50 out, $0.05 cache read:
    // (90000×50 + 20000×5 + 1000×250) / 1M = 4.85¢
    expect($row->prompt_tokens)->toBe(110_000)
        ->and($row->cached_tokens)->toBe(20_000)
        ->and($row->total_cost_in_cents)->toEqualWithDelta(4.85, 0.000001);
});

it('keeps Claude Haiku 5.5 prompts up to 100,000 tokens at the base rates', function () {
    $response = $this->loadMockResponse('claude/message-haiku-5-5-long-context.json');
    $response['usage']['input_tokens'] = 80_000;
    fakeAnthropic($response);

    postAnthropic(['model' => 'claude-haiku-5-5']);

    // (80000×10 + 20000×1 + 1000×50) / 1M = 0.87¢
    expect(SpectraRequest::query()->sole()->total_cost_in_cents)->toEqualWithDelta(0.87, 0.000001);
});

it('adds the compaction iteration the top-level usage leaves out', function () {
    fakeAnthropic($this->loadMockResponse('claude/message-compaction.json'));

    postAnthropic(['model' => 'claude-opus-5-5']);

    $row = SpectraRequest::query()->sole();

    // Billed: 180,000 + 23,000 input and 3,500 + 1,000 output on Opus 5.5 ($4 / $20):
    // (203000×400 + 4500×2000) / 1M = 90.2¢
    expect($row->prompt_tokens)->toBe(203_000)
        ->and($row->completion_tokens)->toBe(4_500)
        ->and($row->total_cost_in_cents)->toEqualWithDelta(90.2, 0.000001);
});

it('records priority tier and bills it at standard prices', function () {
    fakeAnthropic($this->loadMockResponse('claude/message-priority-tier.json'));

    postAnthropic(['model' => 'claude-sonnet-4-6', 'service_tier' => 'auto']);

    $row = SpectraRequest::query()->sole();

    // Priority Tier is a capacity contract; the catalog has no separate
    // prices, so Sonnet 4.6 standard: (410×300 + 585×1500) / 1M = 1.0005¢
    expect($row->pricing_tier)->toBe('priority')
        ->and($row->total_cost_in_cents)->toEqualWithDelta(1.0005, 0.000001);
});

it('ignores the configured default tier when the response says standard', function () {
    config()->set('spectra.costs.provider_settings.anthropic.default_tier', 'batch');
    fakeAnthropic($this->loadMockResponse('claude/message-prompt-caching.json'));

    postAnthropic(['model' => 'claude-opus-4-8']);

    // Opus 4.8 standard, as in the prompt-caching test: 2.564¢
    expect(SpectraRequest::query()->sole()->pricing_tier)->toBe('standard')
        ->and(SpectraRequest::query()->sole()->total_cost_in_cents)->toEqualWithDelta(2.564, 0.000001);
});

it('falls back to the requested fast mode when the response does not say', function () {
    $response = $this->loadMockResponse('claude/message-fast-mode-us.json');
    unset($response['usage']['speed'], $response['usage']['service_tier'], $response['usage']['inference_geo']);
    fakeAnthropic($response);

    postAnthropic(['model' => 'claude-opus-5-5', 'speed' => 'fast']);

    // Same tokens as the fast-mode test without the US surcharge: 6.16¢
    expect(SpectraRequest::query()->sole()->pricing_tier)->toBe('fast')
        ->and(SpectraRequest::query()->sole()->total_cost_in_cents)->toEqualWithDelta(6.16, 0.000001);
});

it('bills standard speed when fast mode was requested but not served', function () {
    $response = $this->loadMockResponse('claude/message-fast-mode-us.json');
    $response['model'] = 'claude-opus-4-6';
    $response['usage']['speed'] = 'standard';
    unset($response['usage']['inference_geo']);
    fakeAnthropic($response);

    // Claude Opus 4.6 accepts `speed: fast` but runs (and bills) at standard speed.
    postAnthropic(['model' => 'claude-opus-4-6', 'speed' => 'fast']);

    // Opus 4.6: (1000×500 + 4000×50 + 2000×1000 + 500×2500) / 1M = 3.95¢
    expect(SpectraRequest::query()->sole()->pricing_tier)->toBe('standard')
        ->and(SpectraRequest::query()->sole()->total_cost_in_cents)->toEqualWithDelta(3.95, 0.000001);
});

it('lets an explicitly chosen tier win over the response', function () {
    fakeAnthropic($this->loadMockResponse('claude/message-prompt-caching.json'));
    Spectra::withPricingTier('batch');

    postAnthropic(['model' => 'claude-opus-4-8']);

    // Opus 4.8 batch: (2048×250 + 1800×25 + 148×312.5 + 100×500 + 503×1250) / 1M = 1.282¢
    expect(SpectraRequest::query()->sole()->pricing_tier)->toBe('batch')
        ->and(SpectraRequest::query()->sole()->total_cost_in_cents)->toEqualWithDelta(1.282, 0.000001);
});

it('prices a batch result message at batch rates', function () {
    $message = $this->loadMockResponse('claude/message-batch-result.jsonl')[0]['result']['message'];

    Spectra::track('anthropic', $message['model'], fn () => $message);

    $row = SpectraRequest::query()->sole();

    // Sonnet 4.6 batch: $1.50 in, $7.50 out, $0.15 cache read:
    // (1200×150 + 800×15 + 40×750) / 1M = 0.222¢
    expect($row->pricing_tier)->toBe('batch')
        ->and($row->prompt_tokens)->toBe(2000)
        ->and($row->total_cost_in_cents)->toEqualWithDelta(0.222, 0.000001);
});

/*
|--------------------------------------------------------------------------
| Streaming
|--------------------------------------------------------------------------
*/

it('takes the cumulative usage of message_delta over message_start in a stream', function () {
    fakeAnthropic(anthropicStream('web-search.txt'), ['Content-Type' => 'text/event-stream']);

    $response = postAnthropic(['model' => 'claude-opus-4-8', 'stream' => true]);

    expect(SpectraRequest::query()->count())->toBe(0);

    $response->body();

    $row = SpectraRequest::query()->sole();

    // message_start said 2,679 input tokens; the final delta says 10,682
    // (the search results were added). Opus 4.8: (10682×500 + 510×2500) / 1M
    // = 6.616¢, plus one web search at 1¢.
    expect($row->is_streaming)->toBeTrue()
        ->and($row->model)->toBe('Claude Opus 4.8')
        ->and($row->response_id)->toBe('msg_01StrmWebSrch7Lq2Vx9Kd3Ma')
        ->and($row->prompt_tokens)->toBe(10682)
        ->and($row->completion_tokens)->toBe(510)
        ->and($row->tool_call_counts)->toEqual(['web_search_requests' => 1])
        ->and($row->pricing_tier)->toBe('standard')
        ->and($row->finish_reason)->toBe('end_turn')
        ->and($row->total_cost_in_cents)->toEqualWithDelta(7.616, 0.000001);
});

it('reads fast mode, US-only inference and cache usage from a stream', function () {
    fakeAnthropic(anthropicStream('fast-mode-us.txt'), ['Content-Type' => 'text/event-stream']);

    postAnthropic(['model' => 'claude-opus-5-5', 'stream' => true, 'speed' => 'fast', 'inference_geo' => 'us'])->body();

    $row = SpectraRequest::query()->sole();

    // Same usage as the non-streamed fast-mode test: 6.16¢ × 1.1 = 6.776¢.
    // The cache counts come from message_start; the delta only updates output.
    expect($row->pricing_tier)->toBe('fast')
        ->and($row->prompt_tokens)->toBe(7000)
        ->and($row->completion_tokens)->toBe(500)
        ->and($row->cached_tokens)->toBe(4000)
        ->and($row->cache_write_tokens)->toBe(2000)
        ->and($row->total_cost_in_cents)->toEqualWithDelta(6.776, 0.000001);
});

it('uses the requested tier for a stream that does not report one', function () {
    $stream = Str::replace([',"service_tier":"standard"', ',"speed":"fast"', ',"inference_geo":"us"'], '', anthropicStream('fast-mode-us.txt'));
    fakeAnthropic($stream, ['Content-Type' => 'text/event-stream']);

    postAnthropic(['model' => 'claude-opus-5-5', 'stream' => true, 'speed' => 'fast'])->body();

    // Same tokens as the fast-mode test without the US surcharge: 6.16¢
    expect(SpectraRequest::query()->sole()->pricing_tier)->toBe('fast')
        ->and(SpectraRequest::query()->sole()->total_cost_in_cents)->toEqualWithDelta(6.16, 0.000001);
});

/*
|--------------------------------------------------------------------------
| Provider contracts
|--------------------------------------------------------------------------
*/

it('maps the usage an anthropic response reports to a pricing tier', function (array $usage, ?string $tier) {
    expect((new Anthropic)->extractPricingTierFromResponse(['usage' => $usage]))->toBe($tier);
})->with([
    'fast mode' => [['speed' => 'fast', 'service_tier' => 'standard'], 'fast'],
    'priority' => [['service_tier' => 'priority'], 'priority'],
    'batch' => [['service_tier' => 'batch'], 'batch'],
    'standard' => [['service_tier' => 'standard'], 'standard'],
    'standard speed only' => [['speed' => 'standard'], 'standard'],
    'nothing reported' => [['input_tokens' => 1], null],
]);

it('maps the speed and service tier a request asks for to a pricing tier', function (array $request, ?string $tier) {
    expect((new Anthropic)->extractPricingTierFromRequest($request))->toBe($tier);
})->with([
    'fast mode' => [['speed' => 'fast'], 'fast'],
    'standard only' => [['service_tier' => 'standard_only'], 'standard'],
    'auto' => [['service_tier' => 'auto'], null],
]);

it('applies the US-only inference multiplier only when the response reports it', function () {
    $provider = new Anthropic;

    expect($provider->extractCostMultiplier(['usage' => ['inference_geo' => 'us']], []))->toBe(1.1)
        ->and($provider->extractCostMultiplier(['usage' => ['inference_geo' => 'global']], ['inference_geo' => 'us']))->toBeNull()
        ->and($provider->extractCostMultiplier(['usage' => ['input_tokens' => 5]], []))->toBeNull();
});
