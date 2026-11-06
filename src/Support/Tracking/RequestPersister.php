<?php

declare(strict_types=1);

namespace Spectra\Support\Tracking;

use Illuminate\Support\Str;
use Spectra\Contracts\RequestExporter;
use Spectra\Enums\ModelType;
use Spectra\Events\RequestTracked;
use Spectra\Jobs\ExportTrackedRequestJob;
use Spectra\Jobs\PersistSpectraRequestJob;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Annotations\AnnotationQueues;
use Spectra\Support\Errors\ErrorFingerprint;
use Spectra\Support\Pricing\PricingLookup;
use Spectra\Support\Pricing\RequestCostCalculator;
use Spectra\Support\Redaction\Redactor;
use Spectra\Support\RequestTransformer;
use Throwable;

/**
 * Turns a finished RequestContext into a stored SpectraRequest, synchronously,
 * after the response, or on a queue.
 *
 * Tracking must never break the host application: any failure while saving
 * is reported through the exception handler and an unsaved model is returned.
 */
class RequestPersister
{
    /**
     * Create a new request persister instance.
     */
    public function __construct(
        protected RequestCostCalculator $costCalculator,
        protected RequestWriter $writer,
        protected RequestExporter $exporter,
        protected RequestTransformer $transformer
    ) {}

    /**
     * Persist a tracked request, reporting any failure and returning an unsaved model instead.
     */
    public function persist(RequestContext $context): SpectraRequest
    {
        if (! config('spectra.enabled', true) || RecordingSwitch::isPaused()) {
            return new SpectraRequest;
        }

        // The tools the model asked for before this call, when tool spans are on.
        rescue(fn () => app(ToolSpanRecorder::class)->callFinished($context));

        try {
            return $this->store($context);
        } catch (Throwable $e) {
            report($e);

            return new SpectraRequest($this->safeAttributes($context));
        }
    }

    /**
     * Store the request now, after the response or on the queue, as the config says.
     */
    protected function store(RequestContext $context): SpectraRequest
    {
        $attributes = $this->buildAttributes($context);

        if (! config('spectra.storage.store_requests', true)) {
            $request = new SpectraRequest($attributes);
            $this->exportToIntegrations($request);

            return $request;
        }

        // Tag names are stored in a 255-character column.
        $tags = array_map(fn (string $tag): string => Str::limit($tag, 255, ''), $context->tags);
        // The review queues that sample this call's trace, picked now from
        // config and written with the row, so the queue setting keeps that
        // write off the request too.
        $reviewQueues = rescue(fn () => app(AnnotationQueues::class)->sampling($context, $this->isFailed($context)), []);

        if (config('spectra.queue.enabled')) {
            // A retried job must update the row an earlier attempt saved,
            // not insert a second one and count the spend twice.
            $attributes['id'] ??= (new SpectraRequest)->newUniqueId();

            return $this->persistViaQueue($attributes, $tags, $reviewQueues);
        }

        if (config('spectra.queue.after_response') && ! app()->runningInConsole()) {
            $attributes['id'] ??= (new SpectraRequest)->newUniqueId();

            return $this->persistAfterResponse($attributes, $tags, $reviewQueues);
        }

        return $this->persistSync($attributes, $tags, $reviewQueues);
    }

    /**
     * Build the request row's attributes from the context, with its cost and display name.
     *
     * @return array<string, mixed>
     */
    public function buildAttributes(RequestContext $context): array
    {
        $columns = $context->toArray();

        $pricingTier = $context->pricingTier
            ?? app(PricingLookup::class)->defaultTier($context->provider)->value;

        $cost = config('spectra.costs.enabled', true)
            ? $this->costCalculator->forContext($context, $pricingTier)
            : ['total_cost_in_cents' => 0.0];

        // Raw payloads are only present in toArray() when the matching storage
        // switch allows it; never fall back to the context, which always has them.
        $requestData = $columns['request_data'] ?? null;
        $response = $columns['response'] ?? null;
        $keepsPayload = $this->keepsPayload($context);

        return [
            'id' => $context->id,
            'batch_id' => $columns['batch_id'] ?? null,
            'trace_id' => $columns['trace_id'] ?? null,
            'parent_span_id' => $context->parentSpanId,
            'span_id' => $context->spanId,
            'response_id' => $context->responseId,
            // Ids and names the app chooses are cut to their column's length, so
            // an over-long one can't make the insert fail and lose the call.
            'conversation_id' => $this->truncate($context->conversationId, 255),
            'prompt_name' => $this->truncate($context->promptName, 100),
            'prompt_version' => $this->truncate($context->promptVersion, 50),
            'conversation_turn' => $context->conversationTurn,
            'provider' => $context->provider,
            'model' => $this->resolveDisplayName($context->provider, $context->model),
            // The provider's model id. `model` holds the catalog display name,
            // so keep the id even when the response echoed the requested one.
            'snapshot' => $context->snapshot ?? ($context->model !== 'unknown' ? $context->model : null),
            'model_type' => $context->modelType,
            'endpoint' => $context->endpoint,
            'pricing_tier' => $pricingTier,
            'trackable_type' => $context->trackableType,
            'trackable_id' => $context->trackableId,
            'request' => $keepsPayload ? $this->redact($this->sanitizeForJson($requestData)) : null,
            'response' => $keepsPayload ? $this->redact($this->sanitizeResponse($context, $response)) : null,
            'prompt_tokens' => $context->promptTokens,
            'completion_tokens' => $context->completionTokens,
            'cached_tokens' => $context->cachedTokens,
            'cache_write_tokens' => $context->cacheWriteTokens,
            'reasoning_tokens' => $context->reasoningTokens,
            'finish_reason' => $context->finishReason,
            'has_tool_calls' => $context->hasToolCalls,
            'tool_call_counts' => $context->toolCallCounts ?: null,
            'duration_seconds' => $context->durationSeconds,
            'input_characters' => $context->inputCharacters,
            'image_count' => $context->imageCount,
            'video_count' => $context->videoCount,
            'page_count' => $context->pageCount,
            'expires_at' => $context->expiresAt,
            'prompt_cost' => $cost['prompt_cost'] ?? 0,
            'completion_cost' => $cost['completion_cost'] ?? 0,
            'total_cost_in_cents' => $cost['total_cost_in_cents'],
            'currency' => Str::upper((string) config('spectra.costs.currency', 'USD')),
            // Async jobs report their own generation time; the HTTP call that
            // fetched the result only measured a status poll.
            'latency_ms' => $context->providerLatencyMs ?? $context->latencyMs,
            'time_to_first_token_ms' => $context->timeToFirstTokenMs,
            'tokens_per_second' => $context->tokensPerSecond,
            'is_reasoning' => $context->isReasoning,
            'reasoning_effort' => $context->reasoningEffort,
            'is_streaming' => $context->isStreaming,
            'status_code' => $context->httpStatus,
            'error_type' => $context->errorType,
            'error_message' => $this->redact($this->truncate(app(Redactor::class)->scrubSecrets($context->errorMessage), 10_000)),
            'error_fingerprint' => $this->isFailed($context) ? app(ErrorFingerprint::class)->for($context->provider, $context->httpStatus, $context->errorType, $context->errorMessage, [$context->model, $context->snapshot]) : null,
            'media_storage_path' => $context->mediaStoragePath ?: null,
            'metadata' => $context->metadata ?: null,
            'request_source' => $context->requestSource,
            'origin' => $context->origin,
            // The X-Request-ID header is the client's: a long one must not drop the call.
            'laravel_request_id' => $this->truncate($context->laravelRequestId, 255),
            'ip_address' => $context->ipAddress,
            'user_agent' => $this->truncate($context->userAgent, 1_000),
            'created_at' => $context->getStartedAt(),
            // created_at is stored to the second; traces order calls by this one.
            'started_at' => $context->getStartedAt(),
            'completed_at' => $context->getCompletedAt(),
        ];
    }

    /**
     * Determine if this call's request and response bodies are stored. With
     * `storage.sampling.rate` below 1, only that share of traces keep them
     * (every call of a trace together, picked by its trace id), and failed
     * calls always do unless `keep_failures` is off. Metrics and cost are
     * stored for every call either way.
     */
    protected function keepsPayload(RequestContext $context): bool
    {
        return app(PayloadSampler::class)->keeps($context->traceId, $this->isFailed($context));
    }

    /**
     * Determine if the call failed: it threw, the provider answered with no
     * 2xx status, or it reported an error inside a 2xx answer.
     */
    protected function isFailed(RequestContext $context): bool
    {
        return ! SpectraRequest::isSuccessfulOutcome($context->httpStatus, $context->errorType);
    }

    /**
     * Mask personal data and secrets in a stored value when redaction is on.
     */
    protected function redact(mixed $value): mixed
    {
        return app(Redactor::class)->redact($value);
    }

    /**
     * Get the catalog display name for a model, or the model id when it is not priced.
     */
    protected function resolveDisplayName(string $provider, string $model): string
    {
        return app(PricingLookup::class)->getDisplayName($provider, $model) ?? $model;
    }

    /**
     * Write the request to the database now.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $tags
     * @param  list<string>  $reviewQueues  Review queues to put the call's trace in
     */
    protected function persistSync(array $attributes, array $tags, array $reviewQueues = []): SpectraRequest
    {
        // A synchronous write is never retried, so only a polled job (named by
        // its response id) can already have a row.
        return $this->writer->write($attributes, $tags, reviewQueues: $reviewQueues, mayExist: filled($attributes['response_id'] ?? null));
    }

    /**
     * Queue the request's write on the configured connection, queue and delay.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $tags
     * @param  list<string>  $reviewQueues  Review queues to put the call's trace in
     */
    protected function persistViaQueue(array $attributes, array $tags, array $reviewQueues = []): SpectraRequest
    {
        $job = new PersistSpectraRequestJob($attributes, $tags, $reviewQueues);

        if ($connection = config('spectra.queue.connection')) {
            $job->onConnection($connection);
        }

        if ($queue = config('spectra.queue.queue')) {
            $job->onQueue($queue);
        }

        if ($delay = config('spectra.queue.delay')) {
            $job->delay($delay);
        }

        dispatch($job);

        return new SpectraRequest($attributes);
    }

    /**
     * Write the request once the response has been sent.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $tags
     * @param  list<string>  $reviewQueues  Review queues to put the call's trace in
     */
    protected function persistAfterResponse(array $attributes, array $tags, array $reviewQueues = []): SpectraRequest
    {
        $this->afterResponse(new PersistSpectraRequestJob($attributes, $tags, $reviewQueues));

        return new SpectraRequest($attributes);
    }

    /**
     * Run the job once the response is sent. A failure is reported and goes
     * no further, so the app's other terminating callbacks still run.
     */
    protected function afterResponse(object $job): void
    {
        app()->terminating(function () use ($job): void {
            rescue(fn () => dispatch_sync($job));
        });
    }

    /**
     * Transform and export a request that is not stored (store_requests off).
     */
    public function exportToIntegrations(SpectraRequest $request): void
    {
        $payload = $this->transformer->transform($request);

        RequestTracked::dispatch($payload);

        if (! config('spectra.integrations.opentelemetry.enabled')) {
            return;
        }

        if (config('spectra.queue.enabled')) {
            $job = new ExportTrackedRequestJob($payload);

            if ($connection = config('spectra.queue.connection')) {
                $job->onConnection($connection);
            }

            if ($queue = config('spectra.queue.queue')) {
                $job->onQueue($queue);
            }

            if ($delay = config('spectra.queue.delay')) {
                $job->delay($delay);
            }

            dispatch($job);

            return;
        }

        if (config('spectra.queue.after_response') && ! app()->runningInConsole()) {
            $this->afterResponse(new ExportTrackedRequestJob($payload));

            return;
        }

        $this->exporter->export($payload);
    }

    /**
     * Get the minimal attributes for the unsaved model returned when saving failed.
     *
     * @return array<string, mixed>
     */
    protected function safeAttributes(RequestContext $context): array
    {
        return [
            'provider' => $context->provider,
            'model' => $context->model,
            'prompt_tokens' => $context->promptTokens,
            'completion_tokens' => $context->completionTokens,
            'total_cost_in_cents' => $context->totalCost,
            'status_code' => $context->httpStatus,
        ];
    }

    /**
     * Cut a string to the column's length, leaving null alone.
     */
    protected function truncate(?string $value, int $length): ?string
    {
        return $value !== null && Str::length($value) > $length ? Str::substr($value, 0, $length) : $value;
    }

    /**
     * Apply model-type–specific response sanitization before storage.
     *
     * For embeddings: strips the large float-vector arrays from the response
     * when `store_embeddings` is disabled, keeping only the metadata (model,
     * usage, object type) while avoiding megabytes of vector data in the DB.
     *
     * The OpenAI Responses API echoes the request's `instructions` and
     * `tools`, so `store_system_prompts` and `store_tools` apply to the
     * stored response too.
     */
    protected function sanitizeResponse(RequestContext $context, mixed $response): mixed
    {
        if ($response === null || ! is_array($response)) {
            return $response;
        }

        $filter = app(RequestDataFilter::class);

        if (! config('spectra.storage.store_system_prompts', true)) {
            $response = $filter->withoutSystemPrompts($response);
        }

        if (! config('spectra.storage.store_tools', true)) {
            $response = $filter->withoutTools($response);
        }

        if ($context->modelType === ModelType::Embedding->value && ! config('spectra.storage.store_embeddings', false)) {
            // OpenAI / Mistral: data[].embedding
            if (is_array($response['data'] ?? null)) {
                foreach ($response['data'] as $i => $item) {
                    if (isset($item['embedding'])) {
                        $response['data'][$i]['embedding'] = '[stripped]';
                    }
                }
            }

            // Google single: embedding.values
            if (isset($response['embedding']['values'])) {
                $response['embedding']['values'] = '[stripped]';
            }

            // Google batch / Ollama: embeddings[]
            if (is_array($response['embeddings'] ?? null)) {
                foreach ($response['embeddings'] as $i => $item) {
                    if (is_array($item) && isset($item['values'])) {
                        // Google batch: embeddings[].values
                        $response['embeddings'][$i]['values'] = '[stripped]';
                    } elseif (is_array($item)) {
                        // Ollama: embeddings[] (direct float arrays)
                        $response['embeddings'][$i] = '[stripped]';
                    }
                }
            }
        }

        return $response;
    }

    /**
     * Recursively strip values that can't be JSON-encoded (e.g. binary file data from multipart uploads).
     */
    protected function sanitizeForJson(mixed $data): mixed
    {
        if ($data === null) {
            return null;
        }

        if (! is_array($data)) {
            return $data;
        }

        $sanitized = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeForJson($value);
            } elseif (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                $sanitized[$key] = '[binary data]';
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
