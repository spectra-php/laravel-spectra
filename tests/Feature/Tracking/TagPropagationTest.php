<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Tracking\TraceContext;

/**
 * Tags carry on like the trace: a queued job inherits the tags of the work
 * that dispatched it, and another of your services continues the trace and
 * tags sent with Spectra::propagationHeaders() (the `spectra.trace` middleware).
 */

/** What a queue worker does with a job payload: a fresh scope, then the payload's context. */
function runAsQueuedJob(?array $payload, Closure $job): mixed
{
    app()->forgetScopedInstances();
    Facade::clearResolvedInstances();
    Context::flush();
    Context::hydrate($payload);

    return $job();
}

function fakeOpenAiChat(): void
{
    Http::fake(['api.openai.com/*' => Http::response([
        'id' => 'chatcmpl-tags', 'object' => 'chat.completion', 'model' => 'gpt-4o', 'choices' => [],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
    ])]);
}

function tagsOfLatestRequest(): array
{
    return SpectraRequest::with('tags')->latest('created_at')->firstOrFail()->tags->pluck('name')->sort()->values()->all();
}

it('gives a queued job the tags around its dispatch', function () {
    Spectra::addGlobalTags(['web']);

    $payload = Spectra::withTags(['chat-reply'], fn () => Context::dehydrate());
    $outside = Context::dehydrate();

    expect(runAsQueuedJob($payload, fn () => Spectra::withTags(['video-generation'], fn () => Spectra::startRequest('falai', 'kling')->tags)))
        ->toBe(['web', 'chat-reply', 'video-generation'])
        ->and(runAsQueuedJob($outside, fn () => Spectra::startRequest('falai', 'kling')->tags))->toBe(['web']);
});

it('keeps the inherited tags down a chain of jobs', function () {
    $first = Spectra::withTags(['chat-reply'], fn () => Context::dehydrate());

    // The job re-dispatches itself, outside any withTags() of its own.
    $second = runAsQueuedJob($first, fn () => Context::dehydrate());

    expect(runAsQueuedJob($second, fn () => Spectra::startRequest('falai', 'kling')->tags))->toBe(['chat-reply']);
});

it('does not give jobs the tags when tag propagation is off', function () {
    config()->set('spectra.tracking.propagate_tags', false);
    TraceContext::addTags(['upstream']);

    $payload = Spectra::withTags(['chat-reply'], fn () => Context::dehydrate());

    expect(runAsQueuedJob($payload, fn () => Spectra::startRequest('falai', 'kling')->tags))->toBe([]);
});

it('forgets inherited tags with clearGlobals()', function () {
    TraceContext::addTags(['upstream']);

    Spectra::clearGlobals();

    expect(Spectra::startRequest('openai', 'gpt-4o')->tags)->toBe([]);
});

it('builds a baggage header with the trace and the current tags', function () {
    Spectra::withTraceId('0b8f1c2e-5d4a-4c3b-9e7f-112233445566');
    Spectra::addGlobalTags(['web']);

    $headers = Spectra::withTags(['chat reply', 'a,b'], fn () => Spectra::propagationHeaders());

    expect($headers)->toBe(['baggage' => 'spectra.trace_id=0b8f1c2e-5d4a-4c3b-9e7f-112233445566,spectra.tags=web%2Cchat%20reply%2Ca%2Cb']);
});

it('sends no header when there is no trace and no tag', function () {
    config()->set('spectra.tracking.trace_per_request', false);

    expect(Spectra::propagationHeaders())->toBe([]);
});

it('continues the caller trace and tags in a route with the spectra.trace middleware', function () {
    fakeOpenAiChat();
    Route::post('/internal/tool', function () {
        Spectra::withTags(['tool'], fn () => Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o']));

        return response()->json(['job' => Context::dehydrate()]);
    })->middleware('spectra.trace');

    $job = $this->withHeaders(['baggage' => 'other=1;prop, spectra.tags=chat-reply%2Cguardrails ,spectra.trace_id=4BF92F35-77B3-4DA6-A3CE-929D0E0E4736'])
        ->postJson('/internal/tool')
        ->json('job');

    $row = SpectraRequest::with('tags')->sole();

    expect($row->trace_id)->toBe('4bf92f35-77b3-4da6-a3ce-929d0e0e4736')
        ->and($row->parent_span_id)->toBeNull()
        ->and($row->tags->pluck('name')->sort()->values()->all())->toBe(['chat-reply', 'guardrails', 'tool']);

    // A job the route dispatched, outside its withTags(), joins the caller too.
    $context = runAsQueuedJob($job, fn () => Spectra::startRequest('falai', 'kling'));

    expect($context->traceId)->toBe('4bf92f35-77b3-4da6-a3ce-929d0e0e4736')
        ->and($context->tags)->toBe(['chat-reply', 'guardrails']);
});

it('round-trips propagationHeaders() through the middleware', function () {
    fakeOpenAiChat();
    Route::post('/internal/tool', fn () => Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o'])->json())
        ->middleware('spectra.trace');

    $headers = Spectra::withTags(['chat-reply'], fn () => Spectra::propagationHeaders());
    $callerTrace = Spectra::currentTraceId();

    // The receiving service runs in its own request.
    app()->forgetScopedInstances();
    Facade::clearResolvedInstances();
    Context::flush();

    $this->withHeaders($headers)->postJson('/internal/tool')->assertOk();

    expect(SpectraRequest::sole()->trace_id)->toBe($callerTrace)
        ->and(tagsOfLatestRequest())->toBe(['chat-reply']);
});

it('ignores a baggage trace id that is not a UUID, and tags that are blank or too long', function () {
    Route::post('/internal/tool', fn () => response()->json(['trace' => Spectra::currentTraceId(), 'tags' => Spectra::currentTags()]))
        ->middleware('spectra.trace');

    $response = $this->withHeaders(['baggage' => 'spectra.trace_id=not-a-uuid,spectra.tags=ok%2C%20%2C'.str_repeat('x', 256)])
        ->postJson('/internal/tool');

    expect($response->json('trace'))->not->toBe('not-a-uuid')
        ->and($response->json('tags'))->toBe(['ok']);
});

it('ignores baggage on routes without the middleware', function () {
    Route::post('/public', fn () => response()->json(['trace' => Spectra::currentTraceId(), 'tags' => Spectra::currentTags()]));

    $response = $this->withHeaders(['baggage' => 'spectra.trace_id=4bf92f35-77b3-4da6-a3ce-929d0e0e4736,spectra.tags=injected'])
        ->postJson('/public');

    expect($response->json('trace'))->not->toBe('4bf92f35-77b3-4da6-a3ce-929d0e0e4736')
        ->and($response->json('tags'))->toBe([]);
});
