<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Pricing\PricingLookup;

/*
|--------------------------------------------------------------------------
| OpenAI text tracking through the HTTP client
|--------------------------------------------------------------------------
|
| Real wire-format responses, faked with Http::fake() and sent with the
| HTTP client, so they go through the global tracking middleware like a
| real app. Costs are worked out by hand from OpenAIPricing (cents per 1M
| tokens) and asserted exactly.
|
*/

it('bills chat completion cache reads and writes, and lets the served tier win over the requested one', function () {
    Http::fake(['api.openai.com/*' => Http::response($this->loadMockResponse('openai/chat_completion_cache_writes.json'))]);

    // The request asks for priority, but OpenAI answers service_tier "default".
    Http::post('https://api.openai.com/v1/chat/completions', [
        'model' => 'gpt-5.6-sol',
        'service_tier' => 'priority',
        'messages' => [['role' => 'user', 'content' => 'When does the contract renew?']],
    ]);

    $row = SpectraRequest::sole();

    // gpt-5.6-sol standard: in 400, cached 40, cache write 500, out 2000.
    // Uncached input = 12000 - 8000 cached - 3000 written = 1000.
    // (1000*400 + 8000*40 + 3000*500 + 900*2000) / 1M = 4.02
    expect($row->model)->toBe('GPT-5.6 Sol')
        ->and($row->pricing_tier)->toBe('standard')
        ->and($row->prompt_tokens)->toBe(12000)
        ->and($row->completion_tokens)->toBe(900)
        ->and($row->cached_tokens)->toBe(8000)
        ->and($row->cache_write_tokens)->toBe(3000)
        ->and($row->reasoning_tokens)->toBe(600)
        ->and($row->finish_reason)->toBe('stop')
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(4.02, 1e-9);
});

it('uses the tier from the response when the request named none', function () {
    Http::fake(['api.openai.com/*' => Http::response($this->loadMockResponse('openai/response_cache_writes_priority.json'))]);

    Http::post('https://api.openai.com/v1/responses', ['model' => 'gpt-5.6-sol', 'input' => 'Check the contract.']);

    $row = SpectraRequest::sole();

    // gpt-5.6-sol priority (Fast): in 800, cached 80, cache write 1000, out 4000.
    // Uncached input = 20000 - 15000 - 4000 = 1000; reasoning is inside the 1500 output.
    // (1000*800 + 15000*80 + 4000*1000 + 1500*4000) / 1M = 12.0
    expect($row->pricing_tier)->toBe('priority')
        ->and($row->response_id)->toBe('resp_0a1b2c3d4e5f60718293a4b5c6d7e8f9')
        ->and($row->prompt_tokens)->toBe(20000)
        ->and($row->completion_tokens)->toBe(1500)
        ->and($row->cached_tokens)->toBe(15000)
        ->and($row->cache_write_tokens)->toBe(4000)
        ->and($row->reasoning_tokens)->toBe(1000)
        ->and($row->reasoning_effort)->toBe('medium')
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(12.0, 1e-9);
});

it('maps the fast service tier to the priority tier', function () {
    $response = $this->loadMockResponse('openai/response_cache_writes_priority.json');
    $response['service_tier'] = 'fast';
    Http::fake(['api.openai.com/*' => Http::response($response)]);

    Http::post('https://api.openai.com/v1/responses', ['model' => 'gpt-5.6-sol', 'service_tier' => 'fast', 'input' => 'x']);

    expect(SpectraRequest::sole()->pricing_tier)->toBe('priority');
});

it('falls back to the requested tier when the response has none', function () {
    $response = $this->loadMockResponse('openai/chat_completion_cache_writes.json');
    unset($response['service_tier']);
    Http::fake(['api.openai.com/*' => Http::response($response)]);

    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-5.6-sol', 'service_tier' => 'flex', 'messages' => []]);

    $row = SpectraRequest::sole();

    // gpt-5.6-sol flex: in 200, cached 20, cache write 250, out 1000.
    // (1000*200 + 8000*20 + 3000*250 + 900*1000) / 1M = 2.01
    expect($row->pricing_tier)->toBe('flex')
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(2.01, 1e-9);
});

it('prices prompts over 272K tokens at the long-context rates', function () {
    Http::fake(['api.openai.com/*' => Http::response($this->loadMockResponse('openai/response_long_context.json'))]);

    Http::post('https://api.openai.com/v1/responses', ['model' => 'gpt-6-astra', 'input' => 'Summarise the repository.']);

    $row = SpectraRequest::sole();

    // 300,000 input tokens > 272K, so gpt-6-astra long context: in 2000, cached 200, write 2500, out 7500.
    // Uncached input = 300000 - 200000 - 50000 = 50000.
    // (50000*2000 + 200000*200 + 50000*2500 + 4000*7500) / 1M = 295.0
    expect($row->model)->toBe('GPT-6 Astra')
        ->and($row->prompt_tokens)->toBe(300000)
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(295.0, 1e-9);
});

it('keeps short-context rates for a prompt of exactly 272K tokens', function () {
    $response = $this->loadMockResponse('openai/response_long_context.json');
    $response['usage'] = [
        'input_tokens' => 272000,
        'input_tokens_details' => ['cached_tokens' => 0, 'cache_write_tokens' => 0],
        'output_tokens' => 1000,
        'output_tokens_details' => ['reasoning_tokens' => 0],
        'total_tokens' => 273000,
    ];
    Http::fake(['api.openai.com/*' => Http::response($response)]);

    Http::post('https://api.openai.com/v1/responses', ['model' => 'gpt-6-astra', 'input' => 'x']);

    // gpt-6-astra standard short context: (272000*1000 + 1000*5000) / 1M = 277.0
    expect((float) SpectraRequest::sole()->total_cost_in_cents)->toEqualWithDelta(277.0, 1e-9);
});

it('adds hosted tool fees, counting code interpreter once per container', function () {
    Http::fake(['api.openai.com/*' => Http::response($this->loadMockResponse('openai/response_with_hosted_tools.json'))]);

    Http::post('https://api.openai.com/v1/responses', ['model' => 'gpt-5.4', 'input' => 'Research the fines.']);

    $row = SpectraRequest::sole();

    // Tokens, gpt-5.4 standard (in 250, out 1500): (2000*250 + 500*1500) / 1M = 1.25
    // Tools: 2 web searches * 1¢ + 1 file search * 0.25¢ + 2 containers * 3¢ = 8.25
    expect($row->model)->toBe('GPT-5.4')
        ->and($row->snapshot)->toBe('gpt-5.4-2026-03-05')
        ->and($row->tool_call_counts)->toBe(['web_search_call' => 2, 'file_search_call' => 1, 'code_interpreter_call' => 2])
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(9.5, 1e-9);
});

it('bills audio tokens of chat completions at the audio rates', function () {
    Http::fake(['api.openai.com/*' => Http::response($this->loadMockResponse('openai/completion_audio.json'))]);

    Http::post('https://api.openai.com/v1/chat/completions', [
        'model' => 'gpt-audio-1.5',
        'modalities' => ['text', 'audio'],
        'audio' => ['voice' => 'alloy', 'format' => 'wav'],
        'messages' => [],
    ]);

    $row = SpectraRequest::sole();

    // gpt-audio-1.5: text in 250 / out 1000, audio in 3200 / out 6400.
    // Input: 200 text + 1000 audio. Output: 100 text + 700 audio.
    // (200*250 + 1000*3200 + 100*1000 + 700*6400) / 1M = 7.83
    expect($row->prompt_tokens)->toBe(1200)
        ->and($row->completion_tokens)->toBe(800)
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(7.83, 1e-9);
});

it('tracks conversation compaction with the model from the request', function () {
    Http::fake(['api.openai.com/*' => Http::response($this->loadMockResponse('openai/response_compaction.json'))]);

    Http::post('https://api.openai.com/v1/responses/compact', ['model' => 'gpt-5.4', 'input' => []]);

    $row = SpectraRequest::sole();

    // gpt-5.4 standard: (42897*250 + 12000*1500) / 1M = 28.72425
    expect($row->model)->toBe('GPT-5.4')
        ->and($row->model_type)->toBe('text')
        ->and($row->prompt_tokens)->toBe(42897)
        ->and($row->completion_tokens)->toBe(12000)
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(28.72425, 1e-9);
});

it('does not record token counting requests', function () {
    Http::fake(['api.openai.com/*' => Http::response(['object' => 'response.input_tokens', 'input_tokens' => 11])]);

    Http::post('https://api.openai.com/v1/responses/input_tokens', ['model' => 'gpt-5.4', 'input' => 'Hello']);

    expect(SpectraRequest::count())->toBe(0);
});

it('records a provider error with its message and no cost', function () {
    Http::fake(['api.openai.com/*' => Http::response([
        'error' => [
            'message' => 'Rate limit reached for gpt-5.4 in organization org-abc on tokens per min (TPM): Limit 30000, Used 30000, Requested 1200.',
            'type' => 'tokens',
            'param' => null,
            'code' => 'rate_limit_exceeded',
        ],
    ], 429)]);

    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-5.4', 'messages' => []]);

    $row = SpectraRequest::sole();

    expect($row->status_code)->toBe(429)
        ->and($row->error_message)->toStartWith('Rate limit reached for gpt-5.4')
        ->and($row->prompt_tokens)->toBe(0)
        ->and((float) $row->total_cost_in_cents)->toBe(0.0);
});

it('tracks regional data residency hosts', function () {
    Http::fake(['eu.api.openai.com/*' => Http::response($this->loadMockResponse('openai/completion.json'))]);

    Http::post('https://eu.api.openai.com/v1/chat/completions', ['model' => 'gpt-4.1', 'messages' => []]);

    // gpt-4.1 standard (in 200, out 800): (19*200 + 10*800) / 1M = 0.0118
    expect(SpectraRequest::sole()->provider)->toBe('openai')
        ->and((float) SpectraRequest::sole()->total_cost_in_cents)->toEqualWithDelta(0.0118, 1e-9);
});

it('prices gpt-4o-2024-05-13 as its own snapshot, not as gpt-4o', function () {
    $response = $this->loadMockResponse('openai/completion.json');
    $response['model'] = 'gpt-4o-2024-05-13';
    Http::fake(['api.openai.com/*' => Http::response($response)]);

    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o-2024-05-13', 'messages' => []]);

    // gpt-4o-2024-05-13 standard (in 500, out 1500): (19*500 + 10*1500) / 1M = 0.0245
    expect(SpectraRequest::sole()->model)->toBe('GPT-4o (2024-05-13)')
        ->and((float) SpectraRequest::sole()->total_cost_in_cents)->toEqualWithDelta(0.0245, 1e-9);
});

it('doubles input and raises output by half for gpt-5.4 long context, keeping the cached rate', function () {
    $response = $this->loadMockResponse('openai/response_with_reasoning.json');
    $response['model'] = 'gpt-5.4-2026-03-05';
    $response['usage'] = [
        'input_tokens' => 400000,
        'input_tokens_details' => ['cached_tokens' => 100000],
        'output_tokens' => 2000,
        'output_tokens_details' => ['reasoning_tokens' => 1500],
        'total_tokens' => 402000,
    ];
    Http::fake(['api.openai.com/*' => Http::response($response)]);

    Http::post('https://api.openai.com/v1/responses', ['model' => 'gpt-5.4', 'input' => 'x']);

    // gpt-5.4 long context: in 500, cached 50, out 2250 (all double above 272K).
    // (300000*500 + 100000*50 + 2000*2250) / 1M = 159.5
    expect((float) SpectraRequest::sole()->total_cost_in_cents)->toEqualWithDelta(159.5, 1e-9);
});

it('prices gpt-rosalind-research from the day billing began', function () {
    $lookup = app(PricingLookup::class);

    expect($lookup->get('openai', 'gpt-rosalind-research', 'standard', 1000, CarbonImmutable::parse('2026-10-04'))['input'])->toBe(0)
        ->and($lookup->get('openai', 'gpt-rosalind-research', 'standard', 1000, CarbonImmutable::parse('2026-10-05'))['input'])->toBe(500);
});
