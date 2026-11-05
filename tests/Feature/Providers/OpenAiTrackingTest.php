<?php

declare(strict_types=1);

use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;

/**
 * OpenAI responses recorded through Spectra::track() and Spectra::stream(),
 * where no endpoint is known and the handler is picked by the response
 * shape: model, tokens and cost, explicit pricing tiers, reasoning
 * detection, tool call counts and tags added to the context.
 */
it('records a tracked openai response with its model, tokens and cost', function (string $fixture, int $promptTokens, int $completionTokens, float $cost) {
    $response = $this->loadMockResponse($fixture);

    Spectra::track('openai', 'gpt-4.1', fn () => $response);

    $record = SpectraRequest::query()->sole();

    expect($record->provider)->toBe('openai')
        ->and($record->model)->toBe('GPT-4.1')
        ->and($record->model_type)->toBe('text')
        ->and($record->prompt_tokens)->toBe($promptTokens)
        ->and($record->completion_tokens)->toBe($completionTokens)
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta($cost, 1e-9);
})->with([
    // gpt-4.1 standard (in 200, out 800): (19×200 + 10×800) / 1M = 0.0118
    'chat completion' => ['openai/completion.json', 19, 10, 0.0118],
    // (36×200 + 87×800) / 1M = 0.0768
    'responses api' => ['openai/response.json', 36, 87, 0.0768],
]);

it('records a Spectra::stream() chat stream with its usage and cost', function () {
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

    // gpt-4o standard (in 250, out 1000): (15×250 + 5×1000) / 1M = 0.00875
    expect($content)->toBe('Hello, world!')
        ->and($tracker->getTimeToFirstToken())->not->toBeNull()
        ->and(SpectraRequest::query()->find($record->id))->not->toBeNull()
        ->and($record->provider)->toBe('openai')
        ->and($record->model)->toBe('GPT-4o')
        ->and($record->prompt_tokens)->toBe(15)
        ->and($record->completion_tokens)->toBe(5)
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.00875, 1e-9);
});

it('bills a request at the pricing tier passed as an option', function () {
    $context = Spectra::startRequest('openai', 'gpt-4o', ['pricing_tier' => 'batch']);

    $record = Spectra::recordSuccess($context, ['content' => 'test'], ['prompt_tokens' => 1000, 'completion_tokens' => 500]);

    // gpt-4o batch (in 125, out 500): (1000×125 + 500×500) / 1M = 0.375
    expect($record->pricing_tier)->toBe('batch')
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.375, 1e-9);
});

it('prefers the reasoning effort in the request over the one the response echoes', function () {
    $context = Spectra::startRequest('openai', 'gpt-5');
    $context->requestData = ['reasoning_effort' => 'high'];

    Spectra::recordSuccess($context, $this->loadMockResponse('openai/response_with_reasoning.json'), [
        'prompt_tokens' => 50,
        'completion_tokens' => 120,
    ]);

    expect(SpectraRequest::query()->sole()->reasoning_effort)->toBe('high');
});

it('marks a response as reasoning from its reasoning output block alone', function () {
    $response = $this->loadMockResponse('openai/response_reasoning_zero_tokens.json');
    unset($response['reasoning']);

    Spectra::track('openai', 'gpt-5.2-codex', fn () => $response);

    $record = SpectraRequest::query()->sole();

    expect($record->reasoning_tokens)->toBe(0)
        ->and($record->reasoning_effort)->toBeNull()
        ->and($record->is_reasoning)->toBeTrue();
});

it('counts chat completion tool calls by type', function () {
    $response = $this->loadMockResponse('openai/completion_multiple_tool_calls.json');

    Spectra::track('openai', 'gpt-4.1', fn () => $response);

    $record = SpectraRequest::query()->sole();

    expect($record->has_tool_calls)->toBeTrue()
        ->and($record->tool_call_counts)->toEqual(['function' => 3])
        ->and($record->finish_reason)->toBe('tool_calls');
});

it('stores the tags added to the context inside track()', function () {
    $response = $this->loadMockResponse('openai/completion.json');

    Spectra::track('openai', 'gpt-4o', function ($context) use ($response) {
        $context->addTag('chat');
        $context->addTag('test');

        return $response;
    });

    expect(SpectraRequest::query()->sole()->tags->pluck('name')->all())->toEqualCanonicalizing(['chat', 'test']);
});
