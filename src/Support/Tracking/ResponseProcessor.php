<?php

namespace Spectra\Support\Tracking;

use Spectra\Contracts\ExtractsPricingTierFromResponse;
use Spectra\Contracts\HasExpiration;
use Spectra\Contracts\HasMedia;
use Spectra\Contracts\ReturnsBinaryResponse;
use Spectra\Contracts\SkipsResponse;
use Spectra\Enums\ModelType;
use Spectra\Providers\Provider;
use Spectra\Support\AudioDurationExtractor;
use Spectra\Support\Pricing\PricingLookup;
use Spectra\Support\ProviderRegistry;

class ResponseProcessor
{
    /**
     * Process a mixed (object or array) response, normalizing to array first.
     *
     * Returns [responseBody, usage] on success, or null if the response should be skipped.
     *
     * @return array{0: array<string, mixed>, 1: array<string, int|null>|\Spectra\Data\TokenMetrics|null}|null
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
     * @return array{0: array<string, mixed>, 1: array<string, int|null>|\Spectra\Data\TokenMetrics|null}|null
     */
    public function process(RequestContext $context, array $body): ?array
    {
        if ($context->processed) {
            return [$body, $this->extractUsageFallback($body)];
        }

        $providerInstance = app(ProviderRegistry::class)->provider($context->provider);
        $endpoint = $context->endpoint ?? '';

        $handler = $providerInstance?->resolveHandler($endpoint, $body);

        if ($handler instanceof SkipsResponse && $handler->shouldSkipResponse($body)) {
            return null;
        }

        $responseModel = $providerInstance?->extractModel($body, $endpoint) ?? data_get($body, 'model');
        if ($responseModel && $responseModel !== $context->model) {
            if ($context->model === 'unknown') {
                $context->model = $responseModel;
            } else {
                $context->snapshot = $responseModel;
            }
        }

        // Fallback: extract model from request data when response didn't contain it
        // (e.g. binary responses like TTS, or Whisper where model is only in the request)
        if ($context->model === 'unknown' && ! empty($context->requestData)) {
            $requestModel = $providerInstance?->extractModelFromRequest($context->requestData, $endpoint)
                ?? $context->requestData['model']
                ?? null;

            if ($requestModel !== null) {
                $context->model = $requestModel;
            }
        }

        if ($context->pricingTier === null && $providerInstance instanceof ExtractsPricingTierFromResponse) {
            $tier = $providerInstance->extractPricingTierFromResponse($body);
            if ($tier !== null) {
                $context->withPricingTier($tier);
            }
        }

        $metrics = $providerInstance?->extractMetrics($body, $endpoint, $context->requestData ?? []);
        $usage = $metrics->tokens ?? $this->extractUsageFallback($body);
        $finishReason = $providerInstance?->extractFinishReason($body, $endpoint);

        $context->finishReason = $finishReason ?? $context->finishReason;

        $toolCallCounts = $this->countToolCalls($body);
        if (! empty($toolCallCounts)) {
            $context->hasToolCalls = true;
            $context->toolCallCounts = $toolCallCounts;
        } elseif (! $context->hasToolCalls) {
            $context->hasToolCalls = $this->detectToolCallsFromFinishReason($finishReason);
        }

        if ($metrics?->image !== null) {
            $context->imageCount = $metrics->image->count;
        }
        if ($metrics?->audio !== null) {
            $context->durationSeconds = $metrics->audio->durationSeconds ?? $context->durationSeconds;
            $context->inputCharacters = $metrics->audio->inputCharacters ?? $context->inputCharacters;
        }
        if ($metrics?->video !== null) {
            $context->videoCount = $metrics->video->count;
            $context->durationSeconds = $metrics->video->durationSeconds ?? $context->durationSeconds;
        }

        // Extract audio duration from binary TTS responses using getID3 (if installed).
        // Must happen before media storage clears rawResponseBody.
        if ($handler instanceof ReturnsBinaryResponse
            && $context->durationSeconds === null
            && $context->rawResponseBody !== null
            && $handler->modelType() === ModelType::Tts
        ) {
            $context->durationSeconds = app(AudioDurationExtractor::class)->extract($context->rawResponseBody);
        }

        if ($handler instanceof HasExpiration) {
            $context->expiresAt = $handler->extractExpiresAt($body);
        }

        if ($handler instanceof HasMedia && config('spectra.storage.media.enabled')) {
            $mediaBody = $body;
            if ($handler instanceof ReturnsBinaryResponse && ! empty($context->requestData)) {
                $mediaBody = array_merge($mediaBody, ['_request_data' => $context->requestData]);
            }

            $media = $handler->storeMedia($context->id, $mediaBody, $context->rawResponseBody);
            if (! empty($media)) {
                $context->mediaStoragePath = $media;
            }

            $context->rawResponseBody = null;
        }

        if ($context->modelType === null) {
            $context->modelType = $handler?->modelType()->value
                ?? $this->resolveModelType($providerInstance, $context)?->value;
        }

        if ($context->responseId === null) {
            $context->responseId = $providerInstance?->extractResponseId($body, $endpoint);
        }

        if ($context->reasoningEffort === null) {
            $context->reasoningEffort = $this->extractReasoningEffort($context->requestData ?? [], $body);
        }

        // Detect reasoning mode: effort setting, or reasoning output blocks (even when reasoning_tokens is 0)
        if (! $context->isReasoning) {
            $context->isReasoning = $context->reasoningEffort !== null
                || $this->hasReasoningOutput($body);
        }

        $context->processed = true;

        $body = $this->stripBinaryData($body);

        return [
            array_merge($body, ['finish_reason' => $finishReason]),
            $usage,
        ];
    }

    /**
     * Count tool calls by type from the response body.
     *
     * Returns an associative array of tool call type => count.
     * Returns empty array if no tool calls are found.
     *
     * Supported output item types:
     * - function_call: User-defined function/tool calls (OpenAI Responses API)
     * - tool_calls: User-defined tool calls (OpenAI Completions API, counted individually)
     * - tool_use: User-defined tool calls (Anthropic)
     * - web_search_call: Built-in web search (OpenAI deep research)
     * - file_search_call: Built-in file search (OpenAI assistants)
     * - code_interpreter_call: Built-in code interpreter (OpenAI deep research)
     * - computer_call: Built-in computer use (Anthropic/OpenAI)
     * - mcp_tool_call: MCP tool calls (OpenAI)
     * - image_generation_call: Built-in image generation (OpenAI)
     * - local_shell_call: Local shell tool calls (OpenAI)
     * - mcp_approval_request: MCP approval requests (OpenAI)
     * - functionCall: Google Gemini function calls
     */
    /**
     * @param  array<string, mixed>  $body
     * @return array<string, int>
     */
    protected function countToolCalls(array $body): array
    {
        $counts = [];

        // OpenAI Completions API: choices[*].message.tool_calls
        if (isset($body['choices']) && is_array($body['choices'])) {
            foreach ($body['choices'] as $choice) {
                if (! empty($choice['message']['tool_calls']) && is_array($choice['message']['tool_calls'])) {
                    foreach ($choice['message']['tool_calls'] as $toolCall) {
                        $type = $toolCall['type'] ?? 'function';
                        $counts[$type] = ($counts[$type] ?? 0) + 1;
                    }
                }
            }
        }

        // OpenAI Responses API: output[*].type — counts all output item types
        if (isset($body['output']) && is_array($body['output'])) {
            foreach ($body['output'] as $item) {
                $type = $item['type'] ?? null;
                if ($type !== null && $this->isToolCallType($type)) {
                    $counts[$type] = ($counts[$type] ?? 0) + 1;
                }
            }
        }

        // Anthropic: content[*].type === 'tool_use'
        if (isset($body['content']) && is_array($body['content'])) {
            foreach ($body['content'] as $block) {
                if (($block['type'] ?? null) === 'tool_use') {
                    $counts['tool_use'] = ($counts['tool_use'] ?? 0) + 1;
                }
            }
        }

        // Google: candidates[*].content.parts[*].functionCall
        if (isset($body['candidates']) && is_array($body['candidates'])) {
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
            'web_search_call',
            'file_search_call',
            'code_interpreter_call',
            'computer_call',
            'mcp_tool_call',
            'image_generation_call',
            'local_shell_call',
            'mcp_approval_request',
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
     * Detect reasoning output blocks in the response body.
     *
     * OpenAI Responses API includes output items with type "reasoning"
     * even when reasoning_tokens is reported as 0 in usage.
     */
    /**
     * @param  array<string, mixed>  $body
     */
    protected function hasReasoningOutput(array $body): bool
    {
        // OpenAI Responses API: output[*].type === 'reasoning'
        if (isset($body['output']) && is_array($body['output'])) {
            foreach ($body['output'] as $item) {
                if (($item['type'] ?? null) === 'reasoning') {
                    return true;
                }
            }
        }

        // Anthropic: content[*].type === 'thinking'
        if (isset($body['content']) && is_array($body['content'])) {
            foreach ($body['content'] as $block) {
                if (($block['type'] ?? null) === 'thinking') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Extract reasoning effort from the request data or response body.
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
     */
    /**
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
     * @param  array<string, mixed>  $body
     * @return array<string, int|null>
     */
    protected function extractUsageFallback(array $body): array
    {
        $usage = $body['usage'] ?? [];

        return [
            'prompt_tokens' => $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0,
            'completion_tokens' => $usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0,
            'cached_tokens' => $usage['cached_tokens'] ?? $usage['cache_read_input_tokens'] ?? 0,
        ];
    }

    /**
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

            $json = json_encode($response);

            return $json !== false ? (json_decode($json, true) ?? []) : [];
        }

        return [];
    }

    protected function resolveModelType(
        ?Provider $providerInstance,
        RequestContext $context
    ): ?ModelType {
        $modelType = $providerInstance?->resolveModelType(
            $context->endpoint ?? '',
            $context->requestData ?? []
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
     * @param  array{can_generate_audio: bool, can_generate_text: bool}  $modelData
     */
    protected function resolveAudioModelType(array $modelData, string $modelSlug): ModelType
    {
        $canAudio = (bool) $modelData['can_generate_audio'];
        $canText = (bool) $modelData['can_generate_text'];

        // TTS: generates audio but not text
        if ($canAudio && ! $canText) {
            return ModelType::Tts;
        }

        // STT: generates text but not audio
        if ($canText && ! $canAudio) {
            return ModelType::Stt;
        }

        // Both or neither — fall back to slug-based detection
        return ModelType::fromAudioSlug($modelSlug);
    }

    /**
     * Strip base64-encoded binary data from the response before DB storage.
     *
     * Replaces inline image/audio data with lightweight placeholders so the
     * response column stays small. Media files are persisted separately via
     * the HasMedia contract when media storage is enabled.
     */
    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function stripBinaryData(array $body): array
    {
        // OpenAI DALL-E: data[].b64_json
        if (isset($body['data']) && is_array($body['data'])) {
            foreach ($body['data'] as $i => $item) {
                if (isset($item['b64_json'])) {
                    $body['data'][$i]['b64_json'] = '[stripped]';
                }
            }
        }

        // OpenAI Responses API: output[].result (image_generation_call base64)
        if (isset($body['output']) && is_array($body['output'])) {
            foreach ($body['output'] as $i => $item) {
                if (($item['type'] ?? null) === 'image_generation_call' && isset($item['result']) && is_string($item['result']) && strlen($item['result']) > 1000) {
                    $body['output'][$i]['result'] = '[stripped]';
                }
            }
        }

        // Google Gemini: candidates[].content.parts[].inlineData.data (images/audio)
        if (isset($body['candidates']) && is_array($body['candidates'])) {
            foreach ($body['candidates'] as $ci => $candidate) {
                foreach ($candidate['content']['parts'] ?? [] as $pi => $part) {
                    if (isset($part['inlineData']['data'])) {
                        $body['candidates'][$ci]['content']['parts'][$pi]['inlineData']['data'] = '[stripped]';
                    }
                }
            }
        }

        // Google Imagen: generatedImages[].image.imageBytes
        if (isset($body['generatedImages']) && is_array($body['generatedImages'])) {
            foreach ($body['generatedImages'] as $i => $image) {
                if (isset($image['image']['imageBytes'])) {
                    $body['generatedImages'][$i]['image']['imageBytes'] = '[stripped]';
                }
            }
        }

        return $body;
    }
}
