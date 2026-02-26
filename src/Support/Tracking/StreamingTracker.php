<?php

namespace Spectra\Support\Tracking;

use Generator;
use Spectra\Contracts\StreamsResponse;
use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;
use Spectra\Spectra as SpectraManager;
use Spectra\Support\ProviderRegistry;
use Throwable;

/**
 * Helper for tracking streaming AI responses.
 *
 * Usage:
 *   $tracker = Spectra::stream();
 *   foreach ($tracker->track($stream) as $chunk) {
 *       echo $chunk; // Process each text chunk
 *   }
 *   $result = $tracker->finish(); // Returns SpectraRequest
 */
class StreamingTracker
{
    protected ?RequestContext $context = null;

    protected ?StreamHandler $streamHandler = null;

    protected float $startTime;

    protected ?float $firstTokenTime = null;

    protected string $content = '';

    /** @var array<string, int> */
    protected array $usage = [];

    protected ?string $finishReason = null;

    protected ?string $responseId = null;

    protected ?string $model = null;

    protected ?Throwable $error = null;

    /** @var array<string, mixed> */
    protected array $completedResponse = [];

    /** @var array<string, mixed> */
    protected array $lastChunkData = [];

    protected bool $contextInitialized = false;

    /** @var array<string, string> */
    protected static array $streamClassMap = [
        'OpenAI\Responses\StreamResponse' => 'openai',
        'OpenAI\Responses\Streaming\StreamResponse' => 'openai',
    ];

    /**
     * Provider and model are optional - they will be auto-detected from the stream.
     */
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        protected ?string $provider = null,
        protected ?string $requestedModel = null,
        protected array $options = []
    ) {
        $this->startTime = microtime(true);
    }

    protected function initializeContext(): void
    {
        if ($this->contextInitialized) {
            return;
        }

        // Reuse context from GuzzleMiddleware if available (has endpoint, request data, pricing tier, etc.)
        $pending = app(SpectraManager::class)->consumePendingStreamContext();

        if ($pending !== null) {
            $this->context = $pending;
            $this->startTime = (float) $pending->getStartedAt()->format('U.u');
            $this->provider = $this->provider ?? $pending->provider;
            $this->requestedModel = $this->requestedModel ?? $pending->model;
        } else {
            $this->context = Spectra::startRequest(
                $this->provider ?? 'unknown',
                $this->requestedModel ?? 'unknown',
                array_merge(
                    $this->options,
                    ['metadata' => array_merge($this->options['metadata'] ?? [], ['streaming' => true])]
                )
            );
        }

        $this->context->isStreaming = true;
        $this->resolveStreamHandler();
        $this->contextInitialized = true;
    }

    /**
     * Resolve the streaming handler from the provider registry.
     *
     * First tries endpoint-based resolution. If no endpoint is available
     * (e.g. standalone Spectra::stream() without GuzzleMiddleware), falls
     * back to the first handler that implements StreamsResponse.
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

        // Try endpoint-based resolution first
        if ($endpoint !== '') {
            $handler = $provider->resolveHandler($endpoint);

            if ($handler instanceof StreamsResponse) {
                $this->streamHandler = $handler->streamingHandler();

                // Don't set modelType yet. Some endpoints are shared across
                // modalities and need response-shape disambiguation.
                return;
            }
        }

        // Fallback: find the first handler that supports streaming.
        // Don't set modelType here — the fallback handler may not match the
        // actual response type (e.g. text handler picked for image stream).
        // ResponseProcessor will resolve it from the full response later.
        foreach ($provider->handlers() as $handler) {
            if ($handler instanceof StreamsResponse) {
                $this->streamHandler = $handler->streamingHandler();

                return;
            }
        }
    }

    /**
     * @param  iterable<mixed>  $stream
     * @return Generator<string>
     */
    public function track(iterable $stream): Generator
    {
        if ($this->provider === null) {
            $this->provider = $this->detectProvider($stream);
        }

        try {
            foreach ($stream as $chunk) {
                // Delay context init to first chunk so we can detect the model
                if (! $this->contextInitialized) {
                    $this->detectModelFromChunk($chunk);
                    $this->initializeContext();
                }

                $text = $this->processChunk($chunk);

                if ($text !== null && $text !== '') {
                    yield $text;
                }
            }
        } catch (Throwable $e) {
            $this->error = $e;
            throw $e;
        }
    }

    protected function detectProvider(mixed $stream): string
    {
        if (is_object($stream)) {
            $className = get_class($stream);

            if (isset(self::$streamClassMap[$className])) {
                return self::$streamClassMap[$className];
            }

            // Partial match fallback
            $classNameLower = strtolower($className);
            if (str_contains($classNameLower, 'openai')) {
                return 'openai';
            }
            if (str_contains($classNameLower, 'anthropic')) {
                return 'anthropic';
            }
            if (str_contains($classNameLower, 'google') || str_contains($classNameLower, 'gemini')) {
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
        if (isset($data['model'])) {
            $this->requestedModel = $data['model'];

            return;
        }

        if (isset($data['message']['model'])) {
            $this->requestedModel = $data['message']['model'];

            return;
        }

        if (isset($data['modelVersion'])) {
            $this->requestedModel = $data['modelVersion'];

            return;
        }
    }

    protected function processChunk(mixed $chunk): ?string
    {
        $data = $this->normalizeChunk($chunk);
        $this->lastChunkData = $data;

        $text = $this->extractText($data);

        if ($text !== null && $text !== '' && $this->firstTokenTime === null) {
            $this->firstTokenTime = microtime(true);
            $ttft = (int) (($this->firstTokenTime - $this->startTime) * 1000);
            $this->context?->setTimeToFirstToken($ttft);
        }

        if ($text !== null) {
            $this->content .= $text;
        }

        $this->extractUsage($data);
        $this->extractFinishReason($data);
        $this->extractModel($data);
        $this->captureCompletedResponse($data);

        return $text;
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeChunk(mixed $chunk): array
    {
        if (is_array($chunk)) {
            $data = $chunk;
        } elseif (is_object($chunk)) {
            $data = method_exists($chunk, 'toArray')
                ? $chunk->toArray()
                : (($json = json_encode($chunk)) !== false ? (json_decode($json, true) ?? []) : []);
        } else {
            return [];
        }

        // OpenAI PHP SDK toArray() returns ['event' => '...', 'data' => [...]]
        // but our extraction methods expect the raw SSE format: ['type' => '...', ...]
        if (isset($data['event']) && is_string($data['event']) && isset($data['data']) && is_array($data['data'])) {
            $type = $data['event'];

            // response.completed nests the full response under 'response' in raw SSE
            if ($type === 'response.completed') {
                return ['type' => $type, 'response' => $data['data']];
            }

            // All other events flatten data fields alongside 'type'
            return array_merge($data['data'], ['type' => $type]);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function captureCompletedResponse(array $data): void
    {
        if (($data['type'] ?? null) !== 'response.completed' || ! isset($data['response']['output'])) {
            return;
        }

        // Capture the full response when it contains output items that need
        // special processing (media storage, tool call counting, etc.)
        $captureTypes = [
            'image_generation_call',
            'web_search_call',
            'file_search_call',
            'code_interpreter_call',
            'computer_call',
            'mcp_tool_call',
            'local_shell_call',
        ];

        foreach ($data['response']['output'] as $item) {
            if (in_array($item['type'] ?? null, $captureTypes, true)) {
                $this->completedResponse = $data['response'];

                return;
            }
        }
    }

    /**
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
     * @param  array<string, mixed>  $data
     */
    protected function extractGenericText(array $data): ?string
    {
        return $data['choices'][0]['delta']['content']
            ?? $data['delta']['text']
            ?? $data['text']
            ?? $data['content']
            ?? null;
    }

    /**
     * Usage data typically arrives in the final chunk of the stream.
     */
    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractUsage(array $data): void
    {
        if (empty($this->usage)) {
            $this->usage = [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'cached_tokens' => 0,
                'reasoning_tokens' => 0,
            ];
        }

        if ($this->streamHandler !== null) {
            $this->usage = $this->streamHandler->usage($data, $this->usage);

            return;
        }

        // Generic fallback: standard usage object
        if (isset($data['usage'])) {
            $usage = $data['usage'];
            $this->usage = [
                'prompt_tokens' => $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0,
                'cached_tokens' => $usage['prompt_tokens_details']['cached_tokens']
                    ?? $usage['input_tokens_details']['cached_tokens']
                    ?? 0,
                'reasoning_tokens' => $usage['completion_tokens_details']['reasoning_tokens']
                    ?? $usage['output_tokens_details']['reasoning_tokens']
                    ?? 0,
            ];
        }
    }

    /**
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

        // Generic fallback
        if (isset($data['choices'][0]['finish_reason'])) {
            $this->finishReason = $data['choices'][0]['finish_reason'];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractModel(array $data): void
    {
        if ($this->streamHandler !== null) {
            $result = $this->streamHandler->model($data);

            if ($result !== null) {
                $this->model = $this->model ?? ($result['model'] ?? null);
                $this->responseId = $this->responseId ?? ($result['id'] ?? null);
            }

            return;
        }

        // Generic fallback
        if (isset($data['model']) && $this->model === null) {
            $this->model = $data['model'];
        }

        if ($this->responseId === null) {
            $this->responseId = $data['id'] ?? $data['responseId'] ?? null;
        }
    }

    public function finish(): SpectraRequest
    {
        if (! $this->contextInitialized) {
            $this->initializeContext();
        }

        assert($this->context !== null);

        // Apply TTFT if it was captured before context was initialized (appendContent path)
        if ($this->firstTokenTime !== null && $this->context->timeToFirstTokenMs === null) {
            $ttft = (int) (($this->firstTokenTime - $this->startTime) * 1000);
            $this->context->setTimeToFirstToken($ttft);
        }

        if ($this->error !== null) {
            return Spectra::recordFailure($this->context, $this->error);
        }

        // Snapshot is the actual versioned model from the API; context->model is the user's requested model
        if ($this->model !== null) {
            $this->context->snapshot = $this->model;
        }

        if ($this->responseId !== null) {
            $this->context->responseId = $this->responseId;
        }

        // When the stream contained a completed response (e.g. image generation),
        // delegate to ResponseProcessor to get metrics, media storage, model type, etc.
        if (! empty($this->completedResponse)) {
            $result = app(ResponseProcessor::class)->process($this->context, $this->completedResponse);

            if ($result !== null) {
                [$responseBody, $usage] = $result;
                $responseBody['streaming'] = true;

                return Spectra::recordSuccess($this->context, $responseBody, $usage ?? []);
            }
        }

        $this->context->finishReason = $this->finishReason;
        $this->context->reasoningTokens = $this->usage['reasoning_tokens'] ?? 0;

        // Detect tool calls from finish_reason
        if ($this->finishReason !== null && in_array($this->finishReason, ['tool_calls', 'tool_use'], true)) {
            $this->context->hasToolCalls = true;
        }

        // Resolve model_type if it wasn't set during handler resolution
        // (happens when no endpoint is available, e.g. standalone Spectra::stream())
        if ($this->context->modelType === null && $this->provider !== null) {
            $providerInstance = app(ProviderRegistry::class)->provider($this->provider);
            $endpoint = $this->context->endpoint ?? '';

            if ($providerInstance !== null) {
                $handler = $providerInstance->resolveHandler($endpoint, $this->lastChunkData);

                if ($handler !== null) {
                    $this->context->modelType = $handler->modelType()->value;
                } elseif ($endpoint !== '') {
                    $this->context->modelType = $providerInstance->resolveModelType($endpoint)?->value;
                }
            }
        }

        $response = [
            'id' => $this->responseId,
            'content' => $this->content,
            'finish_reason' => $this->finishReason,
            'model' => $this->model ?? $this->requestedModel ?? 'unknown',
            'streaming' => true,
        ];

        return Spectra::recordSuccess($this->context, $response, $this->usage);
    }

    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * @return array<string, int>
     */
    public function getUsage(): array
    {
        return $this->usage;
    }

    public function getContext(): RequestContext
    {
        if (! $this->contextInitialized) {
            $this->initializeContext();
        }

        assert($this->context !== null);

        return $this->context;
    }

    public function getTimeToFirstToken(): ?int
    {
        if ($this->firstTokenTime === null) {
            return null;
        }

        return (int) (($this->firstTokenTime - $this->startTime) * 1000);
    }

    /**
     * Manually set usage (useful when usage comes from a separate source).
     */
    /**
     * @param  array<string, int>  $usage
     */
    public function setUsage(array $usage): self
    {
        $this->usage = [
            'prompt_tokens' => $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0,
            'completion_tokens' => $usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0,
            'cached_tokens' => $usage['cached_tokens'] ?? 0,
            'reasoning_tokens' => $usage['reasoning_tokens'] ?? 0,
        ];

        return $this;
    }

    /**
     * Manually append content (useful for custom stream processing).
     */
    public function appendContent(string $text): self
    {
        if ($this->firstTokenTime === null && $text !== '') {
            $this->firstTokenTime = microtime(true);

            if ($this->contextInitialized && $this->context !== null) {
                $ttft = (int) (($this->firstTokenTime - $this->startTime) * 1000);
                $this->context->setTimeToFirstToken($ttft);
            }
        }

        $this->content .= $text;

        return $this;
    }
}
