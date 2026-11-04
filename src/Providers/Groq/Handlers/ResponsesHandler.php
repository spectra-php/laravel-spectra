<?php

declare(strict_types=1);

namespace Spectra\Providers\Groq\Handlers;

use Spectra\Concerns\ExtractsModelField;
use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasFinishReason;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Contracts\StreamsResponse;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\Groq\Streaming\ResponsesStreaming;
use Spectra\Support\Payload;
use Spectra\Support\Tracking\StreamHandler;

/**
 * Groq's OpenAI-compatible Responses API (`POST /openai/v1/responses`).
 *
 * Usage follows OpenAI: `input_tokens` includes cached tokens and
 * `output_tokens` includes reasoning tokens. Built-in tool calls
 * (`browser_search`, `code_interpreter`) appear as output items and are
 * counted by the response processor.
 */
class ResponsesHandler implements ExtractsModelFromResponse, Handler, HasFinishReason, MatchesResponseShape, StreamsResponse
{
    use ExtractsModelField;
    use MatchesEndpoints;

    /**
     * Get the model type this handler records.
     */
    public function modelType(): ModelType
    {
        return ModelType::Text;
    }

    /**
     * Get the Responses API endpoint paths.
     */
    public function endpoints(): array
    {
        return ['/openai/v1/responses'];
    }

    /**
     * Get the token usage of a Responses API body.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $usage = is_array($responseData['usage'] ?? null) ? $responseData['usage'] : [];

        return new Metrics(tokens: $this->tokensFromUsage($usage));
    }

    /**
     * Normalise a Responses API usage object to TokenMetrics.
     *
     * @param  array<string, mixed>  $usage
     */
    public function tokensFromUsage(array $usage): TokenMetrics
    {
        return new TokenMetrics(
            promptTokens: Payload::int($usage['input_tokens'] ?? null),
            completionTokens: Payload::int($usage['output_tokens'] ?? null),
            cachedTokens: Payload::int($usage['input_tokens_details']['cached_tokens'] ?? null),
            reasoningTokens: Payload::int($usage['output_tokens_details']['reasoning_tokens'] ?? null),
        );
    }

    /**
     * Get the output text of the response's message items.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractResponse(array $response): ?string
    {
        $texts = [];

        foreach (is_array($response['output'] ?? null) ? $response['output'] : [] as $item) {
            foreach (is_array($item['content'] ?? null) ? $item['content'] : [] as $content) {
                if (($content['type'] ?? null) === 'output_text' && filled($content['text'] ?? null)) {
                    $texts[] = $content['text'];
                }
            }
        }

        return $texts !== [] ? implode("\n", $texts) : null;
    }

    /**
     * Get the response's status as the finish reason.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractFinishReason(array $response): ?string
    {
        return is_string($response['status'] ?? null) ? $response['status'] : null;
    }

    /**
     * Determine if the body is a Responses API response.
     *
     * @param  array<string, mixed>  $data
     */
    public function matchesResponse(array $data): bool
    {
        return ($data['object'] ?? null) === 'response';
    }

    /**
     * Get the stream handler for a streamed response.
     */
    public function streamingHandler(): StreamHandler
    {
        return new ResponsesStreaming($this);
    }
}
