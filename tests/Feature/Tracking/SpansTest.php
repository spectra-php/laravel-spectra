<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spectra\Enums\SpanKind;
use Spectra\Enums\SpanStatus;
use Spectra\Facades\Spectra;
use Spectra\Integrations\OpenTelemetry\DefaultSpanBuilder;
use Spectra\Models\SpectraRequest;
use Spectra\Models\SpectraSpan;
use Spectra\Support\RequestTransformer;
use Spectra\Tests\Fixtures\CallsAiJob;

/**
 * Spans make a trace a tree: Spectra::span() blocks, and the request, job or
 * command the calls ran in, each with the calls and spans inside it as
 * children.
 */
function servingHttp(bool $http): void
{
    (new ReflectionProperty(app(), 'isRunningInConsole'))->setValue(app(), ! $http);
}

function callOpenAi(): void
{
    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o']);
}

beforeEach(function () {
    config()->set('queue.default', 'sync');
    $completion = $this->loadMockResponse('openai/completion.json');
    // Each call its own response id: a repeated one is read as a poll of the same call.
    Http::fake(['api.openai.com/*' => fn () => Http::response([...$completion, 'id' => 'chatcmpl-'.bin2hex(random_bytes(6))])]);
});

afterEach(function () {
    servingHttp(false);
});

it('records a span with its name, attributes and timing, and returns the result', function () {
    $result = Spectra::span('retrieve-docs', fn () => 'three documents', ['top_k' => 3]);

    $span = SpectraSpan::query()->sole();

    expect($result)->toBe('three documents')
        ->and($span->name)->toBe('retrieve-docs')
        ->and($span->kind)->toBe(SpanKind::Span)
        ->and($span->status)->toBe(SpanStatus::Ok)
        ->and($span->attributes)->toBe(['top_k' => 3])
        ->and($span->trace_id)->toBe(Spectra::currentTraceId())
        ->and($span->ended_at->greaterThanOrEqualTo($span->started_at))->toBeTrue();
});

it('makes the AI calls and spans inside a span its children', function () {
    Spectra::span('answer-question', function () {
        Spectra::span('retrieve-docs', fn () => callOpenAi());
        callOpenAi();
    });

    $outer = SpectraSpan::query()->where('name', 'answer-question')->sole();
    $inner = SpectraSpan::query()->where('name', 'retrieve-docs')->sole();
    [$first, $second] = SpectraRequest::query()->orderBy('started_at')->get()->all();

    expect($inner->parent_span_id)->toBe($outer->span_id)
        ->and($first->parent_span_id)->toBe($inner->span_id)
        ->and($second->parent_span_id)->toBe($outer->span_id)
        ->and($first->span_id)->toHaveLength(16)
        ->and($first->span_id)->not->toBe($second->span_id);
});

it('marks a span that threw as failed and re-throws the exception', function () {
    expect(fn () => Spectra::span('charge-card', fn () => throw new RuntimeException('Card declined')))
        ->toThrow(RuntimeException::class, 'Card declined');

    $span = SpectraSpan::query()->sole();

    expect($span->status)->toBe(SpanStatus::Error)
        ->and($span->error_type)->toBe(RuntimeException::class)
        ->and($span->error_message)->toBe('Card declined');
});

it('starts a trace for a span outside one and ends it with the span', function () {
    config()->set('spectra.tracking.trace_per_request', false);

    Spectra::span('nightly-digest', fn () => callOpenAi());

    $span = SpectraSpan::query()->sole();

    expect(SpectraRequest::query()->sole()->trace_id)->toBe($span->trace_id)
        ->and(Spectra::currentTraceId())->toBeNull();
});

it('gives a queued job that makes AI calls a span with its calls inside', function () {
    dispatch(new CallsAiJob);

    $job = SpectraSpan::query()->sole();

    expect($job->kind)->toBe(SpanKind::Job)
        ->and($job->name)->toBe(CallsAiJob::class)
        ->and(SpectraRequest::query()->sole()->parent_span_id)->toBe($job->span_id);
});

it('stores no span for a job that makes no AI call', function () {
    dispatch(new CallsAiJob(callsAi: false));

    expect(SpectraSpan::query()->count())->toBe(0);
});

it('puts a job dispatched inside a span under that span', function () {
    // A block body: an arrow function would return the PendingDispatch, which
    // dispatches only once it is destroyed, after the span has closed.
    Spectra::span('import-catalog', function () {
        dispatch(new CallsAiJob);
    });

    $span = SpectraSpan::query()->where('name', 'import-catalog')->sole();
    $job = SpectraSpan::query()->where('kind', SpanKind::Job->value)->sole();

    expect($job->parent_span_id)->toBe($span->span_id)
        ->and($job->trace_id)->toBe($span->trace_id);
});

it('gives an Artisan command that makes AI calls a span', function () {
    app(ConsoleKernel::class)->rerouteSymfonyCommandEvents();
    Artisan::command('spectra:digest', fn () => callOpenAi());

    Artisan::call('spectra:digest');

    $command = SpectraSpan::query()->sole();

    expect($command->kind)->toBe(SpanKind::Command)
        ->and($command->name)->toBe('artisan spectra:digest')
        ->and(SpectraRequest::query()->sole()->parent_span_id)->toBe($command->span_id);
});

it('gives an HTTP request that makes AI calls a span under its traceparent', function () {
    servingHttp(true);
    Route::post('/chats/{chat}', function () {
        callOpenAi();

        return 'ok';
    });

    $this->post('/chats/7', [], ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'])->assertOk();

    $request = SpectraSpan::query()->sole();

    expect($request->kind)->toBe(SpanKind::Request)
        ->and($request->name)->toBe('POST /chats/{chat}')
        ->and($request->parent_span_id)->toBe('00f067aa0ba902b7')
        ->and(SpectraRequest::query()->sole()->parent_span_id)->toBe($request->span_id);
});

it('saves the request span too when a synchronous job inside it made the only call', function () {
    servingHttp(true);
    Route::post('/imports', function () {
        dispatch(new CallsAiJob);

        return 'ok';
    });

    $this->post('/imports')->assertOk();

    $request = SpectraSpan::query()->where('kind', SpanKind::Request->value)->sole();
    $job = SpectraSpan::query()->where('kind', SpanKind::Job->value)->sole();

    expect($job->parent_span_id)->toBe($request->span_id);
});

it('stores no root spans when root_spans is off', function () {
    config()->set('spectra.tracking.root_spans', false);

    dispatch(new CallsAiJob);

    expect(SpectraSpan::query()->count())->toBe(0)
        ->and(SpectraRequest::query()->sole()->parent_span_id)->toBeNull();
});

it('keeps recording calls when the spans table does not exist yet', function () {
    Log::spy();
    Schema::connection(config('spectra.storage.connection'))->drop('spectra_spans');

    Spectra::span('first', fn () => callOpenAi());
    Spectra::span('second', fn () => null);

    expect(SpectraRequest::query()->count())->toBe(1);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'spectra_spans'));
});

it('exports a call with its stored span id and a span with its kind and parent', function () {
    config()->set('spectra.integrations.opentelemetry.enabled', true);
    Http::fake([
        'api.openai.com/*' => Http::response($this->loadMockResponse('openai/completion.json')),
        'localhost:4318/*' => Http::response([], 200),
    ]);

    Spectra::span('answer-question', fn () => callOpenAi(), ['tenant' => 'acme']);

    $call = SpectraRequest::query()->sole();
    $span = SpectraSpan::query()->sole();
    $exported = collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), '4318'))
        ->map(fn ($pair) => $pair[0]->data()['resourceSpans'][0]['scopeSpans'][0]['spans'][0])
        ->keyBy('name');

    expect((new DefaultSpanBuilder)->build(app(RequestTransformer::class)->transform($call))['spanId'])->toBe($call->span_id)
        ->and($exported['answer-question'])->toMatchArray(['spanId' => $span->span_id, 'kind' => 1])
        ->and($exported['answer-question']['attributes'])->toContain(['key' => 'tenant', 'value' => ['stringValue' => 'acme']]);
});

it('prunes spans older than the cutoff', function () {
    $this->travelTo(now()->subDays(3));
    Spectra::span('old', fn () => null);
    $this->travelBack();
    Spectra::span('recent', fn () => null);

    $this->artisan('spectra:prune', ['--hours' => 24])->assertSuccessful();

    expect(SpectraSpan::query()->pluck('name')->all())->toBe(['recent']);
});

it('records spans in memory with the fake', function () {
    Spectra::fake();

    $result = Spectra::span('retrieve-docs', fn () => 'docs', ['top_k' => 3]);

    expect($result)->toBe('docs')
        ->and(SpectraSpan::query()->count())->toBe(0);

    Spectra::assertSpanRecorded('retrieve-docs', fn (array $span) => $span['attributes'] === ['top_k' => 3]);
});
