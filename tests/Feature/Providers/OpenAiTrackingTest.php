<?php

use Illuminate\Support\Facades\Schema;
use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;
use Spectra\Providers\OpenAI\Handlers\TextHandler;
use Spectra\Providers\OpenAI\OpenAI;

/*
|--------------------------------------------------------------------------
| OpenAI Response Recording Tests
|--------------------------------------------------------------------------
*/

it('records openai response to database', function (string $fixture, string $model, array $expectations) {
    $response = $this->loadMockResponse($fixture);

    Spectra::track('openai', $model, function ($context) use ($response) {
        return $response;
    });

    $record = SpectraRequest::latest()->first();

    expect($record)->not->toBeNull()
        ->and($record->provider)->toBe('openai')
        ->and($record->model)->toBe($expectations['model'])
        ->and($record->status_code)->toBe(200)
        ->and($record->prompt_tokens)->toBe($expectations['prompt_tokens'])
        ->and($record->completion_tokens)->toBe($expectations['completion_tokens']);

    if (isset($expectations['total_tokens'])) {
        expect($record->total_tokens)->toBe($expectations['total_tokens']);
    }

    if (isset($expectations['has_tool_calls'])) {
        expect($record->has_tool_calls)->toBe($expectations['has_tool_calls']);
    }

    if (isset($expectations['finish_reason'])) {
        expect($record->finish_reason)->toBe($expectations['finish_reason']);
    }

    if (isset($expectations['reasoning_tokens'])) {
        expect($record->reasoning_tokens)->toBe($expectations['reasoning_tokens']);
    }

    if (isset($expectations['cost_greater_than'])) {
        expect($record->total_cost_in_cents)->toBeGreaterThan($expectations['cost_greater_than']);
    }

    if (isset($expectations['content_contains'])) {
        $provider = new OpenAI;
        $content = $provider->extractResponse($response);
        foreach ((array) $expectations['content_contains'] as $substring) {
            expect($content)->toContain($substring);
        }
    }
})->with([
    'completion' => [
        'openai/completion.json',
        'gpt-4.1',
        [
            'model' => 'GPT-4.1',
            'prompt_tokens' => 19,
            'completion_tokens' => 10,
            'total_tokens' => 29,
            'has_tool_calls' => false,
            'finish_reason' => 'stop',
            'reasoning_tokens' => 0,
            'cost_greater_than' => 0,
        ],
    ],
    'response api' => [
        'openai/response.json',
        'gpt-4.1',
        [
            'model' => 'GPT-4.1',
            'prompt_tokens' => 36,
            'completion_tokens' => 87,
            'total_tokens' => 123,
        ],
    ],
    'tool calls' => [
        'openai/completion_tool_calls.json',
        'gpt-4.1',
        [
            'model' => 'GPT-4.1',
            'prompt_tokens' => 50,
            'completion_tokens' => 25,
            'has_tool_calls' => true,
            'finish_reason' => 'tool_calls',
        ],
    ],
    'reasoning' => [
        'openai/completion_reasoning.json',
        'o3-mini',
        [
            'model' => 'o3 Mini',
            'prompt_tokens' => 30,
            'completion_tokens' => 50,
            'reasoning_tokens' => 35,
            'finish_reason' => 'stop',
            'has_tool_calls' => false,
        ],
    ],
    'multiple choices' => [
        'openai/completion_multiple_choices.json',
        'gpt-4o',
        [
            'model' => 'GPT-4o',
            'prompt_tokens' => 12,
            'completion_tokens' => 45,
            'content_contains' => ['Rayleigh scattering', 'Blue light scatters', 'Sunlight interacts'],
        ],
    ],
]);

/*
|--------------------------------------------------------------------------
| Batch Processing Tests
|--------------------------------------------------------------------------
*/

it('processes complete openai batch workflow', function () {
    $batchLines = $this->loadMockResponse('openai/batch.jsonl');

    $processedCount = 0;
    $totalCost = 0;

    foreach ($batchLines as $batchItem) {
        if ($batchItem['error'] !== null) {
            continue;
        }

        $handler = new TextHandler;
        $data = TextHandler::unwrapBatch($batchItem);
        $metrics = $handler->extractMetrics([], $data);
        $model = $handler->extractModelFromResponse($data);
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

        expect($record)->toBeInstanceOf(SpectraRequest::class)
            ->and($record->status_code)->toBe(200)
            ->and($record->provider)->toBe('openai')
            ->and($record->model)->toBe('GPT-4o')
            ->and($record->prompt_tokens)->toBeGreaterThan(0)
            ->and($record->completion_tokens)->toBeGreaterThan(0);

        $processedCount++;
        $totalCost += $record->total_cost_in_cents;
    }

    expect($processedCount)->toBe(2)
        ->and($totalCost)->toBeGreaterThan(0);

    $records = SpectraRequest::where('provider', 'openai')->get();
    expect($records)->toHaveCount(2);
});

/*
|--------------------------------------------------------------------------
| Streaming Tests
|--------------------------------------------------------------------------
*/

it('records streaming response to database', function () {
    $tracker = Spectra::stream('openai', 'gpt-4o');

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

    $dbRecord = SpectraRequest::find($record->id);
    expect($dbRecord)->not->toBeNull()
        ->and($dbRecord->prompt_tokens)->toBe(15);
});

it('records openai responses api streaming to database', function () {
    $tracker = Spectra::stream('openai', 'gpt-4o');

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

    $chunks = [
        ['choices' => [['delta' => ['content' => 'Test response']]]],
        ['choices' => [['finish_reason' => 'stop']]],
    ];

    foreach ($tracker->track($chunks) as $text) {
        // consume
    }

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
| Cost Calculation Tests
|--------------------------------------------------------------------------
*/

it('calculates correct cost for provider', function (string $provider, string $model, string $fixture, float $minCost, float $maxCost) {
    $response = $this->loadMockResponse($fixture);

    Spectra::track($provider, $model, function () use ($response) {
        return $response;
    });

    $record = SpectraRequest::latest()->first();

    expect($record->total_cost_in_cents)->toBeGreaterThan($minCost)
        ->and($record->total_cost_in_cents)->toBeLessThan($maxCost);
})->with([
    'openai gpt-4.1' => ['openai', 'gpt-4.1', 'openai/completion.json', 0, 1],
    'anthropic claude-sonnet-4-5' => ['anthropic', 'claude-sonnet-4-5-20250929', 'claude/message.json', 0.4, 0.6],
    'google gemini-2.0-flash' => ['google', 'gemini-2.0-flash', 'google/response.json', 0, 0.01],
]);

/*
|--------------------------------------------------------------------------
| Pricing Tier Tests
|--------------------------------------------------------------------------
*/

it('applies batch pricing tier for openai batch requests', function () {
    $usage = [
        'prompt_tokens' => 1000,
        'completion_tokens' => 500,
    ];

    $context = Spectra::startRequest('openai', 'gpt-4o', [
        'pricing_tier' => 'batch',
    ]);

    $batchRecord = Spectra::recordSuccess($context, ['content' => 'test'], $usage);

    $context2 = Spectra::startRequest('openai', 'gpt-4o', [
        'pricing_tier' => 'standard',
    ]);

    $standardRecord = Spectra::recordSuccess($context2, ['content' => 'test'], $usage);

    expect($batchRecord->total_cost_in_cents)->toBeLessThan($standardRecord->total_cost_in_cents)
        ->and($batchRecord->prompt_tokens)->toBe($standardRecord->prompt_tokens)
        ->and($batchRecord->completion_tokens)->toBe($standardRecord->completion_tokens);

    $ratio = $batchRecord->total_cost_in_cents / $standardRecord->total_cost_in_cents;
    expect($ratio)->toBeLessThan(0.6);
});

it('applies priority pricing tier for openai requests', function () {
    $response = $this->loadMockResponse('openai/completion.json');

    Spectra::track('openai', 'gpt-4o', function () use ($response) {
        return $response;
    }, ['pricing_tier' => 'standard']);

    $standardRecord = SpectraRequest::latest()->first();
    $standardCost = $standardRecord->total_cost_in_cents;

    Spectra::track('openai', 'gpt-4o', function () use ($response) {
        return $response;
    }, ['pricing_tier' => 'priority']);

    $priorityRecord = SpectraRequest::latest()->first();
    $priorityCost = $priorityRecord->total_cost_in_cents;

    expect($priorityCost)->toBeGreaterThan($standardCost);
});

/*
|--------------------------------------------------------------------------
| Reasoning Effort Tests
|--------------------------------------------------------------------------
*/

it('extracts reasoning_effort from responses api response body', function () {
    $response = $this->loadMockResponse('openai/response_with_reasoning.json');

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
    $response = $this->loadMockResponse('openai/completion.json');

    $context = Spectra::startRequest('openai', 'o3-mini');
    $context->requestData = ['reasoning_effort' => 'high'];

    Spectra::recordSuccess($context, $response, [
        'prompt_tokens' => 19,
        'completion_tokens' => 10,
    ]);

    $record = SpectraRequest::latest()->first();

    expect($record)->not->toBeNull()
        ->and($record->reasoning_effort)->toBe('high')
        ->and($record->is_reasoning)->toBeTrue();
});

it('prefers request data reasoning_effort over response body', function () {
    $response = $this->loadMockResponse('openai/response_with_reasoning.json');

    $context = Spectra::startRequest('openai', 'gpt-5');
    $context->requestData = ['reasoning_effort' => 'high'];

    Spectra::recordSuccess($context, $response, [
        'prompt_tokens' => 50,
        'completion_tokens' => 120,
    ]);

    $record = SpectraRequest::latest()->first();

    expect($record->reasoning_effort)->toBe('high');
});

it('detects reasoning from output blocks even when reasoning_tokens is zero', function () {
    $response = $this->loadMockResponse('openai/response_reasoning_zero_tokens.json');

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

it('counts tool calls by type', function (string $fixture, string $model, array $expectedCounts, string $expectedFinishReason) {
    $response = $this->loadMockResponse($fixture);

    Spectra::track('openai', $model, function () use ($response) {
        return $response;
    });

    $record = SpectraRequest::latest()->first();

    expect($record)->not->toBeNull()
        ->and($record->has_tool_calls)->toBeTrue()
        ->and($record->tool_call_counts)->toBeArray()
        ->and($record->tool_call_counts)->toBe($expectedCounts)
        ->and($record->finish_reason)->toBe($expectedFinishReason);
})->with([
    'responses api' => [
        'openai/response_with_tool_calls.json',
        'o3-deep-research',
        ['web_search_call' => 3, 'code_interpreter_call' => 1, 'function_call' => 2],
        'completed',
    ],
    'completions api multiple' => [
        'openai/completion_multiple_tool_calls.json',
        'gpt-4.1',
        ['function' => 3],
        'tool_calls',
    ],
    'completions api single' => [
        'openai/completion_tool_calls.json',
        'gpt-4.1',
        ['function' => 1],
        'tool_calls',
    ],
]);

it('adds tool call cost surcharge to total cost', function () {
    $response = $this->loadMockResponse('openai/response_with_tool_calls.json');

    Spectra::track('openai', 'o3-deep-research', function () use ($response) {
        return $response;
    });

    $record = SpectraRequest::latest()->first();

    expect($record)->not->toBeNull()
        ->and($record->total_cost_in_cents)->toBeGreaterThanOrEqual(6.0);
});

it('stores null tool_call_counts when no tool calls present', function () {
    $response = $this->loadMockResponse('openai/completion.json');

    Spectra::track('openai', 'gpt-4.1', function () use ($response) {
        return $response;
    });

    $record = SpectraRequest::latest()->first();

    expect($record)->not->toBeNull()
        ->and($record->has_tool_calls)->toBeFalse()
        ->and($record->tool_call_counts)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| TTS Pricing Tests
|--------------------------------------------------------------------------
*/

it('uses minute-based pricing for tts models', function (string $model) {
    $calculator = app(\Spectra\Support\Pricing\CostCalculator::class);
    $unit = $calculator->getPricingUnit('openai', $model);

    expect($unit)->toBe('minute');
})->with([
    'tts-1' => ['tts-1'],
    'tts-1-hd' => ['tts-1-hd'],
    'gpt-4o-mini-tts' => ['gpt-4o-mini-tts'],
]);

it('calculates tts cost by duration', function () {
    $calculator = app(\Spectra\Support\Pricing\CostCalculator::class);

    $cost = $calculator->calculateByDuration('openai', 'tts-1', 60.0);

    expect($cost['total_cost_in_cents'])->toBe(1.5);
});

it('calculates tts-1-hd cost by duration', function () {
    $calculator = app(\Spectra\Support\Pricing\CostCalculator::class);

    $cost = $calculator->calculateByDuration('openai', 'tts-1-hd', 60.0);

    expect($cost['total_cost_in_cents'])->toBe(3.0);
});

/*
|--------------------------------------------------------------------------
| Tags and Metadata Tests
|--------------------------------------------------------------------------
*/

it('records tags with mock response', function () {
    $response = $this->loadMockResponse('openai/completion.json');

    Spectra::track('openai', 'gpt-4o', function ($context) use ($response) {
        $context->addTag('chat');
        $context->addTag('test');

        return $response;
    });

    $record = SpectraRequest::latest()->first();

    expect($record)->not->toBeNull()
        ->and($record->provider)->toBe('openai')
        ->and($record->status_code)->toBe(200);

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
