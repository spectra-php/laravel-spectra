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
    /** The most `spectra_requests.tokens_per_second`, a decimal(8, 2), can hold. */
    protected const MAX_TOKENS_PER_SECOND = 999999.99;

    /**
     * List properties: empty when unset, and stored as NULL while empty.
     *
     * @var list<string>
     */
    protected const LIST_PROPERTIES = ['messages', 'tools', 'requestParameters', 'requestData', 'toolCalls', 'toolCallCounts', 'mediaStoragePath'];

    public readonly string $id;

    public readonly string $traceId;

    /** This call's own span id (16 hex characters), so exports and child spans can point at it. */
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

    /** @var array<array{role: string, content: string}> */
    public array $messages = [];

    /** @var array<int, string> */
    public array $tools = [];

    /** @var array<string, mixed> */
    public array $requestParameters = [];

    /** @var array<string, mixed> */
    public array $requestData = [];

    public mixed $response = null;

    /** @var array<string, int> */
    public array $toolCalls = [];

    /** Whether the response contains tool/function calls. */
    public bool $hasToolCalls = false;

    /**
     * Tool call counts by type (e.g. ['function_call' => 3, 'web_search_call' => 2]).
     *
     * @var array<string, int>
     */
    public array $toolCallCounts = [];

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

    /** @var array<string, mixed> */
    public array $mediaStoragePath = [];

    /** Raw response body for binary responses (e.g. TTS audio). Transient — not persisted. */
    public ?string $rawResponseBody = null;

    public ?int $httpStatus = null;

    /**
     * The provider deployment the request went to (an Azure OpenAI deployment
     * name), used as the model when neither the response nor the request
     * names one.
     */
    public ?string $deployment = null;

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

    /** The job, Artisan command or route the call came from (see {@see TraceOrigin}). */
    public ?string $origin = null;

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

    /** The prompt template the call used, to compare its versions. */
    public ?string $promptName = null;

    public ?string $promptVersion = null;

    protected CarbonInterface $startedAt;

    protected ?CarbonInterface $completedAt = null;

    /**
     * Create a new request context instance, filling its properties from snake_case attributes.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(array $attributes = [])
    {
        // The id the request is stored under, so code inside Spectra::track()
        // can point a score at the call before it is saved.
        $this->id = $attributes['id'] ?? (string) Str::uuid7();
        // Time-ordered like every other id, so saving a call appends to the trace
        // index instead of splitting a random page of it.
        $this->traceId = $attributes['trace_id'] ?? (string) Str::uuid7();
        $this->spanId = $attributes['span_id'] ?? bin2hex(random_bytes(8));
        $this->startedAt = $attributes['started_at'] ?? now();

        foreach ($attributes as $key => $value) {
            $property = Str::camel($key);
            if (! property_exists($this, $property) || in_array($property, ['id', 'traceId', 'spanId', 'startedAt'])) {
                continue;
            }

            // A list passed as null is left empty, as callers may still pass null for one.
            if ($value === null && in_array($property, self::LIST_PROPERTIES, true)) {
                continue;
            }

            $this->{$property} = $value;
        }

        $this->captureContext();
    }

    /**
     * Capture the request id, IP, user agent, source and origin of the work making the call.
     */
    protected function captureContext(): void
    {
        if (config('spectra.tracking.capture_request_id') && app()->has('request')) {
            $headerValue = request()->header('X-Request-ID');
            $serverValue = request()->server('REQUEST_ID');
            $this->laravelRequestId = match (true) {
                is_string($headerValue) => $headerValue,
                is_string($serverValue) => $serverValue,
                default => (string) Str::uuid(),
            };
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

        if ($this->origin === null && config('spectra.tracking.capture_origin', true)) {
            $this->origin = app(TraceOrigin::class)->current();
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
     * Complete the request with its response and usage, then calculate its cost.
     *
     * @param  TokenMetrics|array<string, mixed>  $usage
     */
    public function complete(mixed $response, TokenMetrics|array $usage = []): self
    {
        $this->completedAt = now();
        $this->httpStatus ??= 200;

        $this->latencyMs = (int) $this->startedAt->diffInMilliseconds($this->completedAt);

        $this->response = $response;

        $this->setUsage($usage);
        $this->calculateCost();

        return $this;
    }

    /**
     * Mark the request as failed with the exception and HTTP status. Without
     * a status the response's own stays (a stream that broke off after a
     * 200), and a request that never got one stays without.
     */
    public function fail(\Throwable $exception, ?int $httpStatus = null): self
    {
        $this->completedAt = now();

        $this->latencyMs = (int) $this->startedAt->diffInMilliseconds($this->completedAt);
        $this->errorType = $exception::class;
        $this->errorMessage = $exception->getMessage();
        $this->httpStatus = $httpStatus ?? $this->httpStatus;

        return $this;
    }

    /**
     * Set the token usage and derive the reasoning flag and streaming tokens per second.
     *
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
            $tokensPerSecond = round(($this->completionTokens / $generationMs) * 1000, 2);

            // A stream that arrives all at once (a buffering proxy) has no real
            // generation time, and a rate this high would overflow the column
            // on PostgreSQL and strict MySQL, which loses the whole call.
            $this->tokensPerSecond = $tokensPerSecond <= self::MAX_TOKENS_PER_SECOND ? $tokensPerSecond : null;
        }

        return $this;
    }

    /**
     * Calculate the request's prompt, completion and total cost when costs are enabled.
     */
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

    /**
     * Set how long the stream took to deliver its first token.
     */
    public function setTimeToFirstToken(int $milliseconds): self
    {
        $this->timeToFirstTokenMs = $milliseconds;

        return $this;
    }

    /**
     * Count one more retry of the request.
     */
    public function incrementRetry(): self
    {
        $this->retryCount++;

        return $this;
    }

    /**
     * Add a tag to the request.
     */
    public function addTag(string $tag): self
    {
        $this->tags[] = $tag;

        return $this;
    }

    /**
     * Add a metadata value to the request.
     */
    public function addMetadata(string $key, mixed $value): self
    {
        $this->metadata[$key] = $value;

        return $this;
    }

    /**
     * Attribute the request's usage to a model, such as the user making it.
     */
    public function forTrackable(mixed $trackable): self
    {
        if (is_object($trackable) && ($trackable instanceof Model || method_exists($trackable, 'getKey'))) {
            [$this->trackableType, $this->trackableId] = $this->trackableIdentity($trackable);
        }

        return $this;
    }

    /**
     * Get the type and id to store for a trackable.
     *
     * Eloquent models use their morph alias, so apps with a morph map can
     * still query their requests.
     *
     * @return array{0: string, 1: mixed}
     */
    protected function trackableIdentity(object $trackable): array
    {
        return match (true) {
            $trackable instanceof Model => [$trackable->getMorphClass(), $trackable->getKey()],
            method_exists($trackable, 'getKey') => [$trackable::class, $trackable->getKey()],
            method_exists($trackable, 'getAuthIdentifier') => [$trackable::class, $trackable->getAuthIdentifier()],
            default => [$trackable::class, null],
        };
    }

    /**
     * Get the usage recorded so far, in the normalised TokenMetrics shape.
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

    /**
     * Place the request in a conversation, optionally at a given turn.
     */
    public function inConversation(string $conversationId, ?int $turn = null): self
    {
        $this->conversationId = $conversationId;
        $this->conversationTurn = $turn;

        return $this;
    }

    /**
     * Name the prompt template, and its version, the request used.
     */
    public function usingPrompt(string $name, ?string $version = null): self
    {
        $this->promptName = $name;
        $this->promptVersion = $version;

        return $this;
    }

    /**
     * Set the pricing tier the request is billed at.
     *
     * @param  string|PricingTier  $tier  The pricing tier (batch, flex, standard, priority)
     */
    public function withPricingTier(string|PricingTier $tier): self
    {
        $this->pricingTier = $tier instanceof PricingTier ? $tier->value : $tier;

        return $this;
    }

    /**
     * Get when the request started.
     */
    public function getStartedAt(): CarbonInterface
    {
        return $this->startedAt;
    }

    /**
     * Get when the request completed, or null while it is still running.
     */
    public function getCompletedAt(): ?CarbonInterface
    {
        return $this->completedAt;
    }

    /**
     * Get the request's columns for storage, leaving out payloads the storage config excludes.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $columns = [
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
            'request_parameters' => $this->requestParameters ?: null,
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
            'tool_call_counts' => $this->toolCallCounts ?: null,
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
            'media_storage_path' => $this->mediaStoragePath ?: null,
            'retry_count' => $this->retryCount,
            'request_source' => $this->requestSource,
            'origin' => $this->origin,
            'laravel_request_id' => $this->laravelRequestId,
            'session_id' => $this->sessionId,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'tags' => $this->tags,
            'metadata' => $this->metadata,
            'conversation_id' => $this->conversationId,
            'prompt_name' => $this->promptName,
            'prompt_version' => $this->promptVersion,
            'conversation_turn' => $this->conversationTurn,
            'created_at' => $this->startedAt,
            'updated_at' => $this->completedAt ?? now(),
        ];

        if (config('spectra.storage.store_prompts', true)) {
            $columns['prompt'] = $this->prompt;
            $columns['messages'] = $this->messages ?: null;
        }

        if (config('spectra.storage.store_system_prompts', true)) {
            $columns['system_prompt'] = $this->systemPrompt;
        }

        if (config('spectra.storage.store_responses', true)) {
            $columns['response'] = $this->response;
            $columns['tool_calls'] = $this->toolCalls ?: null;
        }

        if (config('spectra.storage.store_tools', true)) {
            $columns['tools'] = $this->tools ?: null;
        }

        // Raw request parameters are broader than prompt content, so they get
        // their own switch; it falls back to store_prompts for installs that
        // disabled prompt storage before this key existed.
        if (config('spectra.storage.store_request_data') ?? config('spectra.storage.store_prompts', true)) {
            $columns['request_data'] = $this->filteredRequestData();
        }

        return $columns;
    }

    /**
     * Get the raw request body with the system prompt and tool storage
     * switches applied and its inline files replaced by a placeholder.
     *
     * The raw body is where system prompts and tools are stored, so their
     * switches are applied to it. Inline files (base64 images and audio sent
     * as `data:` URIs) are always stripped, like binary data in responses.
     *
     * @return array<string, mixed>|null
     */
    protected function filteredRequestData(): ?array
    {
        if ($this->requestData === []) {
            return null;
        }

        $filter = app(RequestDataFilter::class);
        $requestData = $filter->withoutInlineFiles($this->requestData);

        if (! config('spectra.storage.store_system_prompts', true)) {
            $requestData = $filter->withoutSystemPrompts($requestData);
        }

        if (! config('spectra.storage.store_tools', true)) {
            $requestData = $filter->withoutTools($requestData);
        }

        return $requestData;
    }
}
