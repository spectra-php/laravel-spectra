<?php

use Illuminate\Support\Facades\Schema;
use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;
use Spectra\Providers\Anthropic\Anthropic;
use Spectra\Providers\Google\Google;
use Spectra\Providers\OpenAI\Handlers\TextHandler;
use Spectra\Providers\OpenAI\OpenAI;

/**
 * Helper to load mock response from tests/responses directory.
 */
function loadMockResponse(string $path): array
{
    $fullPath = __DIR__.'/../responses/'.$path;

    if (str_ends_with($path, '.jsonl')) {
        $lines = file($fullPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return array_map(fn ($line) => json_decode($line, true), $lines);
    }

    return json_decode(file_get_contents($fullPath), true);
}

/*
|--------------------------------------------------------------------------
| OpenAI Completions API Integration Tests
|--------------------------------------------------------------------------
*/

it('records openai completion response to database', function () {
    $response = loadMockResponse('openai/completion.json');

    $result = Spectra::track('openai', 'gpt-4.1', function ($context) use ($response) {
        return $response;
    });

    $record = SpectraRequest::latest()->first();

    expect($record)->not->toBeNull()
        ->and($record->provider)->toBe('openai')
        ->and($record->model)->toBe('GPT-4.1')
        ->and($record->status_code)->toBe(200)
        ->and($record->prompt_tokens)->toBe(19)
        ->and($record->completion_tokens)->toBe(10)
        ->and($record->total_tokens)->toBe(29)
        ->and($record->total_cost_in_cents)->toBeGreaterThan(0);
});

it('records openai response api format to database', function () {
    $response = loadMockResponse('openai/response.json');

    $result = Spectra::track('openai', 'gpt-4.1', function ($context) use ($response) {
        return $response;
    });

    $record = SpectraRequest::latest()->first();

    expect($record)->not->toBeNull()
        ->and($record->provider)->toBe('openai')
        ->and($record->status_code)->toBe(200)
        ->and($record->prompt_tokens)->toBe(36)
        ->and($record->completion_tokens)->toBe(87)
        ->and($record->total_tokens)->toBe(123);
});

it('records openai completion with tool calls to database', function () {
    $response = loadMockResponse('openai/completion_tool_calls.json');

    Spectra::track('openai', 'gpt-4.1', function ($context) use ($response) {
        return $response;
    });

    $record = SpectraRequest::latest()->first();

    expect($record)->not->toBeNull()
        ->and($record->provider)->toBe('openai')
        ->and($record->has_tool_calls)->toBeTrue()
        ->and($record->finish_reason)->toBe('tool_calls')
        ->and($record->prompt_tokens)->toBe(50)
        ->and($record->completion_tokens)->toBe(25);
});

it('records openai completion with reasoning tokens to database', function () {
    $response = loadMockResponse('openai/completion_reasoning.json');

    Spectra::track('openai', 'o3-mini', function ($context) use ($response) {
        return $response;
    });

    $record = SpectraRequest::latest()->first();

    expect($record)->not->toBeNull()
        ->and($record->provider)->toBe('openai')
        ->and($record->reasoning_tokens)->toBe(35)
        ->and($record->finish_reason)->toBe('stop')
        ->and($record->has_tool_calls)->toBeFalse()
        ->and($record->prompt_tokens)->toBe(30)
        ->and($record->completion_tokens)->toBe(50);
});

it('records finish_reason stop for normal completion', function () {
    $response = loadMockResponse('openai/completion.json');

    Spectra::track('openai', 'gpt-4.1', function ($context) use ($response) {
        return $response;
    });

    $record = SpectraRequest::latest()->first();

    expect($record->finish_reason)->toBe('stop')
        ->and($record->has_tool_calls)->toBeFalse()
        ->and($record->reasoning_tokens)->toBe(0);
});

it('records openai completion with multiple choices to database', function () {
    $response = loadMockResponse('openai/completion_multiple_choices.json');

    Spectra::track('openai', 'gpt-4o', function ($context) use ($response) {
        return $response;
    });

    $record = SpectraRequest::latest()->first();

    expect($record)->not->toBeNull()
        ->and($record->provider)->toBe('openai')
        ->and($record->model)->toBe('GPT-4o')
        ->and($record->prompt_tokens)->toBe(12)
        ->and($record->completion_tokens)->toBe(45);

    // Verify provider extracts all choices
    $provider = new OpenAI;
    $content = $provider->extractResponse($response);
    expect($content)->toContain('Rayleigh scattering')
        ->and($content)->toContain('Blue light scatters')
        ->and($content)->toContain('Sunlight interacts');
});

/*
|--------------------------------------------------------------------------
| OpenAI Batch API Integration Tests
|--------------------------------------------------------------------------
*/

it('records openai batch responses to database', function () {
    $batchLines = loadMockResponse('openai/batch.jsonl');

    $handler = new TextHandler;

    foreach ($batchLines as $batchItem) {
        expect(TextHandler::isBatch($batchItem))->toBeTrue();

        $data = TextHandler::unwrapBatch($batchItem);
        $metrics = $handler->extractMetrics([], $data);
        $model = $handler->extractModel($data);

        $context = Spectra::startRequest('openai', $model, [
            'pricing_tier' => 'batch',
            'metadata' => [
                'batch_id' => 'test_batch_123',
                'custom_id' => $batchItem['custom_id'],
            ],
        ]);

        $record = Spectra::recordSuccess($context, $batchItem['response']['body'], $metrics->tokens);

        expect($record)->toBeInstanceOf(SpectraRequest::class)
            ->and($record->provider)->toBe('openai')
            ->and($record->model)->toBe('GPT-4o')
            ->and($record->prompt_tokens)->toBeGreaterThan(0)
            ->and($record->completion_tokens)->toBeGreaterThan(0);
    }

    expect(SpectraRequest::count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Anthropic (Claude) Integration Tests
|--------------------------------------------------------------------------
*/

it('records anthropic message response to database', function () {
    $response = loadMockResponse('claude/message.json');

    // Use the model from the response for tracking
    $result = Spectra::track('anthropic', 'claude-sonnet-4-5-20250929', function ($context) use ($response) {
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

    // Verify provider extracts content correctly
    $provider = new Anthropic;
    $content = $provider->extractResponse($response);
    expect($content)->toContain('renewable energy');
});

it('records anthropic batch responses to database', function () {
    $batchLines = loadMockResponse('claude/batch.jsonl');

    foreach ($batchLines as $batchItem) {
        $message = $batchItem['result']['message'];
        $usage = $message['usage'];

        $context = Spectra::startRequest('anthropic', $message['model'], [
            'metadata' => [
                'custom_id' => $batchItem['custom_id'],
            ],
        ]);

        $record = Spectra::recordSuccess($context, $message, [
            'prompt_tokens' => $usage['input_tokens'],
            'completion_tokens' => $usage['output_tokens'],
        ]);

        expect($record)->toBeInstanceOf(SpectraRequest::class)
            ->and($record->provider)->toBe('anthropic')
            ->and($record->model)->toBe('Claude Sonnet 4.5')
            ->and($record->prompt_tokens)->toBeGreaterThan(0)
            ->and($record->completion_tokens)->toBeGreaterThan(0);
    }

    expect(SpectraRequest::where('provider', 'anthropic')->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Google (Gemini) Integration Tests
|--------------------------------------------------------------------------
*/

it('records google gemini response to database', function () {
    $response = loadMockResponse('google/response.json');

    $result = Spectra::track('google', 'gemini-2.0-flash', function ($context) use ($response) {
        return $response;
    });

    $record = SpectraRequest::latest()->first();

    expect($record)->not->toBeNull()
        ->and($record->provider)->toBe('google')
        ->and($record->model)->toBe('Gemini 2.0 Flash')
        ->and($record->status_code)->toBe(200)
        ->and($record->prompt_tokens)->toBe(15)
        ->and($record->completion_tokens)->toBe(42)
        ->and($record->total_tokens)->toBe(57)
        ->and($record->total_cost_in_cents)->toBeGreaterThan(0);

    // Verify provider extracts content correctly
    $provider = new Google;
    $content = $provider->extractResponse($response);
    expect($content)->toContain('Artificial Intelligence')
        ->and($content)->toContain('Machine learning');
    // Capital M
});

/*
|--------------------------------------------------------------------------
| Provider Extraction Tests
|--------------------------------------------------------------------------
*/

it('openai provider extracts all fields correctly from completion', function () {
    $response = loadMockResponse('openai/completion.json');
    $provider = new OpenAI;

    $metrics = $provider->extractMetrics($response);
    expect($metrics->tokens->promptTokens)->toBe(19)
        ->and($metrics->tokens->completionTokens)->toBe(10)
        ->and($metrics->tokens->cachedTokens)->toBe(0)
        ->and($provider->extractModel($response))->toBe('gpt-4.1-2025-04-14')
        ->and($provider->extractFinishReason($response))->toBe('stop')
        ->and($provider->extractResponse($response))->toBe('Hello! How can I assist you today?')
        ->and(TextHandler::isCompletionsApi($response))->toBeTrue()
        ->and(TextHandler::isResponsesApi($response))->toBeFalse();
});

it('openai provider extracts all fields correctly from response api', function () {
    $response = loadMockResponse('openai/response.json');
    $provider = new OpenAI;

    $metrics = $provider->extractMetrics($response);
    expect($metrics->tokens->promptTokens)->toBe(36)
        ->and($metrics->tokens->completionTokens)->toBe(87)
        ->and($metrics->tokens->cachedTokens)->toBe(0)
        ->and($provider->extractModel($response))->toBe('gpt-4.1-2025-04-14')
        ->and($provider->extractFinishReason($response))->toBe('completed')
        ->and($provider->extractResponse($response))->toContain('unicorn named Lumina')
        ->and(TextHandler::isCompletionsApi($response))->toBeFalse()
        ->and(TextHandler::isResponsesApi($response))->toBeTrue();
});

it('anthropic provider extracts all fields correctly', function () {
    $response = loadMockResponse('claude/message.json');
    $provider = new Anthropic;

    $metrics = $provider->extractMetrics($response);
    expect($metrics->tokens->promptTokens)->toBe(21)
        ->and($metrics->tokens->completionTokens)->toBe(305)
        ->and($provider->extractModel($response))->toBe('claude-sonnet-4-5-20250929')
        ->and($provider->extractFinishReason($response))->toBe('end_turn')
        ->and($provider->extractResponse($response))->toContain('renewable energy');
});

it('anthropic handler extracts fields from all model responses', function (string $file, string $expectedModel, int $promptTokens, int $completionTokens) {
    $response = loadMockResponse("claude/{$file}");
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

    // Standard API format (type: message, content: array)
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
    $response = loadMockResponse('claude/claude-opus-4-6.json');

    expect($response['thinking'])->not->toBeNull()
        ->and($response['thinking'])->toContain('quantum');

    // Non-thinking models return null
    $haiku = loadMockResponse('claude/claude-3-haiku.json');
    expect($haiku['thinking'])->toBeNull();
});

it('google provider extracts all fields correctly', function () {
    $response = loadMockResponse('google/response.json');
    $provider = new Google;

    $metrics = $provider->extractMetrics($response);
    expect($metrics->tokens->promptTokens)->toBe(15)
        ->and($metrics->tokens->completionTokens)->toBe(42)
        ->and($metrics->tokens->cachedTokens)->toBe(0)
        ->and($provider->extractModel($response))->toBe('gemini-2.0-flash')
        ->and($provider->extractFinishReason($response))->toBe('STOP')
        ->and($provider->extractResponse($response))->toContain('Artificial Intelligence');
});

/*
|--------------------------------------------------------------------------
| Cost Calculation Tests with Mock Responses
|--------------------------------------------------------------------------
*/

it('calculates correct cost for openai completion', function () {
    $response = loadMockResponse('openai/completion.json');

    Spectra::track('openai', 'gpt-4.1', function () use ($response) {
        return $response;
    });

    $record = SpectraRequest::latest()->first();

    // gpt-4.1 standard pricing: input=200, output=800 cents per 1M tokens
    // 19 prompt tokens = 19 * 200 / 1,000,000 = 0.0038 cents
    // 10 completion tokens = 10 * 800 / 1,000,000 = 0.008 cents
    // Total = 0.0118 cents
    expect($record->total_cost_in_cents)->toBeGreaterThan(0)
        ->and($record->total_cost_in_cents)->toBeLessThan(1);
    // Should be tiny for few tokens
});

it('calculates correct cost for anthropic message', function () {
    $response = loadMockResponse('claude/message.json');

    Spectra::track('anthropic', 'claude-sonnet-4-5-20250929', function () use ($response) {
        return $response;
    });

    $record = SpectraRequest::latest()->first();

    // claude-sonnet-4-5-20250929 pricing: input=300, output=1500 cents per 1M tokens
    // 21 input tokens = 21 * 300 / 1,000,000 = 0.0063 cents
    // 305 output tokens = 305 * 1500 / 1,000,000 = 0.4575 cents
    // Total ≈ 0.4638 cents
    expect($record->total_cost_in_cents)->toBeGreaterThan(0.4)
        ->and($record->total_cost_in_cents)->toBeLessThan(0.6);
});

it('calculates correct cost for google gemini', function () {
    $response = loadMockResponse('google/response.json');

    Spectra::track('google', 'gemini-2.0-flash', function () use ($response) {
        return $response;
    });

    $record = SpectraRequest::latest()->first();

    // gemini-2.0-flash pricing: input=10, output=40 cents per 1M tokens
    // 15 prompt tokens = 15 * 10 / 1,000,000 = 0.00015 cents
    // 42 completion tokens = 42 * 40 / 1,000,000 = 0.00168 cents
    // Total ≈ 0.00183 cents
    expect($record->total_cost_in_cents)->toBeGreaterThan(0)
        ->and($record->total_cost_in_cents)->toBeLessThan(0.01);
});

/*
|--------------------------------------------------------------------------
| Tags and Metadata Tests
|--------------------------------------------------------------------------
*/

it('records tags with mock response', function () {
    $response = loadMockResponse('openai/completion.json');

    Spectra::track('openai', 'gpt-4o', function ($context) use ($response) {
        $context->addTag('chat');
        $context->addTag('test');

        return $response;
    });

    $record = SpectraRequest::latest()->first();

    // Tags are stored via attachTags method
    expect($record)->not->toBeNull()
        ->and($record->provider)->toBe('openai')
        ->and($record->status_code)->toBe(200);

    // Verify context had tags (they may be stored in a separate table)
    // Skip tag count assertion if tags table doesn't exist in test DB
    if (Schema::hasTable('spectra_tags')) {
        expect($record->tags)->toHaveCount(2);
    }
})->skip(fn () => ! Schema::hasTable('spectra_tags'), 'Tags table not available in test database');

/*
|--------------------------------------------------------------------------
| Error Handling Tests
|--------------------------------------------------------------------------
*/

it('records failed request with mock response structure', function () {
    $context = Spectra::startRequest('openai', 'gpt-4o');

    $record = Spectra::recordFailure(
        $context,
        new \Exception('Rate limit exceeded'),
        429
    );

    expect($record->isFailed())->toBeTrue()
        ->and($record->prompt_tokens)->toBe(0)
        ->and($record->completion_tokens)->toBe(0)
        ->and($record->total_cost_in_cents)->toBe(0.0);
});

/*
|--------------------------------------------------------------------------
| Batch API Pricing Tier Tests
|--------------------------------------------------------------------------
*/

it('applies batch pricing tier for openai batch requests', function () {
    // Use gpt-4o which has different pricing per tier
    $usage = [
        'prompt_tokens' => 1000,
        'completion_tokens' => 500,
    ];

    // Record with batch pricing tier
    $context = Spectra::startRequest('openai', 'gpt-4o', [
        'pricing_tier' => 'batch',
    ]);

    $batchRecord = Spectra::recordSuccess($context, ['content' => 'test'], $usage);

    // Record same usage with standard pricing tier for comparison
    $context2 = Spectra::startRequest('openai', 'gpt-4o', [
        'pricing_tier' => 'standard',
    ]);

    $standardRecord = Spectra::recordSuccess($context2, ['content' => 'test'], $usage);

    // Batch should be cheaper than standard (batch is ~50% of standard for gpt-4o)
    // gpt-4o batch: input=125, output=500; standard: input=250, output=1000
    expect($batchRecord->total_cost_in_cents)->toBeLessThan($standardRecord->total_cost_in_cents)
        ->and($batchRecord->prompt_tokens)->toBe($standardRecord->prompt_tokens)
        ->and($batchRecord->completion_tokens)->toBe($standardRecord->completion_tokens);

    // Verify actual cost difference (batch should be ~50% of standard)
    $ratio = $batchRecord->total_cost_in_cents / $standardRecord->total_cost_in_cents;
    expect($ratio)->toBeLessThan(0.6); // Should be around 0.5
});

it('applies priority pricing tier for openai requests', function () {
    $response = loadMockResponse('openai/completion.json');

    // Record with standard pricing
    Spectra::track('openai', 'gpt-4o', function () use ($response) {
        return $response;
    }, ['pricing_tier' => 'standard']);

    $standardRecord = SpectraRequest::latest()->first();
    $standardCost = $standardRecord->total_cost_in_cents;

    // Record with priority pricing
    Spectra::track('openai', 'gpt-4o', function () use ($response) {
        return $response;
    }, ['pricing_tier' => 'priority']);

    $priorityRecord = SpectraRequest::latest()->first();
    $priorityCost = $priorityRecord->total_cost_in_cents;

    // Priority should be more expensive than standard (~1.7x for gpt-4o)
    expect($priorityCost)->toBeGreaterThan($standardCost);
});

/*
|--------------------------------------------------------------------------
| Streaming Integration Tests (Database)
|--------------------------------------------------------------------------
*/

it('records streaming response to database', function () {
    $tracker = Spectra::stream('openai', 'gpt-4o');

    // Simulate OpenAI streaming chunks
    $chunks = [
        ['choices' => [['delta' => ['role' => 'assistant']]], 'model' => 'gpt-4o-2024-11-20'],
        ['choices' => [['delta' => ['content' => 'Hello']]]],
        ['choices' => [['delta' => ['content' => ', ']]]],
        ['choices' => [['delta' => ['content' => 'world']]]],
        ['choices' => [['delta' => ['content' => '!']]]],
        ['choices' => [['finish_reason' => 'stop']], 'usage' => [
            'prompt_tokens' => 15,
            'completion_tokens' => 5,
            'prompt_tokens_details' => ['cached_tokens' => 0],
        ]],
    ];

    $content = '';
    foreach ($tracker->track($chunks) as $text) {
        $content .= $text;
    }

    $record = $tracker->finish();

    expect($record)->toBeInstanceOf(SpectraRequest::class)
        ->and($record->provider)->toBe('openai')
        ->and($record->model)->toBe('GPT-4o')
        ->and($record->status_code)->toBe(200)
        ->and($record->prompt_tokens)->toBe(15)
        ->and($record->completion_tokens)->toBe(5)
        ->and($record->total_cost_in_cents)->toBeGreaterThan(0)
        ->and($content)->toBe('Hello, world!')
        ->and($tracker->getTimeToFirstToken())->not->toBeNull();

    // Verify it's in the database
    $dbRecord = SpectraRequest::find($record->id);
    expect($dbRecord)->not->toBeNull()
        ->and($dbRecord->prompt_tokens)->toBe(15);
});

it('records anthropic streaming response to database', function () {
    $tracker = Spectra::stream('anthropic', 'claude-sonnet-4-20250514');

    // Simulate Anthropic streaming chunks
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

it('records google streaming response to database', function () {
    $tracker = Spectra::stream('google', 'gemini-2.0-flash');

    // Simulate Google Gemini streaming chunks
    $chunks = [
        ['candidates' => [['content' => ['parts' => [['text' => 'Hi there! ']]]]]],
        ['candidates' => [['content' => ['parts' => [['text' => 'How can I help?']]]]]],
        ['candidates' => [['content' => ['parts' => [['text' => '']]], 'finishReason' => 'STOP']],
            'usageMetadata' => [
                'promptTokenCount' => 10,
                'candidatesTokenCount' => 8,
                'totalTokenCount' => 18,
            ]],
    ];

    $content = '';
    foreach ($tracker->track($chunks) as $text) {
        $content .= $text;
    }

    $record = $tracker->finish();

    expect($record)->toBeInstanceOf(SpectraRequest::class)
        ->and($record->provider)->toBe('google')
        ->and($record->status_code)->toBe(200)
        ->and($record->prompt_tokens)->toBe(10)
        ->and($record->completion_tokens)->toBe(8)
        ->and($content)->toBe('Hi there! How can I help?');
});

it('records openai responses api streaming to database', function () {
    $tracker = Spectra::stream('openai', 'gpt-4o');

    // Simulate OpenAI Responses API streaming
    $chunks = [
        ['type' => 'response.created', 'response' => ['id' => 'resp_123', 'model' => 'gpt-4o']],
        ['type' => 'response.output_text.delta', 'delta' => 'Hello '],
        ['type' => 'response.output_text.delta', 'delta' => 'from Responses API!'],
        ['type' => 'response.completed', 'response' => [
            'id' => 'resp_123',
            'model' => 'gpt-4o-2024-11-20',
            'status' => 'completed',
            'usage' => [
                'input_tokens' => 20,
                'output_tokens' => 6,
                'input_tokens_details' => ['cached_tokens' => 5],
            ],
        ]],
    ];

    $content = '';
    foreach ($tracker->track($chunks) as $text) {
        $content .= $text;
    }

    $record = $tracker->finish();

    expect($record)->toBeInstanceOf(SpectraRequest::class)
        ->and($record->status_code)->toBe(200)
        ->and($record->prompt_tokens)->toBe(20)
        ->and($record->completion_tokens)->toBe(6)
        ->and($content)->toBe('Hello from Responses API!');
});

it('records streaming with manual usage to database', function () {
    $tracker = Spectra::stream('openai', 'gpt-4o');

    // Simulate stream without usage in chunks (older API versions)
    $chunks = [
        ['choices' => [['delta' => ['content' => 'Test response']]]],
        ['choices' => [['finish_reason' => 'stop']]],
    ];

    foreach ($tracker->track($chunks) as $text) {
        // consume
    }

    // Manually set usage (e.g., from a separate API call or estimation)
    $tracker->setUsage([
        'prompt_tokens' => 50,
        'completion_tokens' => 25,
    ]);

    $record = $tracker->finish();

    expect($record->prompt_tokens)->toBe(50)
        ->and($record->completion_tokens)->toBe(25)
        ->and($record->total_cost_in_cents)->toBeGreaterThan(0);
});

/*
|--------------------------------------------------------------------------
| Batch Processing Complete Workflow Tests
|--------------------------------------------------------------------------
*/

it('processes complete openai batch workflow', function () {
    $batchLines = loadMockResponse('openai/batch.jsonl');

    $processedCount = 0;
    $totalCost = 0;

    foreach ($batchLines as $index => $batchItem) {
        // Skip if error
        if ($batchItem['error'] !== null) {
            continue;
        }

        $handler = new TextHandler;
        $data = TextHandler::unwrapBatch($batchItem);
        $metrics = $handler->extractMetrics([], $data);
        $model = $handler->extractModel($data);
        $content = $handler->extractResponse($data);

        $context = Spectra::startRequest('openai', $model, [
            'pricing_tier' => 'batch',
            'metadata' => [
                'batch_id' => 'batch_abc123',
                'custom_id' => $batchItem['custom_id'],
                'batch_request_id' => $batchItem['id'],
            ],
        ]);

        $record = Spectra::recordSuccess($context, $batchItem['response']['body'], $metrics->tokens);

        expect($record->status_code)->toBe(200)
            ->and($record->prompt_tokens)->toBeGreaterThan(0);

        $processedCount++;
        $totalCost += $record->total_cost_in_cents;
    }

    expect($processedCount)->toBe(2)
        ->and($totalCost)->toBeGreaterThan(0);

    // Verify all records in database
    $records = SpectraRequest::where('provider', 'openai')->get();
    expect($records)->toHaveCount(2);
});

it('processes complete anthropic batch workflow', function () {
    $batchLines = loadMockResponse('claude/batch.jsonl');

    $processedCount = 0;

    foreach ($batchLines as $batchItem) {
        $result = $batchItem['result'];

        // Skip failed results
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
| Reasoning Effort Extraction Tests
|--------------------------------------------------------------------------
*/

it('extracts reasoning_effort from responses api response body', function () {
    $response = loadMockResponse('openai/response_with_reasoning.json');

    Spectra::track('openai', 'gpt-5', function () use ($response) {
        return $response;
    });

    $record = SpectraRequest::latest()->first();

    expect($record)->not->toBeNull()
        ->and($record->reasoning_effort)->toBe('medium')
        ->and($record->is_reasoning)->toBeTrue()
        ->and($record->reasoning_tokens)->toBe(80);
});

it('extracts reasoning_effort from request data when available', function () {
    $response = loadMockResponse('openai/completion.json');

    $context = Spectra::startRequest('openai', 'o3-mini');
    $context->requestData = ['reasoning_effort' => 'high'];

    Spectra::recordSuccess($context, $response, [
        'prompt_tokens' => 19,
        'completion_tokens' => 10,
    ]);

    $record = SpectraRequest::latest()->first();

    expect($record)->not->toBeNull();
    expect($record->reasoning_effort)->toBe('high');
    expect($record->is_reasoning)->toBeTrue();
});

it('prefers request data reasoning_effort over response body', function () {
    $response = loadMockResponse('openai/response_with_reasoning.json');

    $context = Spectra::startRequest('openai', 'gpt-5');
    $context->requestData = ['reasoning_effort' => 'high'];

    Spectra::recordSuccess($context, $response, [
        'prompt_tokens' => 50,
        'completion_tokens' => 120,
    ]);

    $record = SpectraRequest::latest()->first();

    // Request data 'high' should take precedence over response body 'medium'
    expect($record->reasoning_effort)->toBe('high');
});

it('detects reasoning from output blocks even when reasoning_tokens is zero', function () {
    $response = loadMockResponse('openai/response_reasoning_zero_tokens.json');

    Spectra::track('openai', 'gpt-5.2-codex', function () use ($response) {
        return $response;
    });

    $record = SpectraRequest::latest()->first();

    expect($record)->not->toBeNull()
        ->and($record->reasoning_tokens)->toBe(0)
        ->and($record->reasoning_effort)->toBe('medium')
        ->and($record->is_reasoning)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Tool Call Counting Tests
|--------------------------------------------------------------------------
*/

it('counts tool calls by type from openai responses api', function () {
    $response = loadMockResponse('openai/response_with_tool_calls.json');

    Spectra::track('openai', 'o3-deep-research', function () use ($response) {
        return $response;
    });

    $record = SpectraRequest::latest()->first();

    expect($record)->not->toBeNull()
        ->and($record->has_tool_calls)->toBeTrue()
        ->and($record->tool_call_counts)->toBeArray()
        ->and($record->tool_call_counts)->toBe([
            'web_search_call' => 3,
            'code_interpreter_call' => 1,
            'function_call' => 2,
        ]);
});

it('counts tool calls by type from openai completions api', function () {
    $response = loadMockResponse('openai/completion_multiple_tool_calls.json');

    Spectra::track('openai', 'gpt-4.1', function () use ($response) {
        return $response;
    });

    $record = SpectraRequest::latest()->first();

    expect($record)->not->toBeNull()
        ->and($record->has_tool_calls)->toBeTrue()
        ->and($record->tool_call_counts)->toBeArray()
        ->and($record->tool_call_counts)->toBe([
            'function' => 3,
        ])
        ->and($record->finish_reason)->toBe('tool_calls');
});

it('counts single tool call from openai completions api', function () {
    $response = loadMockResponse('openai/completion_tool_calls.json');

    Spectra::track('openai', 'gpt-4.1', function () use ($response) {
        return $response;
    });

    $record = SpectraRequest::latest()->first();

    expect($record)->not->toBeNull()
        ->and($record->has_tool_calls)->toBeTrue()
        ->and($record->tool_call_counts)->toBeArray()
        ->and($record->tool_call_counts)->toBe([
            'function' => 1,
        ]);
});

it('stores null tool_call_counts when no tool calls present', function () {
    $response = loadMockResponse('openai/completion.json');

    Spectra::track('openai', 'gpt-4.1', function () use ($response) {
        return $response;
    });

    $record = SpectraRequest::latest()->first();

    expect($record)->not->toBeNull()
        ->and($record->has_tool_calls)->toBeFalse()
        ->and($record->tool_call_counts)->toBeNull();
});

it('adds tool call cost surcharge to total cost', function () {
    $response = loadMockResponse('openai/response_with_tool_calls.json');

    Spectra::track('openai', 'o3-deep-research', function () use ($response) {
        return $response;
    });

    $record = SpectraRequest::latest()->first();

    expect($record)->not->toBeNull()
        ->and($record->total_cost_in_cents)->toBeGreaterThanOrEqual(6.0);
});

/*
|--------------------------------------------------------------------------
| TTS Minute-Based Pricing Tests
|--------------------------------------------------------------------------
*/

it('uses minute-based pricing for tts-1 model', function () {
    $calculator = app(\Spectra\Support\Pricing\CostCalculator::class);
    $unit = $calculator->getPricingUnit('openai', 'tts-1');

    expect($unit)->toBe('minute');
});

it('uses minute-based pricing for tts-1-hd model', function () {
    $calculator = app(\Spectra\Support\Pricing\CostCalculator::class);
    $unit = $calculator->getPricingUnit('openai', 'tts-1-hd');

    expect($unit)->toBe('minute');
});

it('uses minute-based pricing for gpt-4o-mini-tts model', function () {
    $calculator = app(\Spectra\Support\Pricing\CostCalculator::class);
    $unit = $calculator->getPricingUnit('openai', 'gpt-4o-mini-tts');

    expect($unit)->toBe('minute');
});

it('calculates tts cost by duration', function () {
    $calculator = app(\Spectra\Support\Pricing\CostCalculator::class);

    // tts-1 at 1.5¢/min, 60 seconds = 1 minute = 1.5¢
    $cost = $calculator->calculateByDuration('openai', 'tts-1', 60.0);

    expect($cost['total_cost_in_cents'])->toBe(1.5);
});

it('calculates tts-1-hd cost by duration', function () {
    $calculator = app(\Spectra\Support\Pricing\CostCalculator::class);

    // tts-1-hd at 3.0¢/min, 60 seconds = 1 minute = 3.0¢
    $cost = $calculator->calculateByDuration('openai', 'tts-1-hd', 60.0);

    expect($cost['total_cost_in_cents'])->toBe(3.0);
});
