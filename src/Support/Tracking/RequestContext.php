<?php

declare(strict_types=1);

namespace Spectra\Support\Tracking;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\PricingTier;
use Spectra\Support\Pricing\RequestCostCalculator;

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

    /** Total input tokens, including cache reads and cache writes. */
    public int $promptTokens = 0;

    /** Total output tokens, including reasoning tokens. */
    public int $completionTokens = 0;

    /** Input tokens read from the prompt cache (part of promptTokens). */
    public int $cachedTokens = 0;

    /** Reasoning/thinking tokens (part of completionTokens). */
    public int $reasoningTokens = 0;

    /** Input tokens written to the prompt cache (part of promptTokens). */
    public int $cacheWriteTokens = 0;

    /** Cache writes with a 1-hour TTL (part of cacheWriteTokens). */
    public int $cacheWrite1hTokens = 0;

    /** Uncached audio input tokens (part of promptTokens). */
    public int $audioInputTokens = 0;

    /** Uncached image input tokens (part of promptTokens). */
    public int $imageInputTokens = 0;

    /** Audio output tokens (part of completionTokens). */
    public int $audioOutputTokens = 0;

    /** Image output tokens (part of completionTokens). */
    public int $imageOutputTokens = 0;

    /** Duration in seconds for audio/video models that charge per minute/second. */
    public ?float $durationSeconds = null;

    /** Input characters for character-based pricing (e.g. TTS models). */
    public ?int $inputCharacters = null;

    public ?int $imageCount = null;

    /** Total output megapixels, for models billed per megapixel. */
    public ?float $megapixels = null;

    public ?int $videoCount = null;

    /** Pages processed, for page-billed models (e.g. OCR). */
    public ?int $pageCount = null;

    /** Billable search/rerank operations for per-search pricing (e.g. Cohere rerank). */
    public ?int $searchCount = null;

    public ?CarbonInterface $expiresAt = null;

    public float $promptCost = 0;

    public float $completionCost = 0;

    /** Total cost of this request in cents. */
    public float $totalCost = 0;

    public string $currency = 'USD';

    /**
     * Pricing tier the request was billed at (see PricingTier). Set explicitly
     * (Spectra::withPricingTier(), the `pricing_tier` option) it always wins;
     * otherwise it is filled from the response, then the request, then config.
     */
    public ?string $pricingTier = null;

    /** Pricing tier the request body asked for (e.g. `service_tier: flex`); used when the response doesn't say. */
    public ?string $requestedPricingTier = null;

    /** Cost the provider reported itself (US cents); replaces the catalog estimate when set. */
    public ?float $reportedCostInCents = null;

    /** Price multiplier for regional processing and similar surcharges (e.g. 1.1 for US-only inference). */
    public float $costMultiplier = 1.0;

    /** Generation time reported by the provider for async jobs (video, predictions), in milliseconds. */
    public ?int $providerLatencyMs = null;

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

    /** Set when a manual Spectra::stream() tracker took over recording this streamed request. */
    public bool $streamClaimed = false;

    public ?string $endpoint = null;

    /** Values: 'web', 'api', 'console' (queued jobs run in the console). */
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

    protected CarbonInterface $startedAt;

    protected ?CarbonInterface $completedAt = null;

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

        // In queued jobs and commands the bound request is a placeholder
        // (127.0.0.1, "Symfony"), so origin is only captured for web requests.
        $webRequest = app()->has('request') && ! app()->runningInConsole();

        if (config('spectra.tracking.capture_ip') && $webRequest) {
            $this->ipAddress = request()->ip();
        }

        if (config('spectra.tracking.capture_user_agent') && $webRequest) {
            $this->userAgent = request()->userAgent();
        }

        if (app()->runningInConsole()) {
            $this->requestSource = 'console';
        } elseif (app()->has('request')) {
            $this->requestSource = request()->expectsJson() ? 'api' : 'web';
        }

        // An explicitly given trackable (per call or via Spectra::forTrackable())
        // always wins over the signed-in user.
        if ($this->trackableType === null && config('spectra.tracking.auto_track_user') && auth()->check()) {
            $user = auth()->user();
            if ($user !== null) {
                [$this->trackableType, $this->trackableId] = $this->trackableIdentity($user);
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
        $tokens = $usage instanceof TokenMetrics ? $usage : TokenMetrics::fromArray($usage);

        $this->promptTokens = $tokens->promptTokens;
        $this->completionTokens = $tokens->completionTokens;
        $this->cachedTokens = $tokens->cachedTokens;
        $this->reasoningTokens = $tokens->reasoningTokens ?: $this->reasoningTokens;
        $this->cacheWriteTokens = $tokens->cacheWriteTokens;
        $this->cacheWrite1hTokens = $tokens->cacheWrite1hTokens;
        $this->audioInputTokens = $tokens->audioInputTokens;
        $this->imageInputTokens = $tokens->imageInputTokens;
        $this->audioOutputTokens = $tokens->audioOutputTokens;
        $this->imageOutputTokens = $tokens->imageOutputTokens;

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

        $pricingTier = null;
        if ($this->pricingTier !== null) {
            $pricingTier = PricingTier::tryFrom($this->pricingTier) ?? $this->pricingTier;
        }

        $costs = app(RequestCostCalculator::class)->forContext($this, $pricingTier);

        $this->promptCost = $costs['prompt_cost'] ?? 0;
        $this->completionCost = $costs['completion_cost'] ?? 0;
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
        if (is_object($trackable) && ($trackable instanceof Model || method_exists($trackable, 'getKey'))) {
            [$this->trackableType, $this->trackableId] = $this->trackableIdentity($trackable);
        }

        return $this;
    }

    /**
     * Type and id to store for a trackable. Eloquent models use their morph
     * alias, so apps with a morph map can still query their requests.
     *
     * @return array{0: string, 1: mixed}
     */
    protected function trackableIdentity(object $trackable): array
    {
        return match (true) {
            $trackable instanceof Model => [$trackable->getMorphClass(), $trackable->getKey()],
            method_exists($trackable, 'getKey') => [get_class($trackable), $trackable->getKey()],
            method_exists($trackable, 'getAuthIdentifier') => [get_class($trackable), $trackable->getAuthIdentifier()],
            default => [get_class($trackable), null],
        };
    }

    /**
     * The usage recorded so far, in the normalised TokenMetrics shape.
     */
    public function tokens(): TokenMetrics
    {
        return new TokenMetrics(
            promptTokens: $this->promptTokens,
            completionTokens: $this->completionTokens,
            cachedTokens: $this->cachedTokens,
            reasoningTokens: $this->reasoningTokens,
            cacheWriteTokens: $this->cacheWriteTokens,
            cacheWrite1hTokens: $this->cacheWrite1hTokens,
            audioInputTokens: $this->audioInputTokens,
            imageInputTokens: $this->imageInputTokens,
            audioOutputTokens: $this->audioOutputTokens,
            imageOutputTokens: $this->imageOutputTokens,
        );
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

    public function getStartedAt(): CarbonInterface
    {
        return $this->startedAt;
    }

    public function getCompletedAt(): ?CarbonInterface
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
            'cache_write_tokens' => $this->cacheWriteTokens,
            'reasoning_tokens' => $this->reasoningTokens,
            'is_reasoning' => $this->isReasoning,
            'reasoning_effort' => $this->reasoningEffort,
            'has_tool_calls' => $this->hasToolCalls,
            'tool_call_counts' => $this->toolCallCounts,
            'duration_seconds' => $this->durationSeconds,
            'input_characters' => $this->inputCharacters,
            'image_count' => $this->imageCount,
            'video_count' => $this->videoCount,
            'page_count' => $this->pageCount,
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
            $data['messages'] = $this->messages;
        }

        if (config('spectra.storage.store_system_prompts', true)) {
            $data['system_prompt'] = $this->systemPrompt;
        }

        if (config('spectra.storage.store_responses', true)) {
            $data['response'] = $this->response;
            $data['tool_calls'] = $this->toolCalls;
        }

        if (config('spectra.storage.store_tools', true)) {
            $data['tools'] = $this->tools;
        }

        // Raw request parameters are broader than prompt content, so they get
        // their own switch; it falls back to store_prompts for installs that
        // disabled prompt storage before this key existed.
        if (config('spectra.storage.store_request_data') ?? config('spectra.storage.store_prompts', true)) {
            $data['request_data'] = $this->requestData;
        }

        return $data;
    }
}
