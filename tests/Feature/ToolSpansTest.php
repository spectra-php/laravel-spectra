<?php

declare(strict_types=1);

/**
 * Tool spans: Spectra::tool() runs a tool as a `tool` span, and with
 * tracking.tool_spans on, the tools a model asked for are drawn in the trace
 * from the results the next call sends back, timed from the end of the
 * previous call to the start of that one.
 */
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Spectra\Enums\SpanKind;
use Spectra\Enums\SpanStatus;
use Spectra\Facades\Spectra;
use Spectra\Integrations\OpenTelemetry\SpanExporter;
use Spectra\Models\SpectraRequest;
use Spectra\Models\SpectraSpan;
use Spectra\Support\Tracking\ToolSpanRecorder;

beforeEach(function () {
    Date::setTestNow('2026-11-04 10:00:00.000');
});

afterEach(function () {
    Date::setTestNow();
});

/**
 * Track one call sending this body, `$seconds` after the previous one.
 *
 * @param  array<string, mixed>  $body
 * @param  array<string, mixed>  $response
 */
function trackAgentStep(array $body, array $response = ['choices' => [['message' => ['content' => 'Done']]]], float $seconds = 0, string $provider = 'openai'): void
{
    if ($seconds > 0) {
        Date::setTestNow(Date::now()->addMilliseconds((int) ($seconds * 1000)));
    }

    Spectra::track($provider, 'gpt-4o', fn () => $response, ['request_data' => $body]);
}

/**
 * The trace's tool spans, earliest first.
 *
 * @return Collection<int, SpectraSpan>
 */
function toolSpans(): Collection
{
    return SpectraSpan::query()->where('kind', SpanKind::Tool->value)->oldest('started_at')->get();
}

/** A Chat Completions body: the question, the model's tool calls, and their results. */
function chatWithToolResults(): array
{
    return ['model' => 'gpt-4o', 'messages' => [
        ['role' => 'user', 'content' => 'Weather in Paris and Rome?'],
        ['role' => 'assistant', 'content' => null, 'tool_calls' => [
            ['id' => 'call_paris', 'type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Paris"}']],
            ['id' => 'call_rome', 'type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Rome"}']],
        ]],
        ['role' => 'tool', 'tool_call_id' => 'call_paris', 'content' => '{"celsius":18}'],
        ['role' => 'tool', 'tool_call_id' => 'call_rome', 'content' => 'Sunny, 24°C'],
    ]];
}

it('runs a tool as a tool span with its arguments and result, under the current span', function () {
    $result = Spectra::span('agent', fn () => Spectra::tool('get_weather', function () {
        Date::setTestNow(Date::now()->addMilliseconds(250));

        return ['celsius' => 18];
    }, ['city' => 'Paris'], id: 'call_1'));

    $tool = toolSpans()->sole();
    $agent = SpectraSpan::query()->where('name', 'agent')->sole();

    expect($result)->toBe(['celsius' => 18])
        ->and($tool->name)->toBe('get_weather')
        ->and($tool->parent_span_id)->toBe($agent->span_id)
        ->and($tool->duration_ms)->toBe(250)
        ->and($tool->status)->toBe(SpanStatus::Ok)
        ->and($tool->attributes)->toBe([
            'gen_ai.tool.name' => 'get_weather',
            'gen_ai.tool.call.id' => 'call_1',
            'gen_ai.tool.call.arguments' => ['city' => 'Paris'],
            'gen_ai.tool.call.result' => ['celsius' => 18],
        ]);
});

it('marks a tool that throws as failed, keeps its arguments and re-throws', function () {
    expect(fn () => Spectra::tool('search', fn () => throw new RuntimeException('Index offline'), ['query' => 'refunds']))
        ->toThrow(RuntimeException::class, 'Index offline');

    $tool = toolSpans()->sole();

    expect($tool->status)->toBe(SpanStatus::Error)
        ->and($tool->error_message)->toBe('Index offline')
        ->and($tool->attributes)->toBe(['gen_ai.tool.name' => 'search', 'gen_ai.tool.call.arguments' => ['query' => 'refunds']]);
});

it('stores a tool\'s arguments and result only with store_prompts on and the trace sampled', function () {
    config()->set('spectra.storage.store_prompts', false);
    Spectra::tool('search', fn () => 'three results', ['query' => 'refunds']);

    config()->set('spectra.storage.store_prompts', true);
    config()->set('spectra.storage.sampling.rate', 0.0);
    Spectra::tool('search', fn () => 'three results', ['query' => 'refunds']);

    expect(toolSpans()->pluck('attributes')->all())->toBe([
        ['gen_ai.tool.name' => 'search'],
        ['gen_ai.tool.name' => 'search'],
    ]);
});

it('cuts a long result to 10,000 characters', function () {
    Spectra::tool('fetch_page', fn () => str_repeat('a', 12_000));

    expect(mb_strlen(toolSpans()->sole()->attributes['gen_ai.tool.call.result']))->toBe(ToolSpanRecorder::MAX_CONTENT_LENGTH + 1);
});

it('records no tool spans from requests while tool_spans is off', function () {
    trackAgentStep(['messages' => [['role' => 'user', 'content' => 'Weather?']]]);
    trackAgentStep(chatWithToolResults(), seconds: 1);

    expect(toolSpans())->toBeEmpty();
});

describe('with tool_spans on', function () {
    beforeEach(function () {
        config()->set('spectra.tracking.tool_spans', true);
    });

    it('draws each tool result a Chat Completions call sends back, between the two calls', function () {
        Spectra::span('agent', function () {
            trackAgentStep(['messages' => [['role' => 'user', 'content' => 'Weather in Paris and Rome?']]]);
            trackAgentStep(chatWithToolResults(), seconds: 1.5);
        });

        $tools = toolSpans();
        $agent = SpectraSpan::query()->where('name', 'agent')->sole();

        expect($tools)->toHaveCount(2)
            ->and($tools->pluck('name')->all())->toBe(['get_weather', 'get_weather'])
            ->and($tools->pluck('duration_ms')->all())->toBe([1500, 1500])
            ->and($tools->pluck('parent_span_id')->unique()->all())->toBe([$agent->span_id])
            ->and($tools[0]->attributes)->toBe([
                'gen_ai.tool.name' => 'get_weather',
                'gen_ai.tool.call.id' => 'call_paris',
                'gen_ai.tool.call.arguments' => ['city' => 'Paris'],
                'gen_ai.tool.call.result' => ['celsius' => 18],
            ])
            ->and($tools[1]->attributes['gen_ai.tool.call.result'])->toBe('Sunny, 24°C');
    });

    it('draws the tools of an agent loop sent through the HTTP client', function () {
        $completion = $this->loadMockResponse('openai/completion.json');
        Http::fake(['api.openai.com/*' => fn () => Http::response([...$completion, 'id' => 'chatcmpl-'.bin2hex(random_bytes(6))])]);

        Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o', 'messages' => [['role' => 'user', 'content' => 'Weather?']]]);
        Date::setTestNow(Date::now()->addSecond());
        Http::post('https://api.openai.com/v1/chat/completions', chatWithToolResults());

        expect(toolSpans()->pluck('name')->all())->toBe(['get_weather', 'get_weather'])
            ->and(toolSpans()->pluck('trace_id')->unique()->all())->toBe([SpectraRequest::query()->value('trace_id')]);
    });

    it('records each tool once, and not for history sent again in a later turn', function () {
        trackAgentStep(['messages' => [['role' => 'user', 'content' => 'Weather in Paris and Rome?']]]);
        trackAgentStep(chatWithToolResults(), seconds: 1);
        // The same results again (a retry), then the next user turn with them in its history.
        trackAgentStep(chatWithToolResults(), seconds: 1);
        trackAgentStep(['messages' => [...chatWithToolResults()['messages'], ['role' => 'assistant', 'content' => 'Paris 18°C, Rome 24°C'], ['role' => 'user', 'content' => 'Thanks']]], seconds: 1);

        expect(toolSpans())->toHaveCount(2);
    });

    it('skips results sent back without an earlier call to time them from', function () {
        trackAgentStep(chatWithToolResults());

        expect(toolSpans())->toBeEmpty();
    });

    it('reads Anthropic tool_result blocks, and fails a result marked is_error', function () {
        trackAgentStep(['messages' => [['role' => 'user', 'content' => 'Refund order 42']]], provider: 'anthropic');
        trackAgentStep(['messages' => [
            ['role' => 'user', 'content' => 'Refund order 42'],
            ['role' => 'assistant', 'content' => [
                ['type' => 'text', 'text' => 'Looking it up.'],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'find_order', 'input' => ['id' => 42]],
                ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'refund', 'input' => ['id' => 42]],
            ]],
            ['role' => 'user', 'content' => [
                ['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => [['type' => 'text', 'text' => 'Order 42: paid']]],
                ['type' => 'tool_result', 'tool_use_id' => 'toolu_2', 'content' => 'Refunds are closed', 'is_error' => true],
            ]],
        ]], seconds: 0.4, provider: 'anthropic');

        $tools = toolSpans();

        expect($tools->pluck('name')->all())->toBe(['find_order', 'refund'])
            ->and($tools[0]->attributes['gen_ai.tool.call.arguments'])->toBe(['id' => 42])
            ->and($tools[0]->attributes['gen_ai.tool.call.result'])->toBe('Order 42: paid')
            ->and($tools[0]->status)->toBe(SpanStatus::Ok)
            ->and($tools[1]->status)->toBe(SpanStatus::Error)
            ->and($tools[1]->attributes['gen_ai.tool.call.result'])->toBe('Refunds are closed');
    });

    it('names Responses API results from the reply that asked, when the request continues it', function () {
        trackAgentStep(['input' => 'Weather in Paris?'], ['id' => 'resp_1', 'output' => [
            ['type' => 'function_call', 'call_id' => 'call_9', 'name' => 'get_weather', 'arguments' => '{"city":"Paris"}'],
        ]]);
        trackAgentStep(['previous_response_id' => 'resp_1', 'input' => [
            ['type' => 'function_call_output', 'call_id' => 'call_9', 'output' => '18°C'],
        ]], seconds: 0.2);

        expect(toolSpans()->sole()->attributes)->toBe([
            'gen_ai.tool.name' => 'get_weather',
            'gen_ai.tool.call.id' => 'call_9',
            'gen_ai.tool.call.arguments' => ['city' => 'Paris'],
            'gen_ai.tool.call.result' => '18°C',
        ]);
    });

    it('reads Gemini functionResponse parts, matched to the call by name', function () {
        trackAgentStep(['contents' => [['role' => 'user', 'parts' => [['text' => 'Weather?']]]]], provider: 'google');
        trackAgentStep(['contents' => [
            ['role' => 'user', 'parts' => [['text' => 'Weather?']]],
            ['role' => 'model', 'parts' => [['functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'Oslo']]]]],
            ['role' => 'user', 'parts' => [['functionResponse' => ['name' => 'get_weather', 'response' => ['celsius' => 4]]]]],
        ]], seconds: 0.3, provider: 'google');

        expect(toolSpans()->sole()->attributes)->toBe([
            'gen_ai.tool.name' => 'get_weather',
            'gen_ai.tool.call.arguments' => ['city' => 'Oslo'],
            'gen_ai.tool.call.result' => ['celsius' => 4],
        ]);
    });

    it('doesn\'t record a tool twice when the app ran it through Spectra::tool()', function () {
        trackAgentStep(['messages' => [['role' => 'user', 'content' => 'Weather in Paris and Rome?']]]);
        Spectra::tool('get_weather', fn () => ['celsius' => 18], ['city' => 'Paris'], id: 'call_paris');
        Spectra::tool('get_weather', fn () => 'Sunny, 24°C', ['city' => 'Rome']);
        trackAgentStep(chatWithToolResults(), seconds: 1);

        expect(toolSpans())->toHaveCount(2);
    });
});

it('exports a tool span as execute_tool, as the OpenTelemetry GenAI conventions name it', function () {
    $span = (new SpanExporter('app', '1.0.0'))->createSpectraSpan([
        'trace_id' => 'trace-1', 'span_id' => 'abc', 'parent_span_id' => null, 'name' => 'get_weather', 'kind' => 'tool',
        'started_at' => now(), 'ended_at' => now(), 'status' => 'ok', 'attributes' => ['gen_ai.tool.name' => 'get_weather', 'gen_ai.tool.call.arguments' => ['city' => 'Paris']],
    ]);

    expect($span['name'])->toBe('execute_tool get_weather')
        ->and($span['kind'])->toBe(1)
        ->and(collect($span['attributes'])->pluck('value', 'key')->all())->toMatchArray([
            'gen_ai.operation.name' => ['stringValue' => 'execute_tool'],
            'gen_ai.tool.call.arguments' => ['stringValue' => '{"city":"Paris"}'],
        ]);
});

it('forgets the tools wrapped in old traces, so a long-running process does not grow', function () {
    $recorder = app(ToolSpanRecorder::class);

    // Tools whose next call happens in another process: no call here ever trims them by trace.
    foreach (range(1, 150) as $trace) {
        $recorder->toolWrapped("trace-{$trace}", 'get_weather', "call-{$trace}");
        $recorder->toolWrapped("trace-{$trace}", 'get_time', null);
    }

    $recorded = (new ReflectionProperty($recorder, 'recorded'))->getValue($recorder);
    $wrapped = (new ReflectionProperty($recorder, 'wrapped'))->getValue($recorder);

    expect($recorded)->toHaveCount(100)
        ->and($wrapped)->toHaveCount(100)
        ->and(array_key_last($recorded))->toBe('trace-150')
        ->and($recorded)->not->toHaveKey('trace-1');
});

it('remembers tool runs in the fake', function () {
    Spectra::fake();

    $result = Spectra::tool('get_weather', fn () => 18, ['city' => 'Paris'], id: 'call_1');

    expect($result)->toBe(18);
    Spectra::assertToolCalled('get_weather', fn (array $arguments) => $arguments['city'] === 'Paris');
    Spectra::assertSpanRecorded('get_weather', fn (array $span) => $span['kind'] === 'tool' && $span['result'] === 18);
});
