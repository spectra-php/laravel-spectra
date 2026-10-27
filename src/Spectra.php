<?php

declare(strict_types=1);

namespace Spectra;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Traits\Macroable;
use Spectra\Data\TokenMetrics;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Query\CostQueryBuilder;
use Spectra\Support\Query\UsageQueryBuilder;
use Spectra\Support\Tracking\RequestContext;
use Spectra\Support\Tracking\RequestPersister;
use Spectra\Support\Tracking\ResponseProcessor;
use Spectra\Support\Tracking\StreamingTracker;
use Spectra\Support\Tracking\TraceContext;
use Throwable;

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

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function isEnabled(): bool
    {
        return (bool) config('spectra.enabled', true);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function startRequest(string $provider, string $model, array $options = []): RequestContext
    {
        // Options first, so the merged tags/metadata and the global fallbacks
        // below are not overwritten by the raw per-request values.
        $context = new RequestContext([
            ...$options,
            'provider' => $provider,
            'model' => $model,
            'tags' => $this->mergedTags($options['tags'] ?? []),
            'metadata' => array_merge($this->globalMetadata, $options['metadata'] ?? []),
            'trackable_type' => $options['trackable_type'] ?? $this->globalTrackableType,
            'trackable_id' => $options['trackable_id'] ?? $this->globalTrackableId,
            'trace_id' => $options['trace_id'] ?? $this->globalTraceId ?? $this->requestTraceId(),
            'parent_span_id' => $options['parent_span_id'] ?? $this->requestParentSpanId($options),
        ]);

        if ($this->globalPricingTier && $context->pricingTier === null) {
            $context->withPricingTier($this->globalPricingTier);
        }

        $this->currentContext = $context;

        return $context;
    }

    /**
     * @param  TokenMetrics|array<string, mixed>  $usage
     */
    public function recordSuccess(RequestContext $context, mixed $response, TokenMetrics|array $usage = []): SpectraRequest
    {
        $result = app(ResponseProcessor::class)->processResponse($context, $response);

        if ($result !== null) {
            [$processedResponse, $processedUsage] = $result;
            $response = $processedResponse;

            if (empty($usage) && $processedUsage !== null) {
                $usage = $processedUsage;
            }
        }

        $context->complete($response, $usage);

        return $this->getPersister()->persist($context);
    }

    public function recordFailure(
        RequestContext $context,
        Throwable $exception,
        ?int $httpStatus = null
    ): SpectraRequest {
        $context->fail($exception, $httpStatus);

        return $this->getPersister()->persist($context);
    }

    /**
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

            $this->recordSuccess($context, $this->toRawResponse($result));

            return $result;
        } catch (Throwable $e) {
            $this->recordFailure($context, $e);
            throw $e;
        }
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
     */
    /**
     * @param  array<string, mixed>  $options
     */
    public function stream(?string $provider = null, ?string $model = null, array $options = []): StreamingTracker
    {
        return new StreamingTracker($provider, $model, $options);
    }

    /**
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
        return array_values(array_unique(array_merge(TraceContext::tags(), $this->globalTags, $this->scopedTags, $tags)));
    }

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

    protected function requestTraceId(): ?string
    {
        return config('spectra.tracking.trace_per_request', true) ? TraceContext::traceId() : null;
    }

    /**
     * The incoming `traceparent` span, when this call belongs to that trace.
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
     * @param  array<string, mixed>  $metadata
     */
    public function withMetadata(array $metadata): self
    {
        $this->globalMetadata = array_merge($this->globalMetadata, $metadata);

        return $this;
    }

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

    public function forTrackable(Model $trackable): self
    {
        $this->globalTrackableType = $trackable->getMorphClass();
        $this->globalTrackableId = $trackable->getKey();

        return $this;
    }

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

        return $model::findOrFail($id);
    }

    public function getCurrentContext(): ?RequestContext
    {
        return $this->currentContext;
    }

    public function setPendingStreamContext(RequestContext $context): void
    {
        $this->pendingStreamContext = $context;
    }

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

    public function usage(): UsageQueryBuilder
    {
        return new UsageQueryBuilder;
    }

    public function costs(): CostQueryBuilder
    {
        return new CostQueryBuilder;
    }

    public function getPersister(): RequestPersister
    {
        return $this->app->make(RequestPersister::class);
    }

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
