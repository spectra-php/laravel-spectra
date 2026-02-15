<?php

namespace Spectra\Support\Tracking;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\PricingTier;

/**
 * In-flight container that accumulates AI request data throughout its lifecycle.
 *
 * Captures timing, tokens, costs, errors, and contextual metadata for observability.
 * Most properties are null/empty when first created and populated after the AI call completes.
 *
 * @implements Arrayable<string, mixed>
 */
class RequestContext implements Arrayable
{
    public readonly string $id;

    public readonly string $traceId;

    public ?string $spanId = null;

    public ?string $parentSpanId = null;

    public string $provider;

    public string $model;

    /** The original model name returned by the API (snapshot/version). */
    public ?string $snapshot = null;

    public ?string $operation = null;

    /** Model type classification: llm, image, tts, stt, video. */
    public ?string $modelType = null;

    public ?string $trackableType = null;

    public mixed $trackableId = null;

    public ?string $apiKeyIdentifier = null;

    public ?string $prompt = null;

    public ?string $systemPrompt = null;

    /** @var array<array{role: string, content: string}>|null */
    public ?array $messages = null;

    /** @var array<int, string>|null */
    public ?array $tools = null;

    /** @var array<string, mixed>|null */
    public ?array $requestParameters = null;

    /** @var array<string, mixed>|null */
    public ?array $requestData = null;

    public mixed $response = null;

    /** @var array<string, int>|null */
    public ?array $toolCalls = null;

    /** Whether the response contains tool/function calls. */
    public bool $hasToolCalls = false;

    /**
     * Tool call counts by type (e.g. ['function_call' => 3, 'web_search_call' => 2]).
     *
     * @var array<string, int>|null
     */
    public ?array $toolCallCounts = null;

    public ?string $finishReason = null;

    public ?string $responseId = null;

    public int $promptTokens = 0;

    public int $completionTokens = 0;

    /** Cached tokens are typically charged at a reduced rate. */
    public int $cachedTokens = 0;

    /** Reasoning/thinking tokens used by models like o3, o4-mini, Claude with extended thinking. */
    public int $reasoningTokens = 0;

    /** Duration in seconds for audio/video models that charge per minute/second. */
    public ?float $durationSeconds = null;

    /** Input characters for character-based pricing (e.g. TTS models). */
    public ?int $inputCharacters = null;

    public ?int $imageCount = null;

    public ?int $videoCount = null;

    public ?Carbon $expiresAt = null;

    public float $promptCost = 0;

    public float $completionCost = 0;

    /** Total cost of this request in cents. */
    public float $totalCost = 0;

    public string $currency = 'USD';

    /** Pricing tier for tiered providers (OpenAI, Anthropic). Options: batch, flex, standard, priority. */
    public ?string $pricingTier = null;

    public ?int $latencyMs = null;

    /** Time to first token in milliseconds (streaming only). */
    public ?int $timeToFirstTokenMs = null;

    public ?float $tokensPerSecond = null;

    public ?string $errorType = null;

    public ?string $errorMessage = null;

    /** @var array<string, mixed>|null */
    public ?array $mediaStoragePath = null;

    /** Raw response body for binary responses (e.g. TTS audio). Transient — not persisted. */
    public ?string $rawResponseBody = null;

    public ?int $httpStatus = null;

    public int $retryCount = 0;

    public bool $isReasoning = false;

    public ?string $reasoningEffort = null;

    public bool $isStreaming = false;

    /** Whether ResponseProcessor has already processed this context. */
    public bool $processed = false;

    public ?string $endpoint = null;

    /** Values: 'web', 'api', 'console', 'queue'. */
    public ?string $requestSource = null;

    public ?string $laravelRequestId = null;

    public ?string $sessionId = null;

    public ?string $ipAddress = null;

    public ?string $userAgent = null;

    /** @var array<int, string> */
    public array $tags = [];

    /** @var array<string, mixed> */
    public array $metadata = [];

    public ?string $conversationId = null;

    public ?int $conversationTurn = null;

    protected Carbon $startedAt;

    protected ?Carbon $completedAt = null;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->id = $attributes['id'] ?? (string) Str::ulid();
        $this->traceId = $attributes['trace_id'] ?? (string) Str::uuid();
        $this->startedAt = $attributes['started_at'] ?? now();

        foreach ($attributes as $key => $value) {
            $property = Str::camel($key);
            if (property_exists($this, $property) && ! in_array($property, ['id', 'traceId', 'startedAt'])) {
                $this->{$property} = $value;
            }
        }

        $this->captureContext();
    }

    protected function captureContext(): void
    {
        if (config('spectra.tracking.capture_request_id') && app()->has('request')) {
            $headerValue = request()->header('X-Request-ID');
            $serverValue = request()->server('REQUEST_ID');
            $this->laravelRequestId = is_string($headerValue) ? $headerValue
                : (is_string($serverValue) ? $serverValue : (string) Str::uuid());
        }

        if (config('spectra.tracking.capture_ip') && app()->has('request')) {
            $this->ipAddress = request()->ip();
        }

        if (config('spectra.tracking.capture_user_agent') && app()->has('request')) {
            $this->userAgent = request()->userAgent();
        }

        if (app()->runningInConsole()) {
            $this->requestSource = 'console';
        } elseif (app()->has('request')) {
            $this->requestSource = request()->expectsJson() ? 'api' : 'web';
        }

        if (config('spectra.tracking.auto_track_user') && auth()->check()) {
            $user = auth()->user();
            if ($user !== null) {
                $this->trackableType = get_class($user);
                $this->trackableId = $user->getKey();
            }
        }
    }

    /**
     * @param  TokenMetrics|array<string, mixed>  $usage
     */
    public function complete(mixed $response, TokenMetrics|array $usage = []): self
    {
        $this->completedAt = now();
        $this->httpStatus = $this->httpStatus ?? 200;

        $this->latencyMs = (int) $this->startedAt->diffInMilliseconds($this->completedAt);

        $this->response = $response;

        $this->setUsage($usage);
        $this->calculateCost();

        return $this;
    }

    public function fail(\Throwable $exception, ?int $httpStatus = null): self
    {
        $this->completedAt = now();

        $this->latencyMs = (int) $this->startedAt->diffInMilliseconds($this->completedAt);
        $this->errorType = get_class($exception);
        $this->errorMessage = $exception->getMessage();
        $this->httpStatus = $httpStatus;

        return $this;
    }

    /**
     * @param  TokenMetrics|array<string, mixed>  $usage
     */
    public function setUsage(TokenMetrics|array $usage): self
    {
        if ($usage instanceof TokenMetrics) {
            $this->promptTokens = $usage->promptTokens;
            $this->completionTokens = $usage->completionTokens;
            $this->cachedTokens = $usage->cachedTokens;
            $this->reasoningTokens = $usage->reasoningTokens ?: $this->reasoningTokens;
        } else {
            $this->promptTokens = $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0;
            $this->completionTokens = $usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0;
            $this->cachedTokens = $usage['cached_tokens'] ?? $usage['cache_read_input_tokens'] ?? 0;
            $this->reasoningTokens = $usage['reasoning_tokens'] ?? $this->reasoningTokens;
        }

        if ($this->reasoningTokens > 0) {
            $this->isReasoning = true;
        }

        // tokens/s is only meaningful for streaming where we have TTFT
        if ($this->isStreaming && $this->timeToFirstTokenMs !== null && $this->latencyMs && $this->completionTokens > 0) {
            $generationMs = max(1, $this->latencyMs - $this->timeToFirstTokenMs);
            $this->tokensPerSecond = round(($this->completionTokens / $generationMs) * 1000, 2);
        }

        return $this;
    }

    protected function calculateCost(): void
    {
        if (! config('spectra.costs.enabled', true)) {
            return;
        }

        $calculator = app(\Spectra\Support\Pricing\CostCalculator::class);

        $pricingTier = null;
        if ($this->pricingTier !== null) {
            $pricingTier = PricingTier::tryFrom($this->pricingTier) ?? $this->pricingTier;
        }

        $costs = $calculator->calculate(
            $this->provider,
            $this->model,
            $this->promptTokens,
            $this->completionTokens,
            $this->cachedTokens,
            $pricingTier
        );

        $this->promptCost = $costs['prompt_cost'];
        $this->completionCost = $costs['completion_cost'];
        $this->totalCost = $costs['total_cost_in_cents'];
        $this->currency = config('spectra.costs.currency', 'USD');
    }

    public function setTimeToFirstToken(int $milliseconds): self
    {
        $this->timeToFirstTokenMs = $milliseconds;

        return $this;
    }

    public function incrementRetry(): self
    {
        $this->retryCount++;

        return $this;
    }

    public function addTag(string $tag): self
    {
        $this->tags[] = $tag;

        return $this;
    }

    public function addMetadata(string $key, mixed $value): self
    {
        $this->metadata[$key] = $value;

        return $this;
    }

    public function forTrackable(mixed $trackable): self
    {
        if (is_object($trackable) && method_exists($trackable, 'getKey')) {
            $this->trackableType = get_class($trackable);
            $this->trackableId = $trackable->getKey();
        }

        return $this;
    }

    public function inConversation(string $conversationId, ?int $turn = null): self
    {
        $this->conversationId = $conversationId;
        $this->conversationTurn = $turn;

        return $this;
    }

    /**
     * @param  string|PricingTier  $tier  The pricing tier (batch, flex, standard, priority)
     */
    public function withPricingTier(string|PricingTier $tier): self
    {
        $this->pricingTier = $tier instanceof PricingTier ? $tier->value : $tier;

        return $this;
    }

    public function getStartedAt(): Carbon
    {
        return $this->startedAt;
    }

    public function getCompletedAt(): ?Carbon
    {
        return $this->completedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'trace_id' => $this->traceId,
            'span_id' => $this->spanId,
            'parent_span_id' => $this->parentSpanId,
            'provider' => $this->provider,
            'model' => $this->model,
            'snapshot' => $this->snapshot,
            'model_type' => $this->modelType,
            'endpoint' => $this->endpoint,
            'operation' => $this->operation,
            'trackable_type' => $this->trackableType,
            'trackable_id' => $this->trackableId,
            'api_key_identifier' => $this->apiKeyIdentifier,
            'request_parameters' => $this->requestParameters,
            'response_id' => $this->responseId,
            'finish_reason' => $this->finishReason,
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'cached_tokens' => $this->cachedTokens,
            'reasoning_tokens' => $this->reasoningTokens,
            'is_reasoning' => $this->isReasoning,
            'reasoning_effort' => $this->reasoningEffort,
            'has_tool_calls' => $this->hasToolCalls,
            'tool_call_counts' => $this->toolCallCounts,
            'duration_seconds' => $this->durationSeconds,
            'input_characters' => $this->inputCharacters,
            'image_count' => $this->imageCount,
            'video_count' => $this->videoCount,
            'expires_at' => $this->expiresAt,
            'prompt_cost' => $this->promptCost,
            'completion_cost' => $this->completionCost,
            'total_cost_in_cents' => $this->totalCost,
            'currency' => $this->currency,
            'pricing_tier' => $this->pricingTier,
            'latency_ms' => $this->latencyMs,
            'time_to_first_token_ms' => $this->timeToFirstTokenMs,
            'tokens_per_second' => $this->tokensPerSecond,
            'status_code' => $this->httpStatus,
            'error_type' => $this->errorType,
            'error_message' => $this->errorMessage,
            'media_storage_path' => $this->mediaStoragePath,
            'http_status' => $this->httpStatus,
            'retry_count' => $this->retryCount,
            'request_source' => $this->requestSource,
            'laravel_request_id' => $this->laravelRequestId,
            'session_id' => $this->sessionId,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'tags' => $this->tags,
            'metadata' => $this->metadata,
            'conversation_id' => $this->conversationId,
            'conversation_turn' => $this->conversationTurn,
            'created_at' => $this->startedAt,
            'updated_at' => $this->completedAt ?? now(),
        ];

        if (config('spectra.storage.store_prompts', true)) {
            $data['prompt'] = $this->prompt;
        }

        if (config('spectra.storage.store_system_prompts', true)) {
            $data['system_prompt'] = $this->systemPrompt;
        }

        if (config('spectra.storage.store_prompts', true)) {
            $data['messages'] = $this->messages;
        }

        if (config('spectra.storage.store_responses', true)) {
            $data['response'] = $this->response;
            $data['tool_calls'] = $this->toolCalls;
        }

        if (config('spectra.storage.store_tools', true)) {
            $data['tools'] = $this->tools;
        }

        if (config('spectra.storage.store_prompts', true)) {
            $data['request_data'] = $this->requestData;
        }

        return $data;
    }
}
