<?php

declare(strict_types=1);

namespace Spectra\Support\Tracking;

use Generator;
use Illuminate\Support\Str;
use JsonException;
use OpenAI\Responses\StreamResponse;
use Spectra\Contracts\StreamsResponse;
use Spectra\Data\TokenMetrics;
use Spectra\Models\SpectraRequest;
use Spectra\Spectra as SpectraManager;
use Spectra\Support\Payload;
use Spectra\Support\ProviderRegistry;
use Throwable;

/**
 * Accumulates a streamed AI response and records it once the stream ends.
 *
 * Two ways in:
 *
 * - Automatic: the Guzzle / HTTP-client middleware wraps the response body in
 *   a TrackedStream, which feeds every event here via ingest() and calls
 *   finish() at the end of the body. No user code is needed.
 * - Manual, for SDKs that don't go through Spectra's middleware:
 *
 *     $tracker = Spectra::stream();
 *     foreach ($tracker->track($stream) as $chunk) {
 *         echo $chunk;
 *     }
 *     $result = $tracker->finish();
 *
 * When both happen for the same request (the middleware saw it and the user
 * also wrapped it), the manual tracker claims the request and the automatic
 * one stays silent, so it is recorded once.
 *
 * A stream that throws while it is read is recorded as a failed request: the
 * middleware's body reports the exception here, and a manual tracker keeps
 * the exception its loop saw. A manual tracker that was never finished
 * records what it has when it is released, so a stream that broke off
 * before `finish()` is not lost.
 */
class StreamingTracker
{
    /**
     * Responses API output item types worth keeping the full response for
     * (media storage, tool-call counting).
     */
    protected const CAPTURE_OUTPUT_TYPES = [
        'image_generation_call',
        'web_search_call',
        'file_search_call',
        'code_interpreter_call',
        'computer_call',
        'mcp_call',
        'local_shell_call',
        'shell_call',
        'apply_patch_call',
        'custom_tool_call',
        'tool_search_call',
        'x_search_call',
    ];

    /** Responses API events that carry the final response object. */
    protected const TERMINAL_RESPONSE_EVENTS = ['response.completed', 'response.incomplete', 'response.failed'];

    protected ?RequestContext $context = null;

    protected ?StreamHandler $streamHandler = null;

    protected float $startTime;

    protected ?float $firstTokenTime = null;

    protected string $content = '';

    /** @var array<string, mixed> */
    protected array $usage = [];

    protected ?string $finishReason = null;

    protected ?string $responseId = null;

    protected ?string $model = null;

    protected ?Throwable $error = null;

    /** Error message sent inside the stream (e.g. an Anthropic `error` event). */
    protected ?string $streamError = null;

    /** @var array<string, mixed> */
    protected array $completedResponse = [];

    /** @var array<string, mixed> */
    protected array $lastChunkData = [];

    protected bool $contextInitialized = false;

    /** Whether any stream chunk, manual content, or manual usage was recorded. */
    protected bool $sawStreamData = false;

    protected ?SpectraRequest $result = null;

    /**
     * SDK stream classes and the provider they belong to.
     *
     * @var array<string, string>
     */
    protected const STREAM_CLASS_MAP = [
        StreamResponse::class => 'openai',
        'OpenAI\Responses\Streaming\StreamResponse' => 'openai',
    ];

    /**
     * Create a new streaming tracker; the provider and model are detected from the stream when not given.
     *
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        protected ?string $provider = null,
        protected ?string $requestedModel = null,
        protected array $options = []
    ) {
        $this->startTime = $this->now();
    }

    /**
     * Create a tracker bound to a request context the middleware already started.
     */
    public static function forContext(RequestContext $context): self
    {
        $tracker = new self($context->provider, $context->model);
        $tracker->context = $context;
        $tracker->startTime = (float) $context->getStartedAt()->format('U.u');
        $tracker->contextInitialized = true;
        $context->isStreaming = true;
        $tracker->resolveStreamHandler();

        return $tracker;
    }

    /**
     * Start the stream's request context once, taking over the middleware's pending one if any.
     */
    protected function initializeContext(): void
    {
        if ($this->contextInitialized) {
            return;
        }

        // Reuse the context the middleware started (endpoint, request data,
        // pricing tier, ...) and take over recording it.
        $pending = app(SpectraManager::class)->consumePendingStreamContext();

        if ($pending !== null) {
            $pending->streamClaimed = true;
            $context = $pending;
            $this->startTime = (float) $pending->getStartedAt()->format('U.u');
            $this->provider ??= $pending->provider;
            $this->requestedModel ??= $pending->model;
            $this->applyOptions($pending);
        } else {
            $context = app(SpectraManager::class)->startRequest(
                $this->provider ?? 'unknown',
                $this->requestedModel ?? 'unknown',
                array_merge(
                    $this->options,
                    ['metadata' => array_merge($this->options['metadata'] ?? [], ['streaming' => true])]
                )
            );
        }

        $this->context = $context;
        $context->isStreaming = true;
        $this->resolveStreamHandler();
        $this->contextInitialized = true;
    }

    /**
     * Resolve the streaming handler from the provider registry.
     *
     * First tries endpoint-based resolution. If no endpoint is available
     * (e.g. standalone Spectra::stream() without middleware), falls back to
     * the first handler that implements StreamsResponse.
     */
    protected function resolveStreamHandler(): void
    {
        if ($this->provider === null) {
            return;
        }

        $provider = app(ProviderRegistry::class)->provider($this->provider);

        if ($provider === null) {
            return;
        }

        $endpoint = $this->context->endpoint ?? '';

        if (filled($endpoint)) {
            $handler = $provider->resolveHandler($endpoint);

            if ($handler instanceof StreamsResponse) {
                $this->streamHandler = $handler->streamingHandler();

                // Don't set modelType yet. Some endpoints are shared across
                // modalities and need response-shape disambiguation.
                return;
            }
        }

        // Fallback: the first handler that supports streaming. The model type
        // is resolved later from the response, since this guess may be wrong.
        foreach ($provider->handlers() as $handler) {
            if ($handler instanceof StreamsResponse) {
                $this->streamHandler = $handler->streamingHandler();

                return;
            }
        }
    }

    /**
     * Wrap a stream (an SDK stream object, any iterable of chunks, or the raw
     * text of a server-sent-events body) and yield its text as it arrives.
     *
     * @param  iterable<mixed>|string  $stream
     * @return Generator<string>
     */
    public function track(iterable|string $stream): Generator
    {
        $this->provider ??= $this->detectProvider($stream);

        if (is_string($stream)) {
            $stream = $this->parseRawStream($stream);
        }

        // ingest() never throws, so anything caught here came from the
        // caller's stream and is theirs to handle.
        try {
            foreach ($stream as $chunk) {
                $text = $this->ingest($chunk);

                // A whitespace-only delta is real text, so only '' is held back.
                if ($text !== null && $text !== '') {
                    yield $text;
                }
            }
        } catch (Throwable $e) {
            $this->error = $e;

            // If the stream died before its context was claimed, take the
            // pending middleware context with us — otherwise the next stream
            // in this process would silently inherit it.
            if (! $this->contextInitialized) {
                $pending = app(SpectraManager::class)->consumePendingStreamContext();

                if ($pending !== null) {
                    $pending->streamClaimed = true;
                    $this->context = $pending;
                    $this->contextInitialized = true;
                }
            }

            throw $e;
        }
    }

    /**
     * Process one stream chunk (an SDK chunk object or a decoded event) and
     * return its text, if any. A chunk it cannot read is reported and
     * skipped, so it never breaks the caller's loop over the stream.
     */
    public function ingest(mixed $chunk): ?string
    {
        return rescue(function () use ($chunk) {
            // Delay context init to the first chunk so the model can be detected.
            if (! $this->contextInitialized) {
                $this->detectModelFromChunk($chunk);
                $this->initializeContext();
            }

            $this->sawStreamData = true;

            return $this->processChunk($chunk);
        });
    }

    /**
     * Parse a raw stream body into its events.
     *
     * @return Generator<array<string, mixed>>
     */
    protected function parseRawStream(string $body): Generator
    {
        $parser = new StreamEventParser;

        yield from $parser->push($body);
        yield from $parser->finish();
    }

    /**
     * Detect the provider from an SDK stream's class name.
     */
    protected function detectProvider(mixed $stream): string
    {
        if (is_object($stream)) {
            $className = $stream::class;

            if (isset(self::STREAM_CLASS_MAP[$className])) {
                return self::STREAM_CLASS_MAP[$className];
            }

            // Stream classes missing from the map still carry the provider's name.
            $classNameLower = Str::lower($className);
            if (Str::contains($classNameLower, 'openai')) {
                return 'openai';
            }
            if (Str::contains($classNameLower, 'anthropic')) {
                return 'anthropic';
            }
            if (Str::contains($classNameLower, ['google', 'gemini'])) {
                return 'google';
            }
        }

        return 'unknown';
    }

    /**
     * Try to detect model from first chunk.
     *
     * Only sets requestedModel if it wasn't already provided by the user.
     * The model from the response is the "snapshot" (versioned model name).
     */
    protected function detectModelFromChunk(mixed $chunk): void
    {
        if ($this->requestedModel !== null) {
            return;
        }

        $data = $this->normalizeChunk($chunk);

        // Generic detection — covers OpenAI (model), Anthropic (message.model), Google (modelVersion)
        $model = $data['model'] ?? $data['message']['model'] ?? $data['response']['model'] ?? $data['modelVersion'] ?? null;

        if (is_string($model)) {
            $this->requestedModel = $model;
        }
    }

    /**
     * Read the text, usage, finish reason, model and errors from one stream chunk.
     */
    protected function processChunk(mixed $chunk): ?string
    {
        $data = $this->normalizeChunk($chunk);
        $this->lastChunkData = $data;

        // Each field is read on its own, so a malformed one only loses itself.
        rescue(fn () => $this->captureStreamError($data));

        $text = rescue(fn () => $this->extractText($data));

        // A whitespace-only delta is the first token too.
        if ($text !== null && $text !== '' && $this->firstTokenTime === null) {
            $this->firstTokenTime = $this->now();
            $ttft = (int) (($this->firstTokenTime - $this->startTime) * 1000);
            $this->context?->setTimeToFirstToken($ttft);
        }

        if ($text !== null) {
            $this->content .= $text;
        }

        rescue(fn () => $this->extractUsage($data));
        rescue(fn () => $this->extractFinishReason($data));
        rescue(fn () => $this->extractModel($data));
        rescue(fn () => $this->captureCompletedResponse($data));

        return $text;
    }

    /**
     * Convert a stream chunk, array or SDK object, into raw stream event fields.
     *
     * @return array<string, mixed>
     */
    protected function normalizeChunk(mixed $chunk): array
    {
        if (is_array($chunk)) {
            $data = $chunk;
        } elseif (is_object($chunk) && method_exists($chunk, 'toArray')) {
            $data = $chunk->toArray();
        } elseif (is_object($chunk)) {
            try {
                $data = json_decode(json_encode($chunk, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR) ?? [];
            } catch (JsonException) {
                return [];
            }
        } else {
            return [];
        }

        if (! is_array($data)) {
            return [];
        }

        // OpenAI PHP SDK toArray() returns ['event' => '...', 'data' => [...]]
        // but our extraction methods expect the raw SSE format: ['type' => '...', ...]
        if (isset($data['event']) && is_string($data['event']) && is_array($data['data'] ?? null)) {
            $type = $data['event'];

            // Terminal events nest the full response under 'response' in raw SSE
            if (in_array($type, self::TERMINAL_RESPONSE_EVENTS, true)) {
                return ['type' => $type, 'response' => $data['data']];
            }

            // All other events flatten data fields alongside 'type'
            return array_merge($data['data'], ['type' => $type]);
        }

        return $data;
    }

    /**
     * Capture the error message of a stream error event or a failed OpenAI response.
     *
     * @param  array<string, mixed>  $data
     */
    protected function captureStreamError(array $data): void
    {
        $type = $data['type'] ?? null;

        // OpenAI Responses: the final event of a failed response; its usage still counts.
        if ($type === 'response.failed') {
            $message = $data['response']['error']['message'] ?? null;
            $this->streamError = filled($message) ? $message : 'Response failed';

            return;
        }

        if ($type !== 'error') {
            return;
        }

        // Anthropic nests the error ({type: error, error: {...}}); OpenAI puts
        // message and code at the top level.
        $error = $data['error'] ?? $data;

        $this->streamError = is_array($error)
            ? (string) ($error['message'] ?? $error['code'] ?? $error['type'] ?? json_encode($error))
            : (string) $error;
    }

    /**
     * Keep the full Responses API response when its output holds media or tool calls.
     *
     * @param  array<string, mixed>  $data
     */
    protected function captureCompletedResponse(array $data): void
    {
        if (! in_array($data['type'] ?? null, self::TERMINAL_RESPONSE_EVENTS, true)
            || ! is_array($data['response']['output'] ?? null)) {
            return;
        }

        foreach ($data['response']['output'] as $item) {
            if (in_array($item['type'] ?? null, self::CAPTURE_OUTPUT_TYPES, true)) {
                $this->completedResponse = $data['response'];

                return;
            }
        }
    }

    /**
     * Extract the text delta of a chunk through the provider's stream handler.
     *
     * @param  array<string, mixed>  $data
     */
    protected function extractText(array $data): ?string
    {
        if ($this->streamHandler !== null) {
            return $this->streamHandler->text($data);
        }

        return $this->extractGenericText($data);
    }

    /**
     * Extract the text delta of a chunk from the common chunk shapes when no handler is known.
     *
     * @param  array<string, mixed>  $data
     */
    protected function extractGenericText(array $data): ?string
    {
        $text = $data['choices'][0]['delta']['content']
            ?? $data['delta']['text']
            ?? $data['text']
            ?? $data['content']
            ?? null;

        return is_string($text) ? $text : null;
    }

    /**
     * Extract the token usage of a chunk; it typically arrives in the final chunk of the stream.
     *
     * @param  array<string, mixed>  $data
     */
    protected function extractUsage(array $data): void
    {
        if (blank($this->usage)) {
            $this->usage = (new TokenMetrics)->toUsageArray();
        }

        if ($this->streamHandler !== null) {
            $this->usage = $this->streamHandler->usage($data, $this->usage);

            return;
        }

        // Generic fallback: standard usage object (also Groq's x_groq.usage)
        $usage = $data['usage'] ?? $data['x_groq']['usage'] ?? null;

        if (is_array($usage)) {
            $this->usage = array_merge($this->usage, [
                'prompt_tokens' => $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0,
                'cached_tokens' => $usage['prompt_tokens_details']['cached_tokens']
                    ?? $usage['input_tokens_details']['cached_tokens']
                    ?? 0,
                'reasoning_tokens' => $usage['completion_tokens_details']['reasoning_tokens']
                    ?? $usage['output_tokens_details']['reasoning_tokens']
                    ?? 0,
            ]);
        }
    }

    /**
     * Extract the finish reason of a chunk, keeping the last one the stream sent.
     *
     * @param  array<string, mixed>  $data
     */
    protected function extractFinishReason(array $data): void
    {
        if ($this->streamHandler !== null) {
            $reason = $this->streamHandler->finishReason($data);

            if ($reason !== null) {
                $this->finishReason = $reason;
            }

            return;
        }

        if (isset($data['choices'][0]['finish_reason'])) {
            $this->finishReason = $data['choices'][0]['finish_reason'];
        }
    }

    /**
     * Extract the model and response id from the first chunk that names them.
     *
     * @param  array<string, mixed>  $data
     */
    protected function extractModel(array $data): void
    {
        if ($this->streamHandler !== null) {
            $result = $this->streamHandler->model($data);

            if ($result !== null) {
                $this->model ??= $result['model'] ?? null;
                $this->responseId ??= $result['id'] ?? null;
            }

            return;
        }

        if (filled($data['model'] ?? null) && $this->model === null) {
            $this->model = $data['model'];
        }

        if ($this->responseId === null) {
            $id = $data['id'] ?? $data['responseId'] ?? null;
            $this->responseId = is_string($id) ? $id : null;
        }
    }

    /**
     * Record the stream. Safe to call more than once; later calls return the
     * first result. A tracking failure is reported and returns an unsaved
     * request, so it never reaches the caller.
     */
    public function finish(): SpectraRequest
    {
        if ($this->result !== null) {
            return $this->result;
        }

        return $this->result = rescue(fn () => $this->record(), fn () => new SpectraRequest);
    }

    /**
     * Record the stream as failed with the exception that broke it off,
     * keeping the usage it reported before. Safe to call more than once.
     */
    public function fail(Throwable $exception): SpectraRequest
    {
        $this->error ??= $exception;

        return $this->finish();
    }

    /**
     * Determine if finish() has already recorded this stream.
     */
    public function isFinished(): bool
    {
        return $this->result !== null;
    }

    /**
     * Record a stream that was never finished when the tracker is released:
     * one whose loop threw, or one that claimed the middleware's context,
     * which stays silent for it and would otherwise never be recorded.
     */
    public function __destruct()
    {
        if ($this->result !== null || ! $this->contextInitialized || $this->context === null) {
            return;
        }

        if ($this->error === null && ! $this->context->streamClaimed) {
            return;
        }

        try {
            $this->finish();
        } catch (Throwable) {
            // The application may be shutting down; tracking is best effort.
        }
    }

    /**
     * Record the finished stream as a request, skipping streams that produced nothing.
     */
    protected function record(): SpectraRequest
    {
        $manager = app(SpectraManager::class);

        // Nothing streamed, nothing set manually, no error: skip recording
        // instead of persisting an all-zero request row. Content is compared
        // with '' because whitespace-only streamed text still counts.
        if (! $this->contextInitialized
            && $this->error === null
            && ! $this->sawStreamData
            && $this->content === ''
            && blank($this->usage)
            && blank($this->completedResponse)
        ) {
            $manager->consumePendingStreamContext();

            return new SpectraRequest;
        }

        if (! $this->contextInitialized) {
            $this->initializeContext();
        }

        assert($this->context !== null);
        $context = $this->context;

        $manager->clearPendingStreamContext($context);

        // Apply TTFT if it was captured before context was initialized (appendContent path)
        if ($this->firstTokenTime !== null && $context->timeToFirstTokenMs === null) {
            $ttft = (int) (($this->firstTokenTime - $this->startTime) * 1000);
            $context->setTimeToFirstToken($ttft);
        }

        // Snapshot is the actual versioned model from the API; context->model is the user's requested model
        if ($this->model !== null) {
            $context->snapshot = $this->model;
        }

        if ($this->responseId !== null) {
            $context->responseId = $this->responseId;
        }

        rescue(fn () => $this->applyStreamExtras());

        // The tier the request asked for, when the stream didn't report one.
        if ($context->pricingTier === null && $context->requestedPricingTier !== null) {
            $context->withPricingTier($context->requestedPricingTier);
        }

        $context->finishReason = $this->finishReason ?? $context->finishReason;

        if ($this->error !== null) {
            $context->setUsage($this->usage);

            return $manager->recordFailure($context, $this->error);
        }

        if ($this->streamError !== null) {
            $context->setUsage($this->usage);

            return $manager->recordFailure($context, new StreamErrorException($this->streamError));
        }

        // When the stream contained a completed response (e.g. image generation),
        // delegate to ResponseProcessor to get metrics, media storage, model type, etc.
        if (filled($this->completedResponse)) {
            $result = app(ResponseProcessor::class)->process($context, $this->completedResponse);

            if ($result !== null) {
                [$responseBody, $usage] = $result;
                $responseBody['streaming'] = true;

                return $manager->recordSuccess($context, $responseBody, $usage ?? []);
            }
        }

        $context->finishReason = $this->finishReason;
        $context->reasoningTokens = Payload::int($this->usage['reasoning_tokens'] ?? null);

        if ($this->finishReason !== null && in_array($this->finishReason, ['tool_calls', 'tool_use'], true)) {
            $context->hasToolCalls = true;
        }

        // Resolve model_type if it wasn't set during handler resolution
        // (happens when no endpoint is available, e.g. standalone Spectra::stream())
        if ($context->modelType === null && $this->provider !== null) {
            rescue(fn () => $this->resolveModelType($context));
        }

        $response = [
            'id' => $this->responseId,
            'content' => $this->content,
            'finish_reason' => $this->finishReason,
            'model' => $this->model ?? $this->requestedModel ?? 'unknown',
            'streaming' => true,
        ];

        return $manager->recordSuccess($context, $response, $this->usage);
    }

    /**
     * Set the model type from the handler that matches the last chunk, or
     * from the endpoint alone when no handler matches.
     */
    protected function resolveModelType(RequestContext $context): void
    {
        $providerInstance = app(ProviderRegistry::class)->provider($this->provider ?? '');
        $endpoint = $context->endpoint ?? '';

        if ($providerInstance === null) {
            return;
        }

        $handler = $providerInstance->resolveHandler($endpoint, $this->lastChunkData);

        if ($handler !== null) {
            $context->modelType = $handler->modelType()->value;
        } elseif (filled($endpoint)) {
            $context->modelType = $providerInstance->resolveModelType($endpoint)?->value;
        }
    }

    /**
     * Apply the options given to Spectra::stream() to a context the
     * middleware started, so its tags, metadata, trackable and tier are kept.
     */
    protected function applyOptions(RequestContext $context): void
    {
        $options = $this->options;

        if (filled($options['tags'] ?? null) && is_array($options['tags'])) {
            $context->tags = collect([...$context->tags, ...$options['tags']])->uniqueStrict()->values()->all();
        }

        if (filled($options['metadata'] ?? null) && is_array($options['metadata'])) {
            $context->metadata = array_merge($context->metadata, $options['metadata']);
        }

        if (isset($options['trackable_type'], $options['trackable_id'])) {
            $context->trackableType = (string) $options['trackable_type'];
            $context->trackableId = $options['trackable_id'];
        }

        if (isset($options['pricing_tier']) && is_string($options['pricing_tier'])) {
            $context->withPricingTier($options['pricing_tier']);
        }

        if (isset($options['conversation_id']) && is_string($options['conversation_id'])) {
            $context->inConversation($options['conversation_id'], isset($options['conversation_turn']) ? (int) $options['conversation_turn'] : null);
        }
    }

    /**
     * Apply the reserved keys a StreamHandler may add to the usage array
     * (see StreamHandler::usage()).
     */
    protected function applyStreamExtras(): void
    {
        if ($this->context === null) {
            return;
        }

        $usage = $this->usage;

        if (isset($usage['reported_cost_in_cents']) && is_numeric($usage['reported_cost_in_cents'])) {
            $this->context->reportedCostInCents = Payload::float($usage['reported_cost_in_cents']);
        }

        if (isset($usage['cost_multiplier']) && is_numeric($usage['cost_multiplier'])) {
            $this->context->costMultiplier = Payload::float($usage['cost_multiplier']);
        }

        if (filled($usage['pricing_tier'] ?? null) && $this->context->pricingTier === null) {
            $this->context->withPricingTier($usage['pricing_tier']);
        }

        if (is_array($usage['tool_calls'] ?? null)) {
            $counts = $this->context->toolCallCounts;

            foreach ($usage['tool_calls'] as $type => $count) {
                if (is_string($type) && is_numeric($count) && $count > 0) {
                    $counts[$type] = max($counts[$type] ?? 0, Payload::int($count));
                }
            }

            if ($counts !== []) {
                $this->context->toolCallCounts = $counts;
                $this->context->hasToolCalls = true;
            }
        }
    }

    /**
     * Get the text streamed so far.
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * Get the token usage the stream reported.
     *
     * @return array<string, mixed>
     */
    public function getUsage(): array
    {
        return $this->usage;
    }

    /**
     * Get the stream's request context, starting it when needed.
     */
    public function getContext(): RequestContext
    {
        if (! $this->contextInitialized) {
            $this->initializeContext();
        }

        assert($this->context !== null);

        return $this->context;
    }

    /**
     * Get the time to first token in milliseconds, or null before any text arrived.
     */
    public function getTimeToFirstToken(): ?int
    {
        if ($this->firstTokenTime === null) {
            return null;
        }

        return (int) (($this->firstTokenTime - $this->startTime) * 1000);
    }

    /**
     * Get the current time as a float timestamp, on the same clock as the context's started_at.
     *
     * The start time comes from that Carbon clock, so measuring the first token
     * with microtime() would mix two clocks: with a frozen or travelled test
     * clock the difference is days, not milliseconds.
     */
    protected function now(): float
    {
        return (float) now()->format('U.u');
    }

    /**
     * Set the usage manually, when it comes from a separate source.
     *
     * Accepts the TokenMetrics usage keys (prompt_tokens, completion_tokens,
     * cached_tokens, cache_write_tokens, reasoning_tokens, ...).
     *
     * @param  array<string, int>  $usage
     */
    public function setUsage(array $usage): self
    {
        $this->usage = TokenMetrics::fromArray($usage)->toUsageArray();

        return $this;
    }

    /**
     * Append content manually, for custom stream processing.
     */
    public function appendContent(string $text): self
    {
        // Whitespace-only text is still the first token, so only '' is ignored.
        if ($this->firstTokenTime === null && $text !== '') {
            $this->firstTokenTime = $this->now();

            if ($this->contextInitialized && $this->context !== null) {
                $ttft = (int) (($this->firstTokenTime - $this->startTime) * 1000);
                $this->context->setTimeToFirstToken($ttft);
            }
        }

        $this->content .= $text;

        return $this;
    }
}
