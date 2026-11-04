<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Spectra\Facades\Spectra;
use Spectra\Http\Controllers\Api\ShowRequestController;
use Spectra\Integrations\OpenTelemetry\DefaultSpanBuilder;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Tracking\TraceContext;

/**
 * Every AI call of one unit of work (an HTTP request, a queued job, or a
 * command) shares one trace id, so the dashboard can show them as a chain.
 */
function useIncomingRequest(array $headers): void
{
    $server = [];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    app()->instance('request', Request::create('/chat', 'POST', server: $server));
}

it('gives every AI call in the same request one trace id', function () {
    $completion = $this->loadMockResponse('openai/completion.json');
    // Each call its own response id: a repeated one is read as a poll of the same call.
    Http::fake(['api.openai.com/*' => Http::sequence()
        ->push([...$completion, 'id' => 'chatcmpl-1'])
        ->push([...$completion, 'id' => 'chatcmpl-2'])
        ->push([...$completion, 'id' => 'chatcmpl-3'])]);

    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o']);
    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o']);
    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o']);

    $traceIds = SpectraRequest::query()->pluck('trace_id')->unique();

    expect(SpectraRequest::query()->count())->toBe(3)
        ->and($traceIds)->toHaveCount(1)
        ->and($traceIds->first())->toBe(Spectra::currentTraceId());
});

it('starts a new trace for the next request', function () {
    $first = Spectra::startRequest('openai', 'gpt-4o')->traceId;

    // What Laravel does between two HTTP requests (Octane) or two queued jobs.
    app()->forgetScopedInstances();
    Facade::clearResolvedInstances();

    $second = Spectra::startRequest('openai', 'gpt-4o')->traceId;

    expect($second)->not->toBe($first);
});

it('starts a new trace on demand', function () {
    $first = Spectra::startRequest('openai', 'gpt-4o')->traceId;

    Spectra::newTrace();

    expect(Spectra::startRequest('openai', 'gpt-4o')->traceId)->not->toBe($first);
});

it('keeps the trace id out of the log context', function () {
    Spectra::startRequest('openai', 'gpt-4o');

    expect(Context::all())->not->toHaveKey(TraceContext::TRACE_ID)
        ->and(Context::getHidden(TraceContext::TRACE_ID))->toBeString();
});

it('gives each call its own trace id when one trace per request is off', function () {
    config()->set('spectra.tracking.trace_per_request', false);

    $first = Spectra::startRequest('openai', 'gpt-4o')->traceId;
    $second = Spectra::startRequest('openai', 'gpt-4o')->traceId;

    expect($second)->not->toBe($first)
        ->and(Spectra::currentTraceId())->toBeNull();
});

it('uses a trace id set with withTraceId for the rest of the request', function () {
    Spectra::withTraceId('0b8f1c2e-5d4a-4c3b-9e7f-112233445566');

    expect(Spectra::startRequest('openai', 'gpt-4o')->traceId)->toBe('0b8f1c2e-5d4a-4c3b-9e7f-112233445566')
        ->and(Context::getHidden(TraceContext::TRACE_ID))->toBe('0b8f1c2e-5d4a-4c3b-9e7f-112233445566');
});

it('lets a per-call trace id override the request trace', function () {
    $requestTrace = Spectra::startRequest('openai', 'gpt-4o')->traceId;

    $context = Spectra::startRequest('openai', 'gpt-4o', ['trace_id' => 'b1f7a3c4-0000-4000-8000-000000000001']);

    expect($context->traceId)->toBe('b1f7a3c4-0000-4000-8000-000000000001')
        ->and(Spectra::startRequest('openai', 'gpt-4o')->traceId)->toBe($requestTrace);
});

it('carries the trace into queued jobs dispatched during the request', function () {
    // Dispatched before any AI call: the job must still join this request's trace.
    $payload = Context::dehydrate();
    $requestTrace = Spectra::startRequest('openai', 'gpt-4o')->traceId;

    // The worker: a fresh scope, then the job payload's context is restored.
    app()->forgetScopedInstances();
    Facade::clearResolvedInstances();
    Context::hydrate($payload);

    expect(Spectra::startRequest('anthropic', 'claude-sonnet-4-6')->traceId)->toBe($requestTrace);
});

it('joins the incoming W3C traceparent trace', function () {
    useIncomingRequest(['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']);

    $context = Spectra::startRequest('openai', 'gpt-4o');

    expect($context->traceId)->toBe('4bf92f35-77b3-4da6-a3ce-929d0e0e4736')
        ->and($context->parentSpanId)->toBe('00f067aa0ba902b7');
});

it('stores the traceparent span and exports it as the OpenTelemetry parent', function () {
    useIncomingRequest(['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']);
    Http::fake(['api.openai.com/*' => Http::response($this->loadMockResponse('openai/completion.json'))]);

    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o']);

    $row = SpectraRequest::query()->sole();
    $span = (new DefaultSpanBuilder)->build([...$row->toArray(), 'started_at' => $row->created_at, 'completed_at' => $row->completed_at]);

    expect($row->trace_id)->toBe('4bf92f35-77b3-4da6-a3ce-929d0e0e4736')
        ->and($row->parent_span_id)->toBe('00f067aa0ba902b7')
        ->and($span['traceId'])->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and($span['parentSpanId'])->toBe('00f067aa0ba902b7');
});

it('ignores a traceparent that is not valid', function (string $header) {
    useIncomingRequest(['traceparent' => $header]);

    $context = Spectra::startRequest('openai', 'gpt-4o');

    expect($context->traceId)->not->toBe('00000000-0000-0000-0000-000000000000')
        ->and($context->traceId)->not->toStartWith('4bf92f35')
        ->and($context->parentSpanId)->toBeNull();
})->with([
    'all-zero trace id' => '00-00000000000000000000000000000000-00f067aa0ba902b7-01',
    'all-zero span id' => '00-4bf92f3577b34da6a3ce929d0e0e4736-0000000000000000-01',
    'not hex' => '00-4bf92f3577b34da6a3ce929d0e0e47zz-00f067aa0ba902b7-01',
]);

it('ignores traceparent when that is turned off', function () {
    config()->set('spectra.tracking.use_traceparent', false);
    useIncomingRequest(['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']);

    $context = Spectra::startRequest('openai', 'gpt-4o');

    expect($context->traceId)->not->toBe('4bf92f35-77b3-4da6-a3ce-929d0e0e4736')
        ->and($context->parentSpanId)->toBeNull();
});

it('does not link calls with their own trace id to the traceparent span', function () {
    useIncomingRequest(['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']);

    $context = Spectra::startRequest('openai', 'gpt-4o', ['trace_id' => 'c2d3e4f5-0000-4000-8000-000000000002']);

    expect($context->parentSpanId)->toBeNull();
});

it('returns no trace for a request without a trace id', function () {
    $request = SpectraRequest::factory()->create(['trace_id' => null]);

    expect(app()->call([app(ShowRequestController::class), '__invoke'], ['id' => $request->id])->toArray()['trace'])->toBeNull();
});
