<?php

declare(strict_types=1);

namespace Spectra;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use JsonException;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ScoreSource;
use Spectra\Enums\SpanKind;
use Spectra\Models\SpectraRequest;
use Spectra\Models\SpectraScore;
use Spectra\Support\Annotations\AnnotationQueues;
use Spectra\Support\Dashboards\Dashboards;
use Spectra\Support\Evaluations\Dataset;
use Spectra\Support\Query\CostQueryBuilder;
use Spectra\Support\Query\UsageQueryBuilder;
use Spectra\Support\Redaction\Redactor;
use Spectra\Support\Scoring\ScoreRecorder;
use Spectra\Support\Tracking\RequestContext;
use Spectra\Support\Tracking\RequestPersister;
use Spectra\Support\Tracking\ResponseProcessor;
use Spectra\Support\Tracking\SpanStack;
use Spectra\Support\Tracking\StreamingTracker;
use Spectra\Support\Tracking\ToolSpanRecorder;
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

    /**
     * The prompt template and version set by withPrompt(), for the calls inside it.
     *
     * @var array{name: string, version: ?string}|null
     */
    protected ?array $scopedPrompt = null;

    protected ?string $globalTrackableType = null;

    /** The conversation, and turn, set by forConversation() for the calls tracked from then on. */
    protected ?string $globalConversationId = null;

    protected ?int $globalConversationTurn = null;

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
        // A trace id the call is given is cut to the column's 64 characters.
        $traceId = isset($options['trace_id'])
            ? Str::limit((string) $options['trace_id'], 64, '')
            : ($this->globalTraceId ?? $this->requestTraceId());

        $context = new RequestContext([
            ...$options,
            'provider' => $provider,
            'model' => $model,
            'tags' => $this->mergedTags($options['tags'] ?? []),
            'metadata' => array_merge($this->globalMetadata, $options['metadata'] ?? []),
            'trackable_type' => $options['trackable_type'] ?? $this->globalTrackableType,
            'trackable_id' => $options['trackable_id'] ?? $this->globalTrackableId,
            // A conversation given for the call wins, with its own turn.
            'conversation_id' => $options['conversation_id'] ?? $this->globalConversationId,
            'conversation_turn' => isset($options['conversation_id']) ? ($options['conversation_turn'] ?? null) : ($options['conversation_turn'] ?? $this->globalConversationTurn),
            'trace_id' => $traceId,
            'parent_span_id' => $options['parent_span_id'] ?? $this->parentSpanIdFor($traceId, $options),
            'prompt_name' => $options['prompt_name'] ?? $this->scopedPrompt['name'] ?? null,
            'prompt_version' => $options['prompt_version'] ?? $this->scopedPrompt['version'] ?? null,
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
        return $this->runInSpan($name, SpanKind::Span, $callback, $attributes);
    }

    /**
     * Run a tool the model asked for as a `tool` span of the current trace,
     * and return what it returns.
     *
     * The span stores the tool's name, its call id, its arguments and its
     * result (when `store_prompts` is on and the trace's payloads are
     * sampled), and fails when the tool throws. With `tracking.tool_spans`
     * on, the call that sends the result back doesn't record it again.
     *
     *   $weather = Spectra::tool('get_weather', fn () => $this->weather($city), ['city' => $city], id: $call['id']);
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @param  array<string, mixed>  $arguments  The arguments the model passed
     * @param  string|null  $id  The tool call id from the model's reply
     * @return TResult
     */
    public function tool(string $name, callable $callback, array $arguments = [], ?string $id = null): mixed
    {
        $recorder = $this->app->make(ToolSpanRecorder::class);

        return $this->runInSpan($name, SpanKind::Tool, $callback, [], function (string $traceId, mixed $result, ?Throwable $error) use ($recorder, $name, $id, $arguments): array {
            $recorder->toolWrapped($traceId, $name, $id);

            return $recorder->attributes($name, $id, $traceId, $error !== null, $arguments, $result, hasResult: $error === null);
        });
    }

    /**
     * Run the callback inside a new span of the current trace (starting a
     * trace for it when there is none), and return what it returns. The
     * callback's exception is re-thrown unchanged.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @param  array<string, mixed>  $attributes
     * @param  (Closure(string, mixed, ?Throwable): array<string, mixed>)|null  $finish  Attributes known at the end, from the trace id, result and error
     * @return TResult
     */
    protected function runInSpan(string $name, SpanKind $kind, callable $callback, array $attributes, ?Closure $finish = null): mixed
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
        $spanId = rescue(fn () => $stack->open($name, $traceId, $this->parentSpanIdFor($traceId, [], withSpans: false), $attributes, $kind));

        try {
            $result = $callback();
        } catch (Throwable $e) {
            if ($spanId !== null) {
                rescue(fn () => $stack->close($spanId, $e, $finish instanceof Closure ? $finish($traceId, null, $e) : []));
            }

            throw $e;
        } finally {
            if ($startedTrace) {
                $this->globalTraceId = null;
            }
        }

        if ($spanId !== null) {
            rescue(fn () => $stack->close($spanId, null, $finish instanceof Closure ? $finish($traceId, $result, null) : []));
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
     * disabled or recording paused, the scores migration not run yet, or a database error,
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
     * Get a dataset of examples to run evaluations over, made the first time
     * something is added to it.
     *
     *   Spectra::dataset('refund-questions')->add(['question' => 'Can I get my money back?'], expected: 'Yes, within 30 days.');
     *
     * @throws InvalidArgumentException when the name is empty or longer than 100 characters
     */
    public function dataset(string $name): Dataset
    {
        $name = Str::trim($name);

        throw_if(blank($name) || Str::length($name) > 100, InvalidArgumentException::class, 'A dataset needs a name of 1 to 100 characters.');

        return new Dataset($name);
    }

    /**
     * Get the shared dashboards: read-only pages for a trackable, which logs in
     * through your app and sees only its own data.
     *
     *   $url = Spectra::dashboard()->create('Usage', $user, widgets: ['stats', 'recent_requests'])->url();
     *   Spectra::dashboard()->list($user);
     *   Spectra::dashboard()->delete($dashboard);
     */
    public function dashboard(): Dashboards
    {
        return $this->app->make(Dashboards::class);
    }

    /**
     * Put a trace in a review queue of `spectra.annotations.queues`, for a
     * person to label on the dashboard's Review screen. Without a trace id,
     * the current trace goes in. A trace already in the queue keeps its
     * place. Returns whether the trace is in the queue.
     *
     *   Spectra::queueForReview('support-review');
     *
     * @throws InvalidArgumentException when the queue isn't configured, or there is no trace
     */
    public function queueForReview(string $queue, ?string $traceId = null): bool
    {
        $queues = $this->app->make(AnnotationQueues::class);
        $queues->find($queue);
        $traceId ??= $this->currentTraceId();

        throw_if(blank($traceId), InvalidArgumentException::class, 'There is no trace to queue for review. Pass a trace id outside a request, job or command.');

        return $queues->add($queue, $traceId);
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
     * A stream that throws inside the loop is recorded as a failed request
     * when the tracker is released, or at once with `$tracker->fail($e)`.
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
     * Run the callback with every AI call inside it tagged with this prompt
     * template and version, so the dashboard's Prompts screen can compare
     * versions on cost, errors, latency and scores.
     *
     *   $reply = Spectra::withPrompt('support-answer', 'v3', fn () => $agent->reply($message));
     */
    public function withPrompt(string $name, ?string $version, callable $callback): mixed
    {
        $previous = $this->scopedPrompt;
        $this->scopedPrompt = ['name' => $name, 'version' => $version];

        try {
            return $callback();
        } finally {
            $this->scopedPrompt = $previous;
        }
    }

    /**
     * Get the tags a request tracked right now would get: the inherited, global
     * and scoped tags. Queued jobs dispatched now inherit them.
     *
     * @return array<int, string>
     */
    public function currentTags(): array
    {
        return $this->mergedTags([]);
    }

    /**
     * Get the headers that carry the current trace and tags to another of your own
     * services, which continues them with the `spectra.trace` middleware.
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
     * Merge the inherited, global and scoped tags, then the request's own, without repeats.
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
        // Any string works as a trace id; the column holds 64 characters.
        $traceId = Str::limit($traceId, 64, '');

        $this->globalTraceId = $traceId;

        if (config('spectra.tracking.trace_per_request', true)) {
            TraceContext::set($traceId);
        }

        return $this;
    }

    /**
     * Get the trace id shared by the current request, job, or command.
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
     * Get the span a new AI call or span of the given trace belongs to: the open
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
     * Get the incoming `traceparent` span (or the span that dispatched this job),
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
     * Forget the global tags, trackable, conversation, pricing tier, trace id and metadata.
     */
    public function clearGlobals(): self
    {
        $this->globalTags = [];
        TraceContext::forgetTags();
        $this->globalTrackableType = null;
        $this->globalTrackableId = null;
        $this->globalConversationId = null;
        $this->globalConversationTurn = null;
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
     * Put every AI call tracked from now on in this conversation, at this
     * turn: one user message and the calls that answer it (tool calls and
     * embeddings included) share a turn. A model stands for its key. The
     * Conversations screen then shows the calls as one transcript.
     *
     *   Spectra::forConversation($chat, turn: $chat->messages()->where('role', 'user')->count());
     *
     * A conversation given to one call (its `conversation_id` option, or
     * $context->inConversation()) wins over this one.
     *
     * @throws InvalidArgumentException when the id is empty
     */
    public function forConversation(Model|string|int $conversation, ?int $turn = null): self
    {
        $id = (string) ($conversation instanceof Model ? $conversation->getKey() : $conversation);

        throw_if(blank(Str::trim($id)), InvalidArgumentException::class, 'A conversation needs an id.');

        $this->globalConversationId = $id;
        $this->globalConversationTurn = $turn;

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
            try {
                return json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return null;
            }
        }

        return $result;
    }
}
