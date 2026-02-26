<?php

use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;
use Spectra\Providers\Anthropic\Anthropic;

it('records anthropic message response to database', function () {
    $response = $this->loadMockResponse('claude/message.json');

    Spectra::track('anthropic', 'claude-sonnet-4-5-20250929', function ($context) use ($response) {
        return $response;
    });

    $record = SpectraRequest::latest()->first();

    expect($record)->not->toBeNull()
        ->and($record->provider)->toBe('anthropic')
        ->and($record->model)->toBe('Claude Sonnet 4.5')
        ->and($record->status_code)->toBe(200)
        ->and($record->prompt_tokens)->toBe(21)
        ->and($record->completion_tokens)->toBe(305)
        ->and($record->total_tokens)->toBe(326)
        ->and($record->total_cost_in_cents)->toBeGreaterThan(0);

    $provider = new Anthropic;
    $content = $provider->extractResponse($response);
    expect($content)->toContain('renewable energy');
});

/*
|--------------------------------------------------------------------------
| Provider Extraction Tests
|--------------------------------------------------------------------------
*/

it('anthropic provider extracts all fields correctly', function () {
    $response = $this->loadMockResponse('claude/message.json');
    $provider = new Anthropic;

    $metrics = $provider->extractMetrics($response);
    expect($metrics->tokens->promptTokens)->toBe(21)
        ->and($metrics->tokens->completionTokens)->toBe(305)
        ->and($provider->extractModel($response))->toBe('claude-sonnet-4-5-20250929')
        ->and($provider->extractFinishReason($response))->toBe('end_turn')
        ->and($provider->extractResponse($response))->toContain('renewable energy');
});

it('anthropic handler extracts fields from all model responses', function (string $file, string $expectedModel, int $promptTokens, int $completionTokens) {
    $response = $this->loadMockResponse("claude/{$file}");
    $provider = new Anthropic;

    $metrics = $provider->extractMetrics($response);
    expect($metrics->tokens->promptTokens)->toBe($promptTokens)
        ->and($metrics->tokens->completionTokens)->toBe($completionTokens)
        ->and($provider->extractModel($response))->toBe($expectedModel)
        ->and($provider->extractFinishReason($response))->toBe('end_turn')
        ->and($provider->extractResponse($response))->toContain('quantum');
})->with([
    'claude-opus-4-6' => ['claude-opus-4-6.json', 'claude-opus-4-6', 44, 70],
    'claude-opus-4-5' => ['claude-opus-4-5.json', 'claude-opus-4-5-20251101', 44, 89],
    'claude-opus-4-1' => ['claude-opus-4-1.json', 'claude-opus-4-1-20250805', 44, 124],
    'claude-opus-4' => ['claude-opus-4.json', 'claude-opus-4-20250514', 44, 153],
    'claude-sonnet-4-5' => ['claude-sonnet-4-5.json', 'claude-sonnet-4-5-20250929', 44, 182],
    'claude-sonnet-4' => ['claude-sonnet-4.json', 'claude-sonnet-4-20250514', 44, 94],
    'claude-3-7-sonnet' => ['claude-3-7-sonnet.json', 'claude-3-7-sonnet-20250219', 15, 53],
    'claude-haiku-4-5' => ['claude-haiku-4-5.json', 'claude-haiku-4-5-20251001', 15, 36],
    'claude-3-5-haiku' => ['claude-3-5-haiku.json', 'claude-3-5-haiku-20241022', 15, 52],
    'claude-3-haiku' => ['claude-3-haiku.json', 'claude-3-haiku-20240307', 15, 42],
]);

it('anthropic handler matches both standard and simplified response shapes', function () {
    $handler = new \Spectra\Providers\Anthropic\Handlers\MessageHandler;

    expect($handler->matchesResponse([
        'type' => 'message',
        'content' => [['type' => 'text', 'text' => 'Hello']],
        'stop_reason' => 'end_turn',
    ]))->toBeTrue()
        ->and($handler->matchesResponse([
            'type' => 'text',
            'content' => 'Hello',
            'stop_reason' => 'end_turn',
        ]))->toBeTrue();
});

it('anthropic handler extracts thinking from extended responses', function () {
    $response = $this->loadMockResponse('claude/claude-opus-4-6.json');

    expect($response['thinking'])->not->toBeNull()
        ->and($response['thinking'])->toContain('quantum');

    $haiku = $this->loadMockResponse('claude/claude-3-haiku.json');
    expect($haiku['thinking'])->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Batch Processing Tests
|--------------------------------------------------------------------------
*/

it('processes complete anthropic batch workflow', function () {
    $batchLines = $this->loadMockResponse('claude/batch.jsonl');

    $processedCount = 0;

    foreach ($batchLines as $batchItem) {
        $result = $batchItem['result'];

        if ($result['type'] !== 'succeeded') {
            continue;
        }

        $message = $result['message'];
        $usage = $message['usage'];

        $context = Spectra::startRequest('anthropic', $message['model'], [
            'metadata' => [
                'custom_id' => $batchItem['custom_id'],
                'message_id' => $message['id'],
            ],
        ]);

        $record = Spectra::recordSuccess($context, $message, [
            'prompt_tokens' => $usage['input_tokens'],
            'completion_tokens' => $usage['output_tokens'],
        ]);

        expect($record->status_code)->toBe(200)
            ->and($record->provider)->toBe('anthropic');

        $processedCount++;
    }

    expect($processedCount)->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Streaming Tests
|--------------------------------------------------------------------------
*/

it('records anthropic streaming response to database', function () {
    $tracker = Spectra::stream('anthropic', 'claude-sonnet-4-20250514');

    $chunks = [
        ['type' => 'message_start', 'message' => [
            'id' => 'msg_123',
            'model' => 'claude-sonnet-4-20250514',
            'usage' => ['input_tokens' => 25],
        ]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
        ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => 'Hello from Claude!']],
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 4]],
        ['type' => 'message_stop'],
    ];

    $content = '';
    foreach ($tracker->track($chunks) as $text) {
        $content .= $text;
    }

    $record = $tracker->finish();

    expect($record)->toBeInstanceOf(SpectraRequest::class)
        ->and($record->provider)->toBe('anthropic')
        ->and($record->status_code)->toBe(200)
        ->and($record->prompt_tokens)->toBe(25)
        ->and($record->completion_tokens)->toBe(4)
        ->and($record->total_cost_in_cents)->toBeGreaterThan(0)
        ->and($content)->toBe('Hello from Claude!');
});
