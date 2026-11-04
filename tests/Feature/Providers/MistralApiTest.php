<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Spectra\Models\SpectraRequest;

/**
 * Mistral requests as real apps send them: through Laravel's HTTP client, with
 * Spectra's watcher installed as global middleware.
 */
function mistralSse(array ...$events): string
{
    return implode('', array_map(fn (array $event) => 'data: '.json_encode($event)."\n\n", $events))."data: [DONE]\n\n";
}

it('bills a reasoning response with its cache hits and thinking inside the output', function () {
    Http::fake(['api.mistral.ai/*' => Http::response($this->loadMockResponse('mistral/chat-reasoning.json'))]);

    Http::post('https://api.mistral.ai/v1/chat/completions', ['model' => 'mistral-large-4', 'reasoning_effort' => 'high', 'messages' => []]);

    $row = SpectraRequest::query()->sole();

    // Mistral Large 4: 200 uncached × 70 + 1,000 cached × 7 + 1,114 × 210
    // = 254,940 / 1M = 0.25494 cents. Thinking tokens are already in completion_tokens.
    expect($row->provider)->toBe('mistral')
        ->and($row->model)->toBe('Mistral Large 4')
        ->and($row->model_type)->toBe('text')
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

it('records the tier Mistral says it served and bills it at standard without a published price', function () {
    $body = $this->loadMockResponse('mistral/chat.json');
    $body['usage']['service_tier'] = 'priority';
    Http::fake(['api.mistral.ai/*' => Http::response($body)]);

    Http::post('https://api.mistral.ai/v1/chat/completions', ['model' => 'mistral-large-latest', 'service_tier' => 'priority', 'messages' => []]);

    $row = SpectraRequest::query()->sole();

    // No priority price is published, so Mistral Large 3 bills at standard: (25×50 + 15×150) / 1M = 0.0035¢
    expect($row->pricing_tier)->toBe('priority')
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(0.0035, 1e-12);
});

it('records a streamed reasoning response with its answer text and cost', function () {
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

    $row = SpectraRequest::query()->sole();

    // Mistral Medium 3.5: 14 × 150 + 86 × 750 = 66,600 / 1M = 0.0666 cents.
    expect($row->is_streaming)->toBeTrue()
        ->and($row->model)->toBe('Mistral Medium 3.5')
        ->and($row->response['content'])->toBe('17 × 23 = 391.')
        ->and($row->prompt_tokens)->toBe(14)
        ->and($row->completion_tokens)->toBe(86)
        ->and($row->finish_reason)->toBe('stop')
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(0.0666, 1e-9);
});

it('bills OCR per page', function () {
    Http::fake(['api.mistral.ai/*' => Http::response($this->loadMockResponse('mistral/ocr.json'))]);

    Http::post('https://api.mistral.ai/v1/ocr', [
        'model' => 'mistral-ocr-latest',
        'document' => ['type' => 'document_url', 'document_url' => 'https://example.com/report.pdf'],
    ]);

    $row = SpectraRequest::query()->sole();

    // Mistral OCR 4.1: 3 pages × 0.4 cents = 1.2 cents.
    expect($row->provider)->toBe('mistral')
        ->and($row->model)->toBe('Mistral OCR 4.1')
        ->and($row->model_type)->toBe('text')
        ->and($row->page_count)->toBe(3)
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(1.2, 1e-9);
});
