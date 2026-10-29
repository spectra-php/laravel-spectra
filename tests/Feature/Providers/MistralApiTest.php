<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Pricing\CostCalculator;

/**
 * Mistral requests as real apps send them: through Laravel's HTTP client, with
 * Spectra's watcher installed as global middleware.
 */
function mistralSse(array ...$events): string
{
    return implode('', array_map(fn (array $event) => 'data: '.json_encode($event)."\n\n", $events))."data: [DONE]\n\n";
}

it('tracks a reasoning response with thinking chunks and cache hits', function () {
    Http::fake(['api.mistral.ai/*' => Http::response($this->loadMockResponse('mistral/chat-reasoning.json'))]);

    Http::post('https://api.mistral.ai/v1/chat/completions', ['model' => 'mistral-large-4', 'reasoning_effort' => 'high', 'messages' => []]);

    $row = SpectraRequest::sole();

    // Mistral Large 4: 200 uncached × 70 + 1,000 cached × 7 + 1,114 × 210
    // = 254,940 / 1M = 0.25494 cents. Thinking tokens are already in completion_tokens.
    expect($row->model)->toBe('Mistral Large 4')
        ->and($row->response['choices'][0]['message']['content'][1]['text'])->toBe('John is 22 years old.')
        ->and($row->prompt_tokens)->toBe(1_200)
        ->and($row->cached_tokens)->toBe(1_000)
        ->and($row->completion_tokens)->toBe(1_114)
        ->and($row->is_reasoning)->toBeTrue()
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(0.25494, 1e-9);
});

it('extracts only the answer text from thinking chunks', function () {
    $handler = $this->mistralProvider()->resolveHandler('/v1/chat/completions');

    expect($handler->extractResponse($this->loadMockResponse('mistral/chat-reasoning.json')))->toBe('John is 22 years old.');
});

it('resolves dated model ids to their own prices', function () {
    Http::fake(['api.mistral.ai/*' => Http::sequence()
        ->push(['id' => 'm-1', 'object' => 'chat.completion', 'model' => 'mistral-medium-2508', 'choices' => [], 'usage' => ['prompt_tokens' => 1_000_000, 'completion_tokens' => 1_000_000, 'total_tokens' => 2_000_000]])
        ->push(['id' => 'm-2', 'object' => 'chat.completion', 'model' => 'mistral-medium-3-5', 'choices' => [], 'usage' => ['prompt_tokens' => 1_000_000, 'completion_tokens' => 1_000_000, 'total_tokens' => 2_000_000]])]);

    Http::post('https://api.mistral.ai/v1/chat/completions', ['model' => 'mistral-medium-2508', 'messages' => []]);
    Http::post('https://api.mistral.ai/v1/chat/completions', ['model' => 'mistral-medium-3-5', 'messages' => []]);

    [$medium31, $medium35] = SpectraRequest::orderBy('id')->get()->all();

    // Medium 3.1: $0.40 + $2 = 240 cents. Medium 3.5: $1.50 + $7.50 = 900 cents.
    expect($medium31->model)->toBe('Mistral Medium 3.1')
        ->and((float) $medium31->total_cost_in_cents)->toEqualWithDelta(240.0, 1e-9)
        ->and($medium35->model)->toBe('Mistral Medium 3.5')
        ->and((float) $medium35->total_cost_in_cents)->toEqualWithDelta(900.0, 1e-9);
});

it('records the tier Mistral says it served', function () {
    Http::fake(['api.mistral.ai/*' => Http::response([
        'id' => 'm-priority', 'object' => 'chat.completion', 'model' => 'mistral-small-latest',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12, 'service_tier' => 'priority'],
    ])]);

    Http::post('https://api.mistral.ai/v1/chat/completions', ['model' => 'mistral-small-latest', 'service_tier' => 'priority', 'messages' => []]);

    // No priority price is published, so it bills at standard: 10 × 15 + 2 × 60 = 270 / 1M cents.
    expect(SpectraRequest::sole()->pricing_tier)->toBe('priority')
        ->and((float) SpectraRequest::sole()->total_cost_in_cents)->toEqualWithDelta(0.00027, 1e-12);
});

it('tracks a streamed reasoning response', function () {
    $chunk = fn (mixed $content, ?string $finish = null, ?array $usage = null) => array_filter([
        'id' => 'cmpl-mistral-stream', 'object' => 'chat.completion.chunk', 'created' => 1773842038, 'model' => 'mistral-medium-3-5',
        'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => $finish]],
        'usage' => $usage,
    ], fn ($value) => $value !== null);

    $sse = mistralSse(
        $chunk([['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => '17 × 23 = 17 × 20 + 17 × 3']]]]),
        $chunk([['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => ' = 391']]], ['type' => 'text', 'text' => '17 × 23']]),
        $chunk(' = 391.'),
        $chunk('', 'stop', ['prompt_tokens' => 14, 'completion_tokens' => 86, 'total_tokens' => 100, 'prompt_tokens_details' => ['cached_tokens' => 0]]),
    );

    Http::fake(['api.mistral.ai/*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);

    Http::post('https://api.mistral.ai/v1/chat/completions', ['model' => 'mistral-medium-3-5', 'stream' => true, 'reasoning_effort' => 'high', 'messages' => []])->body();

    $row = SpectraRequest::sole();

    // Mistral Medium 3.5: 14 × 150 + 86 × 750 = 66,600 / 1M = 0.0666 cents.
    expect($row->is_streaming)->toBeTrue()
        ->and($row->model)->toBe('Mistral Medium 3.5')
        ->and($row->response['content'])->toBe('17 × 23 = 391.')
        ->and($row->prompt_tokens)->toBe(14)
        ->and($row->completion_tokens)->toBe(86)
        ->and($row->finish_reason)->toBe('stop')
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(0.0666, 1e-9);
});

it('tracks OCR per page', function () {
    Http::fake(['api.mistral.ai/*' => Http::response($this->loadMockResponse('mistral/ocr.json'))]);

    Http::post('https://api.mistral.ai/v1/ocr', [
        'model' => 'mistral-ocr-latest',
        'document' => ['type' => 'document_url', 'document_url' => 'https://example.com/report.pdf'],
    ]);

    $row = SpectraRequest::sole();

    // Mistral OCR 4.1: 3 pages × 0.4 cents = 1.2 cents.
    expect($row->model)->toBe('Mistral OCR 4.1')
        ->and($row->page_count)->toBe(3)
        ->and($row->response['usage_info']['pages_processed'])->toBe(3)
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(1.2, 1e-9);
});

it('prices the corrected catalog entries', function () {
    $calculator = new CostCalculator;

    // Moderation 2 is free; Devstral 2 is $0.40 / $2; GLM 5.3 caches at $0.14.
    expect($calculator->calculate('mistral', 'mistral-moderation-2603', 1_000_000, 0)['total_cost_in_cents'])->toEqual(0.0)
        ->and($calculator->calculate('mistral', 'devstral-latest', 1_000_000, 1_000_000)['total_cost_in_cents'])->toEqual(240.0)
        ->and($calculator->calculate('mistral', 'zai-glm-latest', 1_000_000, 0, 1_000_000)['total_cost_in_cents'])->toEqual(14.0)
        ->and($calculator->calculate('mistral', 'mistral-nemo', 1_000_000, 1_000_000)['total_cost_in_cents'])->toEqual(30.0)
        ->and($calculator->calculate('mistral', 'mistral-large-latest', 1_000_000, 1_000_000, 0, 'batch')['total_cost_in_cents'])->toEqual(100.0)
        ->and($calculator->calculateByPages('mistral', 'mistral-ocr-2512', 10)['total_cost_in_cents'])->toEqual(2.0)
        ->and($calculator->calculateByDuration('mistral', 'voxtral-mini-realtime-latest', 120)['total_cost_in_cents'])->toEqual(1.2);
});
