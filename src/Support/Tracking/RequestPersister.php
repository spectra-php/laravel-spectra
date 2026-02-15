<?php

namespace Spectra\Support\Tracking;

use Spectra\Contracts\RequestExporter;
use Spectra\Enums\PricingUnit;
use Spectra\Events\RequestTracked;
use Spectra\Jobs\ExportTrackedRequestJob;
use Spectra\Jobs\PersistSpectraRequestJob;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Pricing\CostCalculator;
use Spectra\Support\Pricing\PricingLookup;
use Spectra\Support\RequestTransformer;
use Spectra\Support\StatsAggregator;

class RequestPersister
{
    public function __construct(
        protected CostCalculator $costCalculator,
        protected StatsAggregator $statsAggregator,
        protected RequestExporter $exporter,
        protected RequestTransformer $transformer
    ) {}

    public function persist(RequestContext $context): SpectraRequest
    {
        if (! config('spectra.enabled', true)) {
            return new SpectraRequest;
        }

        if (! config('spectra.storage.store_requests', true)) {
            $request = new SpectraRequest($context->toArray());
            $this->exportToIntegrations($request);

            return $request;
        }

        $attributes = $this->buildAttributes($context);
        $tags = $context->tags;

        if (config('spectra.queue.enabled')) {
            return $this->persistViaQueue($attributes, $tags);
        }

        if (config('spectra.queue.after_response') && ! app()->runningInConsole()) {
            return $this->persistAfterResponse($attributes, $tags);
        }

        return $this->persistSync($attributes, $tags);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildAttributes(RequestContext $context): array
    {
        // Override latency with provider-side generation time when available
        // (e.g. Sora video responses include completed_at/created_at timestamps)
        $responseData = is_array($context->response) ? $context->response : [];
        if (isset($responseData['completed_at'], $responseData['created_at'])) {
            $context->latencyMs = (int) (($responseData['completed_at'] - $responseData['created_at']) * 1000);
        }

        $data = $context->toArray();

        $model = $this->resolveDisplayName($context->provider, $context->model);
        $snapshot = $context->snapshot;

        $promptTokens = $context->promptTokens;
        $completionTokens = $context->completionTokens;
        $cachedTokens = $context->cachedTokens;

        $pricingTier = $context->pricingTier
            ?? config("spectra.costs.provider_settings.{$context->provider}.default_tier", 'standard');

        $cost = $this->calculateCost($context, $pricingTier);

        $trackableType = $context->trackableType;
        $trackableId = $context->trackableId;

        return [
            'batch_id' => $data['batch_id'] ?? null,
            'trace_id' => $data['trace_id'] ?? null,
            'response_id' => $context->responseId,
            'provider' => $context->provider,
            'model' => $model,
            'snapshot' => $snapshot,
            'model_type' => $context->modelType,
            'endpoint' => $context->endpoint,
            'pricing_tier' => $pricingTier,
            'trackable_type' => $trackableType,
            'trackable_id' => $trackableId,
            'request' => $this->sanitizeForJson($data['request_data'] ?? $context->requestData),
            'response' => $this->sanitizeResponse($context, $data['response'] ?? null),
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'reasoning_tokens' => $context->reasoningTokens,
            'finish_reason' => $context->finishReason,
            'has_tool_calls' => $context->hasToolCalls,
            'tool_call_counts' => $context->toolCallCounts,
            'duration_seconds' => $context->durationSeconds,
            'input_characters' => $context->inputCharacters,
            'image_count' => $context->imageCount,
            'video_count' => $context->videoCount,
            'expires_at' => $context->expiresAt,
            'prompt_cost' => $cost['prompt_cost'] ?? 0,
            'completion_cost' => $cost['completion_cost'] ?? 0,
            'total_cost_in_cents' => $cost['total_cost_in_cents'],
            'latency_ms' => $context->latencyMs,
            'time_to_first_token_ms' => $context->timeToFirstTokenMs,
            'tokens_per_second' => $context->tokensPerSecond,
            'is_reasoning' => $context->isReasoning,
            'reasoning_effort' => $context->reasoningEffort,
            'is_streaming' => $context->isStreaming,
            'status_code' => $context->httpStatus,
            'media_storage_path' => $context->mediaStoragePath,
            'metadata' => $context->metadata ?: null,
            'created_at' => $context->getStartedAt(),
            'completed_at' => $context->getCompletedAt(),
        ];
    }

    /**
     * @return array{prompt_cost?: float, completion_cost?: float, total_cost_in_cents: float}
     */
    protected function calculateCost(RequestContext $context, string $pricingTier): array
    {
        $model = $context->model;
        $pricingUnit = $this->costCalculator->getPricingUnit($context->provider, $model);

        $cost = match ($pricingUnit) {
            PricingUnit::Minute->value => $this->costCalculator->calculateByDuration(
                $context->provider,
                $model,
                $context->durationSeconds ?? 0,
                $pricingTier
            ),
            PricingUnit::Second->value => $this->costCalculator->calculateByDurationSeconds(
                $context->provider,
                $model,
                $context->durationSeconds ?? 0,
                $pricingTier
            ),
            PricingUnit::Characters->value => $this->costCalculator->calculateByCharacters(
                $context->provider,
                $model,
                $context->inputCharacters ?? 0,
                $pricingTier
            ),
            PricingUnit::Image->value => $this->costCalculator->calculateByImages(
                $context->provider,
                $model,
                $context->imageCount ?? 0,
                $pricingTier
            ),
            PricingUnit::Video->value => $this->costCalculator->calculateByVideos(
                $context->provider,
                $model,
                $context->videoCount ?? 0,
                $pricingTier
            ),
            default => $this->costCalculator->calculate(
                $context->provider,
                $model,
                $context->promptTokens,
                $context->completionTokens,
                $context->cachedTokens,
                $pricingTier
            ),
        };

        // Add tool call surcharges (e.g. web_search_call, code_interpreter_call)
        $toolCallSurcharge = $this->calculateToolCallCost($context);
        if ($toolCallSurcharge > 0) {
            $cost['total_cost_in_cents'] = $cost['total_cost_in_cents'] + $toolCallSurcharge;
        }

        return $cost;
    }

    /**
     * Calculate the cost of tool calls based on per-provider pricing.
     *
     * Returns cost in cents.
     */
    protected function calculateToolCallCost(RequestContext $context): float
    {
        if (empty($context->toolCallCounts)) {
            return 0.0;
        }

        $pricing = app(PricingLookup::class)->getToolCallPricing($context->provider);

        if (empty($pricing)) {
            return 0.0;
        }

        $total = 0.0;
        foreach ($context->toolCallCounts as $type => $count) {
            if (isset($pricing[$type])) {
                $total += $pricing[$type] * $count;
            }
        }

        return $total;
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
        if ($attributes['response_id'] ?? null) {
            $request = SpectraRequest::updateOrCreate(
                ['response_id' => $attributes['response_id']],
                $attributes
            );
        } else {
            $request = SpectraRequest::create($attributes);
        }

        if (! empty($tags)) {
            $request->attachTags($tags);
        }

        $this->statsAggregator->recordRequest($request);
        $this->exportToIntegrations($request);

        return $request;
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
        $job = new PersistSpectraRequestJob($attributes, $tags);

        dispatch($job)->afterResponse();

        return new SpectraRequest($attributes);
    }

    /**
     * Transform and export a request to all enabled integrations.
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
            dispatch(new ExportTrackedRequestJob($data))->afterResponse();

            return;
        }

        $this->exporter->export($data);
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
        if ($context->modelType === 'embedding' && ! config('spectra.storage.store_embeddings', false)) {
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
