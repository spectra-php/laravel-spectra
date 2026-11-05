<?php

declare(strict_types=1);

namespace Spectra\Support\Tracking;

use Illuminate\Support\Str;
use JsonException;
use Spectra\Contracts\ExtractsCostMultiplier;
use Spectra\Contracts\ExtractsCostMultiplierForModel;
use Spectra\Contracts\ExtractsPricingTierFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasExpiration;
use Spectra\Contracts\HasMedia;
use Spectra\Contracts\HasProviderLatency;
use Spectra\Contracts\ReportsJobFailure;
use Spectra\Contracts\ReturnsBinaryResponse;
use Spectra\Contracts\SkipsResponse;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\Provider;
use Spectra\Support\AudioDurationExtractor;
use Spectra\Support\Pricing\PricingLookup;
use Spectra\Support\ProviderRegistry;

/**
 * Turns a provider's response body into the fields of a tracked request.
 *
 * Every tracking path (watchers, Guzzle middleware, `Spectra::track()`,
 * streams with a final response) hands the decoded body here, so handler
 * resolution, metrics, model, tier, media and tool-call detection happen in
 * one place. Provider JSON changes without notice, so each step is guarded
 * on its own: a field it cannot read is reported and dropped, and the
 * request is still recorded with its cost.
 */
class ResponseProcessor
{
    /**
     * Process a mixed (object or array) response, normalizing to array first.
     *
     * Returns [responseBody, usage] on success, or null if the response should be skipped.
     *
     * @return array{0: array<string, mixed>, 1: array<string, int|null>|TokenMetrics|null}|null
     */
    public function processResponse(RequestContext $context, mixed $response): ?array
    {
        return $this->process($context, $this->toArray($response));
    }

    /**
     * Process a successful AI response, populating the context with extracted data.
     *
     * Idempotent — skips processing if the context has already been processed.
     *
     * Returns [responseBody, usage] on success, or null if the response should be skipped.
     *
     * @param  array<string, mixed>  $body
     * @return array{0: array<string, mixed>, 1: array<string, int|null>|TokenMetrics|null}|null
     */
    public function process(RequestContext $context, array $body): ?array
    {
        if ($context->processed) {
            return [$body, $this->extractUsageFallback($body)];
        }

        $providerInstance = app(ProviderRegistry::class)->provider($context->provider);
        $endpoint = $context->endpoint ?? '';

        // Every step below reads provider JSON that can change shape without
        // notice. Each one runs under its own rescue(), so a field of the wrong
        // type (a TypeError when it reaches a typed property or return) is
        // reported and left empty while the request is still recorded.
        $handler = rescue(fn () => $providerInstance?->resolveHandler($endpoint, $body));

        if ($handler instanceof SkipsResponse && rescue(fn () => $handler->shouldSkipResponse($body), false)) {
            return null;
        }

        $this->resolveModel($context, $providerInstance, $body, $endpoint);
        $this->resolvePricing($context, $providerInstance, $body);

        $metrics = rescue(fn () => $providerInstance?->extractMetrics($body, $endpoint, $context->requestData));
        $usage = $metrics->tokens ?? $this->extractUsageFallback($body);
        $finishReason = rescue(fn () => $providerInstance?->extractFinishReason($body, $endpoint));

        $context->finishReason = $finishReason ?? $context->finishReason;

        $this->recordToolCalls($context, $body, $metrics, $finishReason);
        $this->applyMetrics($context, $metrics);
        $this->applyHandlerDetails($context, $handler, $body);
        $this->storeMedia($context, $handler, $body);

        $context->modelType ??= $handler?->modelType()->value
            ?? rescue(fn () => $this->resolveModelType($providerInstance, $context)?->value);

        $this->resolveResponseDetails($context, $providerInstance, $body, $endpoint);

        $context->processed = true;

        $body = rescue(fn () => $this->stripBinaryData($body), $body);

        return [
            array_merge($body, ['finish_reason' => $finishReason]),
            $usage,
        ];
    }

    /**
     * Take the model from the response; when it differs from the requested
     * one it is the snapshot. Binary responses (TTS) and Whisper name the
     * model only in the request, so fall back to the request data.
     *
     * @param  array<string, mixed>  $body
     */
    protected function resolveModel(RequestContext $context, ?Provider $providerInstance, array $body, string $endpoint): void
    {
        rescue(function () use ($context, $providerInstance, $body, $endpoint) {
            $responseModel = $providerInstance?->extractModel($body, $endpoint) ?? data_get($body, 'model');

            if (filled($responseModel) && $responseModel !== $context->model) {
                if ($context->model === 'unknown') {
                    $context->model = $responseModel;
                } else {
                    $context->snapshot = $responseModel;
                }
            }
        });

        if ($context->model !== 'unknown' || blank($context->requestData)) {
            return;
        }

        rescue(function () use ($context, $providerInstance, $endpoint) {
            $requestModel = $providerInstance?->extractModelFromRequest($context->requestData, $endpoint)
                ?? $context->requestData['model']
                ?? null;

            if (filled($requestModel)) {
                $context->model = $requestModel;
            }
        });
    }

    /**
     * Set the pricing tier and the provider's cost multiplier. The tier the
     * provider says it served wins over what the request asked for (a
     * priority request can be downgraded, a project can default to flex);
     * an explicitly set tier wins over both.
     *
     * @param  array<string, mixed>  $body
     */
    protected function resolvePricing(RequestContext $context, ?Provider $providerInstance, array $body): void
    {
        if ($context->pricingTier === null) {
            rescue(function () use ($context, $providerInstance, $body) {
                $responseTier = $providerInstance instanceof ExtractsPricingTierFromResponse
                    ? $providerInstance->extractPricingTierFromResponse($body)
                    : null;

                $tier = $responseTier ?? $context->requestedPricingTier;

                if ($tier !== null) {
                    $context->withPricingTier($tier);
                }
            });
        }

        if ($providerInstance instanceof ExtractsCostMultiplier) {
            $context->costMultiplier = rescue(fn () => $providerInstance->extractCostMultiplier($body, $context->requestData)) ?? $context->costMultiplier;
        }

        if ($providerInstance instanceof ExtractsCostMultiplierForModel) {
            $context->costMultiplier = rescue(fn () => $providerInstance->extractCostMultiplierForModel($context->model, $body, $context->requestData)) ?? $context->costMultiplier;
        }
    }

    /**
     * Count tool calls from the body and the handler's metrics (the higher
     * count per type wins); without any, a tool-call finish reason still
     * marks the request.
     *
     * @param  array<string, mixed>  $body
     */
    protected function recordToolCalls(RequestContext $context, array $body, ?Metrics $metrics, ?string $finishReason): void
    {
        $toolCallCounts = rescue(fn () => $this->countToolCalls($body), []);

        foreach ($metrics->toolCalls ?? [] as $type => $count) {
            if ($count > 0) {
                $toolCallCounts[$type] = max($toolCallCounts[$type] ?? 0, $count);
            }
        }

        if (filled($toolCallCounts)) {
            $context->hasToolCalls = true;
            $context->toolCallCounts = $toolCallCounts;
        } elseif (! $context->hasToolCalls) {
            $context->hasToolCalls = $this->detectToolCallsFromFinishReason($finishReason);
        }
    }

    /** Copy the per-unit usage (images, pages, audio, video, searches) and any reported cost. */
    protected function applyMetrics(RequestContext $context, ?Metrics $metrics): void
    {
        if ($metrics === null) {
            return;
        }

        if ($metrics->reportedCostInCents !== null) {
            $context->reportedCostInCents = $metrics->reportedCostInCents;
        }

        if ($metrics->image !== null) {
            $context->imageCount = $metrics->image->count;
            $context->megapixels = $metrics->image->megapixels ?? $context->megapixels;
        }

        if ($metrics->document !== null) {
            $context->pageCount = $metrics->document->pages;
        }

        if ($metrics->audio !== null) {
            $context->durationSeconds = $metrics->audio->durationSeconds ?? $context->durationSeconds;
            $context->inputCharacters = $metrics->audio->inputCharacters ?? $context->inputCharacters;
        }

        if ($metrics->video !== null) {
            $context->videoCount = $metrics->video->count;
            $context->durationSeconds = $metrics->video->durationSeconds ?? $context->durationSeconds;
        }

        if ($metrics->search !== null) {
            $context->searchCount = $metrics->search->count;
        }
    }

    /**
     * Read what the handler's optional contracts provide: a binary TTS
     * response's duration (getID3, if installed), expiry, provider latency
     * and an async job's failure.
     *
     * @param  array<string, mixed>  $body
     */
    protected function applyHandlerDetails(RequestContext $context, ?Handler $handler, array $body): void
    {
        // Runs before media storage, which clears rawResponseBody.
        if ($handler instanceof ReturnsBinaryResponse
            && $context->durationSeconds === null
            && $context->rawResponseBody !== null
            && $handler->modelType() === ModelType::Tts
        ) {
            $rawResponseBody = $context->rawResponseBody;
            $context->durationSeconds = rescue(fn () => app(AudioDurationExtractor::class)->extract($rawResponseBody));
        }

        if ($handler instanceof HasExpiration) {
            $context->expiresAt = rescue(fn () => $handler->extractExpiresAt($body));
        }

        if ($handler instanceof HasProviderLatency) {
            $context->providerLatencyMs = rescue(fn () => $handler->extractProviderLatency($body));
        }

        if ($handler instanceof ReportsJobFailure && ($failure = rescue(fn () => $handler->extractFailure($body))) !== null) {
            $context->errorType = ProviderErrorException::class;
            $context->errorMessage = $failure;
        }
    }

    /**
     * Store generated media when the config allows it, then drop the raw
     * body so it is never persisted.
     *
     * @param  array<string, mixed>  $body
     */
    protected function storeMedia(RequestContext $context, ?Handler $handler, array $body): void
    {
        if (! $handler instanceof HasMedia || ! config('spectra.storage.media.enabled')) {
            return;
        }

        // A paused recording stores no row, so the files would be orphans.
        if (RecordingSwitch::isPaused()) {
            $context->rawResponseBody = null;

            return;
        }

        $mediaBody = $body;
        if (filled($context->requestData)) {
            $mediaBody = array_merge($mediaBody, ['_request_data' => $context->requestData]);
        }

        $rawResponseBody = $context->rawResponseBody;
        $media = rescue(fn () => $handler->storeMedia($context->id, $mediaBody, $rawResponseBody), []);
        if (filled($media)) {
            $context->mediaStoragePath = $media;
        }

        $context->rawResponseBody = null;
    }

    /**
     * Fill the response id and reasoning effort when nothing set them, and
     * mark reasoning from the effort or reasoning output blocks (even when
     * reasoning_tokens is 0).
     *
     * @param  array<string, mixed>  $body
     */
    protected function resolveResponseDetails(RequestContext $context, ?Provider $providerInstance, array $body, string $endpoint): void
    {
        if ($context->responseId === null) {
            rescue(function () use ($context, $providerInstance, $body, $endpoint) {
                $context->responseId = $providerInstance?->extractResponseId($body, $endpoint);
            });
        }

        $context->reasoningEffort ??= rescue(fn () => $this->extractReasoningEffort($context->requestData, $body));

        if (! $context->isReasoning) {
            $context->isReasoning = $context->reasoningEffort !== null
                || rescue(fn () => $this->hasReasoningOutput($body), false);
        }
    }

    /**
     * Count the tool calls in a response body by type, for the per-call tool surcharge.
     *
     * Returns an associative array of tool call type => count, empty when
     * no tool calls are found.
     *
     * Supported output item types:
     * - function_call: User-defined function/tool calls (OpenAI Responses API)
     * - tool_calls: User-defined tool calls (OpenAI Completions API, counted individually)
     * - tool_use: User-defined tool calls (Anthropic)
     * - web_search_call: Built-in web search (OpenAI deep research)
     * - file_search_call: Built-in file search (OpenAI assistants)
     * - code_interpreter_call: Built-in code interpreter (OpenAI deep research)
     * - computer_call: Built-in computer use (Anthropic/OpenAI)
     * - mcp_call / mcp_list_tools: MCP tool calls (OpenAI)
     * - custom_tool_call, shell_call, apply_patch_call, tool_search_call: other built-in tools (OpenAI)
     * - x_search_call: X search (xAI Responses API)
     * - image_generation_call: Built-in image generation (OpenAI)
     * - local_shell_call: Local shell tool calls (OpenAI)
     * - mcp_approval_request: MCP approval requests (OpenAI)
     * - functionCall: Google Gemini function calls
     *
     * @param  array<string, mixed>  $body
     * @return array<string, int>
     */
    protected function countToolCalls(array $body): array
    {
        $counts = [];

        // OpenAI Completions API: choices[*].message.tool_calls
        if (is_array($body['choices'] ?? null)) {
            foreach ($body['choices'] as $choice) {
                if (filled($choice['message']['tool_calls'] ?? null) && is_array($choice['message']['tool_calls'])) {
                    foreach ($choice['message']['tool_calls'] as $toolCall) {
                        $type = $toolCall['type'] ?? 'function';
                        $counts[$type] = ($counts[$type] ?? 0) + 1;
                    }
                }
            }
        }

        // OpenAI Responses API: output[*].type — counts all output item types
        if (is_array($body['output'] ?? null)) {
            $containers = [];

            foreach ($body['output'] as $item) {
                $type = is_array($item) ? ($item['type'] ?? null) : null;

                if (! is_string($type) || ! $this->isToolCallType($type)) {
                    continue;
                }

                // Code interpreter is billed per container session, not per call.
                if ($type === 'code_interpreter_call' && isset($item['container_id']) && is_string($item['container_id'])) {
                    $containers[$item['container_id']] = true;

                    continue;
                }

                $counts[$type] = ($counts[$type] ?? 0) + 1;
            }

            if ($containers !== []) {
                $counts['code_interpreter_call'] = ($counts['code_interpreter_call'] ?? 0) + count($containers);
            }
        }

        // Anthropic: content[*].type === 'tool_use'
        if (is_array($body['content'] ?? null)) {
            foreach ($body['content'] as $block) {
                if (($block['type'] ?? null) === 'tool_use') {
                    $counts['tool_use'] = ($counts['tool_use'] ?? 0) + 1;
                }
            }
        }

        // Google: candidates[*].content.parts[*].functionCall
        if (is_array($body['candidates'] ?? null)) {
            foreach ($body['candidates'] as $candidate) {
                foreach ($candidate['content']['parts'] ?? [] as $part) {
                    if (isset($part['functionCall'])) {
                        $counts['function_call'] = ($counts['function_call'] ?? 0) + 1;
                    }
                }
            }
        }

        return $counts;
    }

    /**
     * Check if an output item type represents a tool call.
     */
    protected function isToolCallType(string $type): bool
    {
        return in_array($type, [
            'function_call',
            'custom_tool_call',
            'web_search_call',
            'file_search_call',
            'code_interpreter_call',
            'computer_call',
            'mcp_call',
            'mcp_list_tools',
            'mcp_approval_request',
            'image_generation_call',
            'local_shell_call',
            'shell_call',
            'apply_patch_call',
            'tool_search_call',
            'x_search_call',
        ], true);
    }

    /**
     * Detect tool calls from finish_reason when response body parsing didn't find any.
     */
    protected function detectToolCallsFromFinishReason(?string $finishReason): bool
    {
        return $finishReason !== null && in_array($finishReason, ['tool_calls', 'tool_use'], true);
    }

    /**
     * Determine if a response body holds reasoning or thinking output.
     *
     * OpenAI Responses API includes output items with type "reasoning"
     * even when reasoning_tokens is reported as 0 in usage.
     *
     * @param  array<string, mixed>  $body
     */
    protected function hasReasoningOutput(array $body): bool
    {
        // OpenAI Responses API: output[*].type === 'reasoning'
        if (is_array($body['output'] ?? null)) {
            foreach ($body['output'] as $item) {
                if (($item['type'] ?? null) === 'reasoning') {
                    return true;
                }
            }
        }

        // Anthropic: content[*].type === 'thinking'
        if (is_array($body['content'] ?? null)) {
            foreach ($body['content'] as $block) {
                if (($block['type'] ?? null) === 'thinking') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Extract the reasoning effort or thinking budget a request asked for, in each provider's format.
     *
     * Checks request data first (explicit user configuration), then falls back
     * to the response body (echoed by the Responses API).
     *
     * Supports provider-specific formats:
     * - OpenAI/xAI/Groq Chat Completions: reasoning_effort (low, medium, high)
     * - OpenAI/xAI/Groq/OpenRouter Responses API: reasoning.effort (request or response)
     * - OpenRouter: reasoning.max_tokens (token budget)
     * - Anthropic: thinking.budget_tokens (token budget)
     * - Cohere: thinking.token_budget (token budget)
     * - Google: generationConfig.thinkingConfig.thinkingBudget
     * - Ollama: think (boolean or effort string)
     * - Mistral: prompt_mode ("reasoning")
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    protected function extractReasoningEffort(array $requestData, array $responseData = []): ?string
    {
        // OpenAI/xAI/Groq Chat Completions: { "reasoning_effort": "high" }
        if (isset($requestData['reasoning_effort'])) {
            return (string) $requestData['reasoning_effort'];
        }

        // OpenAI/xAI/Groq/OpenRouter Responses API: { "reasoning": { "effort": "high" } }
        if (isset($requestData['reasoning']['effort'])) {
            return (string) $requestData['reasoning']['effort'];
        }

        // OpenRouter: { "reasoning": { "max_tokens": 2000 } }
        if (isset($requestData['reasoning']['max_tokens'])) {
            return (string) $requestData['reasoning']['max_tokens'];
        }

        // Anthropic: { "thinking": { "type": "enabled", "budget_tokens": 10000 } }
        if (isset($requestData['thinking']['budget_tokens'])) {
            return (string) $requestData['thinking']['budget_tokens'];
        }

        // Cohere: { "thinking": { "type": "enabled", "token_budget": 5000 } }
        if (isset($requestData['thinking']['token_budget'])) {
            return (string) $requestData['thinking']['token_budget'];
        }

        // Google: { "generationConfig": { "thinkingConfig": { "thinkingBudget": 8192 } } }
        if (isset($requestData['generationConfig']['thinkingConfig']['thinkingBudget'])) {
            return (string) $requestData['generationConfig']['thinkingConfig']['thinkingBudget'];
        }

        // Ollama: { "think": true } or { "think": "high" }
        if (isset($requestData['think']) && $requestData['think'] !== false) {
            return is_string($requestData['think']) ? $requestData['think'] : 'enabled';
        }

        // Mistral: { "prompt_mode": "reasoning" }
        if (isset($requestData['prompt_mode']) && $requestData['prompt_mode'] === 'reasoning') {
            return 'enabled';
        }

        // Fallback: Responses API echoes reasoning config in the response body
        // e.g. { "reasoning": { "effort": "medium", "summary": null } }
        if (isset($responseData['reasoning']['effort'])) {
            return (string) $responseData['reasoning']['effort'];
        }

        return null;
    }

    /**
     * Extract token usage from common usage keys when no handler reads the body.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, int|null>
     */
    protected function extractUsageFallback(array $body): array
    {
        $usage = $body['usage'] ?? [];

        if (! is_array($usage)) {
            return ['prompt_tokens' => 0, 'completion_tokens' => 0, 'cached_tokens' => 0];
        }

        return [
            'prompt_tokens' => $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0,
            'completion_tokens' => $usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0,
            'cached_tokens' => $usage['prompt_tokens_details']['cached_tokens']
                ?? $usage['input_tokens_details']['cached_tokens']
                ?? $usage['cached_tokens']
                ?? 0,
            'reasoning_tokens' => $usage['completion_tokens_details']['reasoning_tokens']
                ?? $usage['output_tokens_details']['reasoning_tokens']
                ?? 0,
        ];
    }

    /**
     * Convert a response from an array, an SDK object or a plain object to an array.
     *
     * @return array<string, mixed>
     */
    protected function toArray(mixed $response): array
    {
        if (is_array($response)) {
            return $response;
        }

        if (is_object($response)) {
            if (method_exists($response, 'toArray')) {
                return $response->toArray();
            }

            try {
                return json_decode(json_encode($response, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR) ?? [];
            } catch (JsonException) {
                return [];
            }
        }

        return [];
    }

    /**
     * Resolve the model type from the provider's handler, then from the pricing catalog.
     */
    protected function resolveModelType(
        ?Provider $providerInstance,
        RequestContext $context
    ): ?ModelType {
        $modelType = $providerInstance?->resolveModelType(
            $context->endpoint ?? '',
            $context->requestData
        );

        if ($modelType !== null) {
            return $modelType;
        }

        $modelData = app(PricingLookup::class)->getModelData($context->provider, $context->model);

        if ($modelData) {
            $modelType = ModelType::fromPricingType($modelData['type']);

            if ($modelType === null && $modelData['type'] === 'audio') {
                $modelType = $this->resolveAudioModelType($modelData, $context->model);
            }

            return $modelType;
        }

        return null;
    }

    /**
     * Resolve whether an audio model is text-to-speech or speech-to-text from its capabilities.
     *
     * @param  array{can_generate_audio: bool, can_generate_text: bool}  $modelData
     */
    protected function resolveAudioModelType(array $modelData, string $modelSlug): ModelType
    {
        $canAudio = $modelData['can_generate_audio'];
        $canText = $modelData['can_generate_text'];

        if ($canAudio && ! $canText) {
            return ModelType::Tts;
        }

        if ($canText && ! $canAudio) {
            return ModelType::Stt;
        }

        return ModelType::fromAudioSlug($modelSlug);
    }

    /**
     * Replace base64 images and audio in a response body before it is stored.
     *
     * Inline image/audio data becomes a lightweight placeholder so the
     * response column stays small. Media files are persisted separately via
     * the HasMedia contract when media storage is enabled.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function stripBinaryData(array $body): array
    {
        // OpenAI DALL-E: data[].b64_json
        if (is_array($body['data'] ?? null)) {
            foreach ($body['data'] as $i => $item) {
                if (isset($item['b64_json'])) {
                    $body['data'][$i]['b64_json'] = '[stripped]';
                }
            }
        }

        // OpenAI Responses API: output[].result (image_generation_call base64)
        if (is_array($body['output'] ?? null)) {
            foreach ($body['output'] as $i => $item) {
                if (($item['type'] ?? null) === 'image_generation_call' && isset($item['result']) && is_string($item['result']) && strlen($item['result']) > 1000) {
                    $body['output'][$i]['result'] = '[stripped]';
                }
            }
        }

        // Google Gemini: candidates[].content.parts[].inlineData.data (images/audio)
        if (is_array($body['candidates'] ?? null)) {
            foreach ($body['candidates'] as $candidateIndex => $candidate) {
                foreach ($candidate['content']['parts'] ?? [] as $partIndex => $part) {
                    if (isset($part['inlineData']['data'])) {
                        $body['candidates'][$candidateIndex]['content']['parts'][$partIndex]['inlineData']['data'] = '[stripped]';
                    }
                }
            }
        }

        // Google Imagen: generatedImages[].image.imageBytes
        if (is_array($body['generatedImages'] ?? null)) {
            foreach ($body['generatedImages'] as $i => $image) {
                if (isset($image['image']['imageBytes'])) {
                    $body['generatedImages'][$i]['image']['imageBytes'] = '[stripped]';
                }
            }
        }

        // Mistral TTS: audio_data (base64 audio)
        if (isset($body['audio_data']) && is_string($body['audio_data']) && strlen($body['audio_data']) > 1000) {
            $body['audio_data'] = '[stripped]';
        }

        // OpenRouter image generation: choices[].message.images[].image_url.url (base64 data URLs)
        if (is_array($body['choices'] ?? null)) {
            foreach ($body['choices'] as $choiceIndex => $choice) {
                foreach ($choice['message']['images'] ?? [] as $imageIndex => $image) {
                    $url = $image['image_url']['url'] ?? null;
                    if (is_string($url) && Str::startsWith($url, 'data:') && strlen($url) > 1000) {
                        $body['choices'][$choiceIndex]['message']['images'][$imageIndex]['image_url']['url'] = '[stripped]';
                    }
                }
            }
        }

        return $body;
    }
}
