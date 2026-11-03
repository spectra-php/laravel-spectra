<?php

declare(strict_types=1);

namespace Spectra\Support\Tracking;

use Spectra\Contracts\RequestExporter;
use Spectra\Enums\ModelType;
use Spectra\Events\RequestTracked;
use Spectra\Jobs\ExportTrackedRequestJob;
use Spectra\Jobs\PersistSpectraRequestJob;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Pricing\PricingLookup;
use Spectra\Support\Pricing\RequestCostCalculator;
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
    public function __construct(
        protected RequestCostCalculator $costCalculator,
        protected RequestWriter $writer,
        protected RequestExporter $exporter,
        protected RequestTransformer $transformer
    ) {}

    public function persist(RequestContext $context): SpectraRequest
    {
        if (! config('spectra.enabled', true)) {
            return new SpectraRequest;
        }

        try {
            return $this->store($context);
        } catch (Throwable $e) {
            report($e);

            return new SpectraRequest($this->safeAttributes($context));
        }
    }

    protected function store(RequestContext $context): SpectraRequest
    {
        $attributes = $this->buildAttributes($context);

        if (! config('spectra.storage.store_requests', true)) {
            $request = new SpectraRequest($attributes);
            $this->exportToIntegrations($request);

            return $request;
        }

        $tags = $context->tags;

        if (config('spectra.queue.enabled')) {
            // A retried job must update the row an earlier attempt saved,
            // not insert a second one and count the spend twice.
            $attributes['id'] ??= (new SpectraRequest)->newUniqueId();

            return $this->persistViaQueue($attributes, $tags);
        }

        if (config('spectra.queue.after_response') && ! app()->runningInConsole()) {
            $attributes['id'] ??= (new SpectraRequest)->newUniqueId();

            return $this->persistAfterResponse($attributes, $tags);
        }

        return $this->persistSync($attributes, $tags);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildAttributes(RequestContext $context): array
    {
        $data = $context->toArray();

        $pricingTier = $context->pricingTier
            ?? app(PricingLookup::class)->defaultTier($context->provider)->value;

        $cost = config('spectra.costs.enabled', true)
            ? $this->costCalculator->forContext($context, $pricingTier)
            : ['total_cost_in_cents' => 0.0];

        // Raw payloads are only present in toArray() when the matching storage
        // switch allows it; never fall back to the context, which always has them.
        $requestData = array_key_exists('request_data', $data) ? $data['request_data'] : null;
        $response = array_key_exists('response', $data) ? $data['response'] : null;

        return [
            'batch_id' => $data['batch_id'] ?? null,
            'trace_id' => $data['trace_id'] ?? null,
            'parent_span_id' => $context->parentSpanId,
            'response_id' => $context->responseId,
            'conversation_id' => $context->conversationId,
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
            'request' => $this->sanitizeForJson($requestData),
            'response' => $this->sanitizeResponse($context, $response),
            'prompt_tokens' => $context->promptTokens,
            'completion_tokens' => $context->completionTokens,
            'cached_tokens' => $context->cachedTokens,
            'cache_write_tokens' => $context->cacheWriteTokens,
            'reasoning_tokens' => $context->reasoningTokens,
            'finish_reason' => $context->finishReason,
            'has_tool_calls' => $context->hasToolCalls,
            'tool_call_counts' => $context->toolCallCounts,
            'duration_seconds' => $context->durationSeconds,
            'input_characters' => $context->inputCharacters,
            'image_count' => $context->imageCount,
            'video_count' => $context->videoCount,
            'page_count' => $context->pageCount,
            'expires_at' => $context->expiresAt,
            'prompt_cost' => $cost['prompt_cost'] ?? 0,
            'completion_cost' => $cost['completion_cost'] ?? 0,
            'total_cost_in_cents' => $cost['total_cost_in_cents'],
            'currency' => strtoupper((string) config('spectra.costs.currency', 'USD')),
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
            'error_message' => $this->truncate($context->errorMessage, 10_000),
            'media_storage_path' => $context->mediaStoragePath,
            'metadata' => $context->metadata ?: null,
            'request_source' => $context->requestSource,
            'origin' => $context->origin,
            'laravel_request_id' => $context->laravelRequestId,
            'ip_address' => $context->ipAddress,
            'user_agent' => $this->truncate($context->userAgent, 1_000),
            'created_at' => $context->getStartedAt(),
            // created_at is stored to the second; traces order calls by this one.
            'started_at' => $context->getStartedAt(),
            'completed_at' => $context->getCompletedAt(),
        ];
    }

    protected function resolveDisplayName(string $provider, string $model): string
    {
        return app(PricingLookup::class)->getDisplayName($provider, $model) ?? $model;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $tags
     */
    protected function persistSync(array $attributes, array $tags): SpectraRequest
    {
        return $this->writer->write($attributes, $tags);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $tags
     */
    protected function persistViaQueue(array $attributes, array $tags): SpectraRequest
    {
        $job = new PersistSpectraRequestJob($attributes, $tags);

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
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $tags
     */
    protected function persistAfterResponse(array $attributes, array $tags): SpectraRequest
    {
        $this->afterResponse(new PersistSpectraRequestJob($attributes, $tags));

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
        $data = $this->transformer->transform($request);

        // Dispatch event for custom listeners
        RequestTracked::dispatch($data);

        // Export to OpenTelemetry (or whichever RequestExporter is bound)
        if (! config('spectra.integrations.opentelemetry.enabled')) {
            return;
        }

        if (config('spectra.queue.enabled')) {
            $job = new ExportTrackedRequestJob($data);

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
            $this->afterResponse(new ExportTrackedRequestJob($data));

            return;
        }

        $this->exporter->export($data);
    }

    /**
     * Minimal attributes for the unsaved model returned when saving failed.
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

    protected function truncate(?string $value, int $length): ?string
    {
        return $value !== null && mb_strlen($value) > $length ? mb_substr($value, 0, $length) : $value;
    }

    /**
     * Apply model-type–specific response sanitization before storage.
     *
     * For embeddings: strips the large float-vector arrays from the response
     * when `store_embeddings` is disabled, keeping only the metadata (model,
     * usage, object type) while avoiding megabytes of vector data in the DB.
     */
    protected function sanitizeResponse(RequestContext $context, mixed $response): mixed
    {
        if ($response === null || ! is_array($response)) {
            return $response;
        }

        // Strip embedding vectors (large float arrays) when store_embeddings is disabled
        if ($context->modelType === ModelType::Embedding->value && ! config('spectra.storage.store_embeddings', false)) {
            // OpenAI / Mistral: data[].embedding
            if (isset($response['data']) && is_array($response['data'])) {
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
            if (isset($response['embeddings']) && is_array($response['embeddings'])) {
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
