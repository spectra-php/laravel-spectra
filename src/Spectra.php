<?php

declare(strict_types=1);

namespace Spectra;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Macroable;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ScoreSource;
use Spectra\Models\SpectraRequest;
use Spectra\Models\SpectraScore;
use Spectra\Support\Query\CostQueryBuilder;
use Spectra\Support\Query\UsageQueryBuilder;
use Spectra\Support\Redaction\Redactor;
use Spectra\Support\Scoring\ScoreRecorder;
use Spectra\Support\Tracking\RequestContext;
use Spectra\Support\Tracking\RequestPersister;
use Spectra\Support\Tracking\ResponseProcessor;
use Spectra\Support\Tracking\SpanStack;
use Spectra\Support\Tracking\StreamingTracker;
use Spectra\Support\Tracking\TraceContext;
use Throwable;

/**
 * The scoped tracking manager behind the `Spectra` facade.
 *
 * It starts and records request contexts for the watchers, the Guzzle
 * middleware and manual `track()` / `stream()` calls, and holds the globals
 * (tags, trackable, trace id, pricing tier, metadata) applied to every request
 * of the current request, job or command. It is scoped, not a singleton, so
 * those globals never leak between Octane requests or queued jobs.
 */
class Spectra
{
    use Macroable;

    protected Application $app;

    protected ?RequestContext $currentContext = null;

    /** @var array<int, string> */
    protected array $globalTags = [];

    /**
     * Tags of the withTags() callbacks running right now, outermost first.
     *
     * @var array<int, string>
     */
    protected array $scopedTags = [];

    protected ?string $globalTrackableType = null;

    protected string|int|null $globalTrackableId = null;

    protected ?string $globalPricingTier = null;

    protected ?string $globalTraceId = null;

    /** @var array<string, mixed> */
    protected array $globalMetadata = [];

    protected ?RequestContext $pendingStreamContext = null;

    /**
     * Create a new Spectra instance.
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Determine if tracking is enabled in the config.
     */
    public function isEnabled(): bool
    {
        return (bool) config('spectra.enabled', true);
    }

    /**
     * Start a request context with the globals, tags, trackable and trace applied.
     *
     * @param  array<string, mixed>  $options
     */
    public function startRequest(string $provider, string $model, array $options = []): RequestContext
    {
        // Options first, so the merged tags/metadata and the global fallbacks
        // below are not overwritten by the raw per-request values.
        $traceId = $options['trace_id'] ?? $this->globalTraceId ?? $this->requestTraceId();

        $context = new RequestContext([
            ...$options,
            'provider' => $provider,
            'model' => $model,
            'tags' => $this->mergedTags($options['tags'] ?? []),
            'metadata' => array_merge($this->globalMetadata, $options['metadata'] ?? []),
            'trackable_type' => $options['trackable_type'] ?? $this->globalTrackableType,
            'trackable_id' => $options['trackable_id'] ?? $this->globalTrackableId,
            'trace_id' => $traceId,
            'parent_span_id' => $options['parent_span_id'] ?? $this->parentSpanIdFor($traceId, $options),
        ]);

        if ($this->globalPricingTier && $context->pricingTier === null) {
            $context->withPricingTier($this->globalPricingTier);
        }

        $this->currentContext = $context;

        return $context;
    }

    /**
     * Complete the context with the processed response and usage, and persist it.
     *
     * @param  TokenMetrics|array<string, mixed>  $usage
     */
    public function recordSuccess(RequestContext $context, mixed $response, TokenMetrics|array $usage = []): SpectraRequest
    {
        $result = app(ResponseProcessor::class)->processResponse($context, $response);

        if ($result !== null) {
            [$processedResponse, $processedUsage] = $result;
            $response = $processedResponse;

            if (blank($usage) && $processedUsage !== null) {
                $usage = $processedUsage;
            }
        }

        $context->complete($response, $usage);

        return $this->getPersister()->persist($context);
    }

    /**
     * Mark the context as failed with the exception and persist it.
     */
    public function recordFailure(
        RequestContext $context,
        Throwable $exception,
        ?int $httpStatus = null
    ): SpectraRequest {
        $context->fail($exception, $httpStatus);

        return $this->getPersister()->persist($context);
    }

    /**
     * Run the callback as a tracked AI request, and return what it returns.
     *
     * @param  array<string, mixed>  $options
     */
    public function track(
        string $provider,
        string $model,
        callable $callback,
        array $options = []
    ): mixed {
        if (! $this->isEnabled()) {
            return $callback(new RequestContext(['provider' => $provider, 'model' => $model]));
        }

        $context = $this->startRequest($provider, $model, $options);

        try {
            $result = $callback($context);
        } catch (Throwable $e) {
            rescue(fn () => $this->recordFailure($context, $e));

            throw $e;
        }

        // Only the callback's own exception may reach the caller: a tracking
        // failure must not cost them the result they already have.
        rescue(fn () => $this->recordSuccess($context, $this->toRawResponse($result)));

        return $result;
    }

    /**
     * Run the callback as a named span of the current trace, and return what
     * it returns.
     *
     * AI calls and spans inside the callback become its children, as do the
     * queued jobs it dispatches. The span records its start, its end and
     * whether the callback threw; the exception is re-thrown unchanged.
     * Outside a trace, the span starts one for the callback's duration.
     *
     *   $answer = Spectra::span('answer-question', fn () => $agent->run($question), [
     *       'question_length' => strlen($question),
     *   ]);
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @param  array<string, mixed>  $attributes  Stored with the span and exported to OpenTelemetry
     * @return TResult
     */
    public function span(string $name, callable $callback, array $attributes = []): mixed
    {
        if (! $this->isEnabled()) {
            return $callback();
        }

        $startedTrace = false;
        $traceId = $this->currentTraceId();

        if ($traceId === null) {
            $traceId = (string) Str::uuid();
            $this->globalTraceId = $traceId;
            $startedTrace = true;
        }

        $stack = $this->app->make(SpanStack::class);
        $spanId = rescue(fn () => $stack->open($name, $traceId, $this->parentSpanIdFor($traceId, [], withSpans: false), $attributes));

        try {
            $result = $callback();
        } catch (Throwable $e) {
            if ($spanId !== null) {
                rescue(fn () => $stack->close($spanId, $e));
            }

            throw $e;
        } finally {
            if ($startedTrace) {
                $this->globalTraceId = null;
            }
        }

        if ($spanId !== null) {
            rescue(fn () => $stack->close($spanId));
        }

        return $result;
    }

    /**
     * Record a score on an AI call, a trace or a conversation: a user's
     * thumbs up, a rating, a model's judgement or a reviewer's label.
     *
     * The value's type sets the score's: true/false is a yes/no score, a
     * number is a numeric one, a string is a category. Without a call,
     * trace or conversation, the score goes to the current trace.
     *
     *   Spectra::score('helpful', true, traceId: $traceId, source: ScoreSource::User);
     *   Spectra::score('accuracy', 0.82, request: $requestId, source: ScoreSource::Model);
     *   Spectra::score('sentiment', 'positive', conversationId: $chat->id);
     *
     * Returns the saved score, or null when it could not be saved (Spectra
     * disabled, the scores migration not run yet, or a database error,
     * which is reported). A wrong argument throws an InvalidArgumentException.
     *
     * @param  SpectraRequest|string|null  $request  The call, or its id ($context->id inside Spectra::track())
     * @param  array<string, mixed>  $metadata
     */
    public function score(
        string $name,
        int|float|bool|string $value,
        SpectraRequest|string|null $request = null,
        ?string $traceId = null,
        ?string $conversationId = null,
        ?string $comment = null,
        ScoreSource|string $source = ScoreSource::Api,
        ?Model $author = null,
        array $metadata = [],
    ): ?SpectraScore {
        if ($request === null && $traceId === null && $conversationId === null) {
            $traceId = $this->currentTraceId();
        }

        return $this->app->make(ScoreRecorder::class)->record(
            $name, $value, $request, $traceId, $conversationId, $comment, $source, $author, $metadata,
        );
    }

    /**
     * Redact every stored string with this callback too, after the built-in
     * and custom patterns, when `spectra.storage.redaction.enabled` is on.
     * Call it once, from a service provider's boot method.
     *
     *   Spectra::redactUsing(fn (string $text) => preg_replace('/ACC-\d{8}/', '[REDACTED:account]', $text));
     *
     * @param  Closure(string): string  $callback
     */
    public function redactUsing(Closure $callback): self
    {
        $this->app->make(Redactor::class)->using($callback);

        return $this;
    }

    /**
     * Create a streaming tracker for tracking streaming AI responses.
     *
     * Provider and model are optional - they will be auto-detected from the stream.
     *
     * Usage:
     *   $tracker = Spectra::stream();
     *   foreach ($tracker->track($stream) as $chunk) {
     *       echo $chunk;
     *   }
     *   $result = $tracker->finish();
     *
     * @param  array<string, mixed>  $options
     */
    public function stream(?string $provider = null, ?string $model = null, array $options = []): StreamingTracker
    {
        return new StreamingTracker($provider, $model, $options);
    }

    /**
     * Add tags to every request tracked for the rest of the request, job or command.
     *
     * @param  array<int, string>  $tags
     */
    public function addGlobalTags(array $tags): self
    {
        $this->globalTags = array_merge($this->globalTags, $tags);

        return $this;
    }

    /**
     * Tag the requests tracked while the callback runs, then drop the tags
     * again. Calls nest: an inner call adds its tags to the outer ones.
     * Unlike global tags, they apply only to the requests sent inside the
     * callback, so one request or job can tag each of its AI calls apart.
     *
     * @template TReturn
     *
     * @param  array<int, string>  $tags
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function withTags(array $tags, callable $callback): mixed
    {
        $previous = $this->scopedTags;
        $this->scopedTags = array_merge($this->scopedTags, $tags);

        try {
            return $callback();
        } finally {
            $this->scopedTags = $previous;
        }
    }

    /**
     * The tags a request tracked right now would get: the inherited, global
     * and scoped tags. Queued jobs dispatched now inherit them.
     *
     * @return array<int, string>
     */
    public function currentTags(): array
    {
        return $this->mergedTags([]);
    }

    /**
     * Headers that carry the current trace and tags to another of your own
     * services, which continues them with the `spectra.trace` middleware:
     *
     *   Http::withHeaders(Spectra::propagationHeaders())->post($internalUrl, $payload);
     *
     * Send them only to your own services: they name your trace and tags.
     *
     * @return array<string, string>
     */
    public function propagationHeaders(): array
    {
        $baggage = TraceContext::toBaggage($this->currentTraceId(), $this->currentTags());

        return $baggage === null ? [] : ['baggage' => $baggage];
    }

    /**
     * The inherited, global and scoped tags, then the request's own, without repeats.
     *
     * @param  array<int, string>  $tags
     * @return array<int, string>
     */
    protected function mergedTags(array $tags): array
    {
        return collect(TraceContext::tags())
            ->merge($this->globalTags)
            ->merge($this->scopedTags)
            ->merge($tags)
            ->uniqueStrict()
            ->values()
            ->all();
    }

    /**
     * Use this pricing tier for requests that don't set their own.
     */
    public function withPricingTier(string $tier): self
    {
        $this->globalPricingTier = $tier;

        return $this;
    }

    /**
     * Use this trace id for the rest of the current request, job, or command.
     * Queued jobs dispatched afterwards keep it too.
     */
    public function withTraceId(string $traceId): self
    {
        $this->globalTraceId = $traceId;

        if (config('spectra.tracking.trace_per_request', true)) {
            TraceContext::set($traceId);
        }

        return $this;
    }

    /**
     * The trace id shared by the current request, job, or command.
     */
    public function currentTraceId(): ?string
    {
        if ($this->globalTraceId !== null) {
            return $this->globalTraceId;
        }

        return config('spectra.tracking.trace_per_request', true) ? TraceContext::traceId() : null;
    }

    /**
     * Start a new trace: the next AI call gets a fresh trace id.
     */
    public function newTrace(): self
    {
        $this->globalTraceId = null;
        TraceContext::reset();

        return $this;
    }

    /**
     * Get the trace id of the current request, job or command, when trace_per_request is on.
     */
    protected function requestTraceId(): ?string
    {
        return config('spectra.tracking.trace_per_request', true) ? TraceContext::traceId() : null;
    }

    /**
     * The span a new AI call or span of the given trace belongs to: the open
     * Spectra::span() block or unit of work (see SpanStack), else the span
     * the work came from.
     *
     * @param  array<string, mixed>  $options
     * @param  bool  $withSpans  false for the upstream span only (SpanStack adds its own)
     */
    protected function parentSpanIdFor(?string $traceId, array $options, bool $withSpans = true): ?string
    {
        $upstream = $this->requestParentSpanId($options);

        // A call sent to a trace of its own doesn't join the spans of this one.
        if (! $withSpans || (isset($options['trace_id']) && $options['trace_id'] !== $this->currentTraceId())) {
            return $upstream;
        }

        return rescue(fn () => $this->app->make(SpanStack::class)->parentFor($traceId, $upstream), $upstream);
    }

    /**
     * The incoming `traceparent` span (or the span that dispatched this job),
     * when this call belongs to that trace.
     *
     * @param  array<string, mixed>  $options
     */
    protected function requestParentSpanId(array $options): ?string
    {
        if (isset($options['trace_id']) || $this->globalTraceId !== null || ! config('spectra.tracking.trace_per_request', true)) {
            return null;
        }

        return TraceContext::parentSpanId();
    }

    /**
     * Add metadata to every request tracked for the rest of the request, job or command.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function withMetadata(array $metadata): self
    {
        $this->globalMetadata = array_merge($this->globalMetadata, $metadata);

        return $this;
    }

    /**
     * Forget the global tags, trackable, pricing tier, trace id and metadata.
     */
    public function clearGlobals(): self
    {
        $this->globalTags = [];
        TraceContext::forgetTags();
        $this->globalTrackableType = null;
        $this->globalTrackableId = null;
        $this->globalPricingTier = null;

        if ($this->globalTraceId !== null) {
            $this->globalTraceId = null;
            TraceContext::reset();
        }

        $this->globalMetadata = [];

        return $this;
    }

    /**
     * Attribute every request tracked from now on to the given model.
     */
    public function forTrackable(Model $trackable): self
    {
        $this->globalTrackableType = $trackable->getMorphClass();
        $this->globalTrackableId = $trackable->getKey();

        return $this;
    }

    /**
     * Attribute every request tracked from now on to the given user or user id.
     */
    public function forUser(Model|int $user): self
    {
        if (is_int($user)) {
            $user = $this->resolveUserModel($user);
        }

        return $this->forTrackable($user);
    }

    /**
     * Resolve the application's authenticatable user model by primary key.
     */
    protected function resolveUserModel(int $id): Model
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model') ?? 'App\Models\User';

        return $model::query()->findOrFail($id);
    }

    /**
     * Get the context of the request started last.
     */
    public function getCurrentContext(): ?RequestContext
    {
        return $this->currentContext;
    }

    /**
     * Hold the context a stream will pick up when it starts.
     */
    public function setPendingStreamContext(RequestContext $context): void
    {
        $this->pendingStreamContext = $context;
    }

    /**
     * Take the pending stream context, leaving none behind.
     */
    public function consumePendingStreamContext(): ?RequestContext
    {
        $context = $this->pendingStreamContext;
        $this->pendingStreamContext = null;

        return $context;
    }

    /**
     * Forget the pending stream context, but only if it is the given one.
     */
    public function clearPendingStreamContext(RequestContext $context): void
    {
        if ($this->pendingStreamContext === $context) {
            $this->pendingStreamContext = null;
        }
    }

    /**
     * Begin a fluent query on recorded requests: counts, tokens, cost and latency.
     */
    public function usage(): UsageQueryBuilder
    {
        return new UsageQueryBuilder;
    }

    /**
     * Begin a fluent query on the cost of recorded requests.
     */
    public function costs(): CostQueryBuilder
    {
        return new CostQueryBuilder;
    }

    /**
     * Get the persister that stores completed request contexts.
     */
    public function getPersister(): RequestPersister
    {
        return $this->app->make(RequestPersister::class);
    }

    /**
     * Convert the callback's result to an array the response processor can read.
     */
    protected function toRawResponse(mixed $result): mixed
    {
        if (is_array($result)) {
            return $result;
        }

        if (is_object($result) && method_exists($result, 'toArray')) {
            return $result->toArray();
        }

        if (is_object($result)) {
            $json = json_encode($result);

            return $json !== false ? json_decode($json, true) : null;
        }

        return $result;
    }
}
