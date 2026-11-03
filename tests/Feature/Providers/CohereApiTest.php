<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Spectra\Models\SpectraRequest;

/**
 * Cohere requests as real apps send them: through Laravel's HTTP client, with
 * Spectra's watcher installed as global middleware.
 */
function cohereSse(array ...$events): string
{
    return implode('', array_map(
        fn (array $event) => 'event: '.$event['type']."\n".'data: '.json_encode($event)."\n\n",
        $events,
    ));
}

it('bills a v2 chat on its billed tokens, with cached tokens at the input rate', function () {
    Http::fake(['api.cohere.com/*' => Http::response($this->loadMockResponse('cohere/chat-v2.json'))]);

    Http::post('https://api.cohere.com/v2/chat', ['model' => 'command-r-08-2024', 'messages' => [['role' => 'user', 'content' => 'Tell me about LLMs']]]);

    $row = SpectraRequest::sole();

    // Command R: 1,200 billed input × 15 + 418 output × 60 = 43,080 / 1M cents.
    // Cached tokens have no separate price, so they bill at the input rate.
    expect($row->provider)->toBe('cohere')
        ->and($row->model)->toBe('Command R')
        ->and($row->model_type)->toBe('text')
        ->and($row->response_id)->toBe('c14c80c3-18eb-4519-9460-6c92edd8cfb4')
        ->and($row->prompt_tokens)->toBe(1_200)
        ->and($row->cached_tokens)->toBe(1_024)
        ->and($row->completion_tokens)->toBe(418)
        ->and($row->finish_reason)->toBe('COMPLETE')
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(0.04308, 1e-9);
});

it('tracks a streamed v2 chat from its message-end usage', function () {
    $sse = cohereSse(
        ['delta' => ['message' => ['role' => 'assistant']], 'id' => '29f14a5a-11de-4cae-9800-25e4747408ea', 'type' => 'message-start'],
        ['delta' => ['message' => ['content' => ['text' => '', 'type' => 'text']]], 'index' => 0, 'type' => 'content-start'],
        ['delta' => ['message' => ['content' => ['text' => 'LL']]], 'index' => 0, 'type' => 'content-delta'],
        ['delta' => ['message' => ['content' => ['text' => 'Ms stand for Large Language Models.']]], 'index' => 0, 'type' => 'content-delta'],
        ['index' => 0, 'type' => 'content-end'],
        ['delta' => ['finish_reason' => 'COMPLETE', 'usage' => ['billed_units' => ['input_tokens' => 5, 'output_tokens' => 26], 'tokens' => ['input_tokens' => 71, 'output_tokens' => 26]]], 'type' => 'message-end'],
    );

    Http::fake(['api.cohere.com/*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);

    $response = Http::post('https://api.cohere.com/v2/chat', ['model' => 'command-r7b-12-2024', 'stream' => true, 'messages' => []]);

    expect($response->body())->toBe($sse);

    $row = SpectraRequest::sole();

    // Command R7B: 5 × 3.75 + 26 × 15 = 408.75 / 1M cents.
    expect($row->is_streaming)->toBeTrue()
        ->and($row->model)->toBe('Command R7B')
        ->and($row->response_id)->toBe('29f14a5a-11de-4cae-9800-25e4747408ea')
        ->and($row->response['content'])->toBe('LLMs stand for Large Language Models.')
        ->and($row->prompt_tokens)->toBe(5)
        ->and($row->completion_tokens)->toBe(26)
        ->and($row->finish_reason)->toBe('COMPLETE')
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(0.00040875, 1e-12);
});

it('bills embed image tokens on top of text tokens', function () {
    $body = $this->loadMockResponse('cohere/embed.json');
    $body['meta']['billed_units'] = ['input_tokens' => 10, 'image_tokens' => 1_000];
    Http::fake(['api.cohere.ai/*' => Http::response($body)]);

    Http::post('https://api.cohere.ai/v2/embed', ['model' => 'embed-v4.0', 'input_type' => 'image', 'embedding_types' => ['float'], 'images' => ['data:image/jpeg;base64,AAAA']]);

    $row = SpectraRequest::sole();

    // Embed 4: (10 text + 1,000 image tokens) × 12 = 12,120 / 1M cents.
    expect($row->model_type)->toBe('embedding')
        ->and($row->prompt_tokens)->toBe(1_010)
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(0.01212, 1e-12);
});

it('bills rerank per search unit', function () {
    $body = $this->loadMockResponse('cohere/rerank.json');
    $body['meta']['billed_units']['search_units'] = 3;
    Http::fake(['api.cohere.com/*' => Http::response($body)]);

    Http::post('https://api.cohere.com/v2/rerank', ['model' => 'rerank-v4.0-pro', 'query' => 'q', 'documents' => ['a', 'b', 'c']]);

    $row = SpectraRequest::sole();

    // Rerank 4 Pro: 3 searches × 0.25 cents.
    expect($row->model)->toBe('Rerank 4 Pro')
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(0.75, 1e-12);
});

it('bills parse per page', function () {
    Http::fake(['api.cohere.com/*' => Http::response($this->loadMockResponse('cohere/parse.json'))]);

    Http::post('https://api.cohere.com/v2/parse', ['model' => 'parse-v5.0', 'document' => ['type' => 'image_url', 'image_url' => 'https://cohere.com/favicon-32x32.png'], 'output_format' => 'markdown']);

    $row = SpectraRequest::sole();

    // Parse 5: 2 pages × 0.15 cents.
    expect($row->provider)->toBe('cohere')
        ->and($row->model)->toBe('Parse 5')
        ->and($row->model_type)->toBe('text')
        ->and($row->page_count)->toBe(2)
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(0.3, 1e-12);
});
