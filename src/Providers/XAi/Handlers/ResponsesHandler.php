<?php

declare(strict_types=1);

namespace Spectra\Providers\XAi\Handlers;

use Spectra\Concerns\ExtractsModelField;
use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasFinishReason;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Contracts\SkipsResponse;
use Spectra\Contracts\StreamsResponse;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\XAi\Streaming\ResponsesStreaming;
use Spectra\Providers\XAi\XAiUsage;
use Spectra\Support\Payload;
use Spectra\Support\Tracking\StreamHandler;

/**
 * xAI Responses API (`POST /v1/responses`), xAI's main text endpoint.
 *
 * Like xAI chat, `output_tokens` leaves out `output_tokens_details.reasoning_tokens`
 * (the docs example reports 32 input + 9 output + 110 reasoning = 151 total),
 * so reasoning is added to the billed output. The exact charge comes from
 * `usage.cost_in_usd_ticks`. Server-side tool output items (`web_search_call`,
 * `x_search_call`, ...) are counted by the response processor; the usage
 * breakdown adds what output items cannot show, such as X posts fetched.
 */
class ResponsesHandler implements ExtractsModelFromResponse, Handler, HasFinishReason, MatchesResponseShape, SkipsResponse, StreamsResponse
{
    use ExtractsModelField;
    use MatchesEndpoints;

    /**
     * Get the model type xAI Responses requests are recorded as.
     */
    public function modelType(): ModelType
    {
        return ModelType::Text;
    }

    /**
     * Get the xAI Responses API endpoint path.
     */
    public function endpoints(): array
    {
        return ['/v1/responses'];
    }

    /**
     * Build the token usage, server-side tool calls and reported cost of an xAI response.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $usage = is_array($responseData['usage'] ?? null) ? $responseData['usage'] : null;

        if ($usage === null) {
            return new Metrics(tokens: new TokenMetrics);
        }

        return new Metrics(
            tokens: $this->tokensFromUsage($usage),
            toolCalls: XAiUsage::toolCalls($usage),
            reportedCostInCents: XAiUsage::costInCents($usage),
        );
    }

    /**
     * Normalise a Responses usage object. Older responses use the chat-style
     * `prompt_tokens` / `completion_tokens` names, so both are read.
     *
     * @param  array<string, mixed>  $usage
     */
    public function tokensFromUsage(array $usage): TokenMetrics
    {
        $inputDetails = $usage['input_tokens_details'] ?? $usage['prompt_tokens_details'] ?? [];
        $outputDetails = $usage['output_tokens_details'] ?? $usage['completion_tokens_details'] ?? [];
        $inputDetails = is_array($inputDetails) ? $inputDetails : [];
        $outputDetails = is_array($outputDetails) ? $outputDetails : [];

        $reasoning = Payload::int($outputDetails['reasoning_tokens'] ?? null);

        return new TokenMetrics(
            promptTokens: Payload::int($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? null),
            completionTokens: Payload::int($usage['output_tokens'] ?? $usage['completion_tokens'] ?? null) + $reasoning,
            cachedTokens: Payload::int($inputDetails['cached_tokens'] ?? null),
            reasoningTokens: $reasoning,
            imageInputTokens: Payload::int($inputDetails['image_tokens'] ?? null),
        );
    }

    /**
     * Get the `output_text` content of every output item, one per line.
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

        return filled($texts) ? implode("\n", $texts) : null;
    }

    /**
     * Get the status of an xAI response as its finish reason.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractFinishReason(array $response): ?string
    {
        return is_string($response['status'] ?? null) ? $response['status'] : null;
    }

    /**
     * Determine if the body is an xAI Responses API object.
     *
     * @param  array<string, mixed>  $data
     */
    public function matchesResponse(array $data): bool
    {
        return ($data['object'] ?? null) === 'response';
    }

    /**
     * Determine if the response is still running and should not be recorded yet.
     *
     * A response that is still running has no final usage yet.
     *
     * @param  array<string, mixed>  $responseData
     */
    public function shouldSkipResponse(array $responseData): bool
    {
        return in_array($responseData['status'] ?? null, ['queued', 'in_progress'], true);
    }

    /**
     * Create the stream handler for xAI Responses API streams.
     */
    public function streamingHandler(): StreamHandler
    {
        return new ResponsesStreaming($this);
    }
}
