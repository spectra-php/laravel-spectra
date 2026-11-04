<?php

declare(strict_types=1);

namespace Spectra\Providers\OpenAI\Handlers;

use Illuminate\Support\Arr;
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
use Spectra\Providers\OpenAI\OpenAiUsage;
use Spectra\Providers\OpenAI\Streaming\TextStreaming;
use Spectra\Support\Tracking\StreamHandler;

/**
 * Text generation: Chat Completions, legacy Completions, the Responses API
 * and conversation compaction.
 *
 * A Responses request made with `background: true` answers `queued` (no
 * usage yet); that answer is skipped and the finished response is recorded
 * when it is fetched (see BackgroundResponseHandler).
 */
class TextHandler implements ExtractsModelFromResponse, Handler, HasFinishReason, MatchesResponseShape, SkipsResponse, StreamsResponse
{
    use ExtractsModelField;
    use MatchesEndpoints;

    /** Responses API statuses that mean the response is still being generated. */
    public const PENDING_STATUSES = ['queued', 'in_progress'];

    /**
     * Get the model type this handler records.
     */
    public function modelType(): ModelType
    {
        return ModelType::Text;
    }

    /**
     * Get the Chat Completions, Completions, Responses and compaction endpoint paths.
     */
    public function endpoints(): array
    {
        return [
            '/v1/chat/completions',
            '/v1/completions',
            '/v1/responses',
            '/v1/responses/compact',
        ];
    }

    /**
     * Get the token usage of a text response, read in its API's usage shape.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $usage = $responseData['usage'] ?? null;

        if (! is_array($usage)) {
            return new Metrics(tokens: new TokenMetrics);
        }

        return new Metrics(
            tokens: self::isResponsesApi($responseData) || self::isCompaction($responseData)
                ? OpenAiUsage::fromResponses($usage)
                : OpenAiUsage::fromAny($usage),
        );
    }

    /**
     * Determine if the body is a Responses API answer that is still being generated.
     *
     * @param  array<string, mixed>  $responseData
     */
    public function shouldSkipResponse(array $responseData): bool
    {
        return self::isResponsesApi($responseData)
            && in_array($responseData['status'] ?? null, self::PENDING_STATUSES, true);
    }

    /**
     * Get the text of the choices or of the Responses API output items.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractResponse(array $response): ?string
    {
        // Chat completions: choices[*].message.content
        $contents = collect(is_array($response['choices'] ?? null) ? $response['choices'] : [])
            ->map(fn (mixed $choice) => data_get($choice, 'message.content') ?? data_get($choice, 'text'))
            ->filter(fn (mixed $content) => filled($content));

        if ($contents->isNotEmpty()) {
            return $contents->implode("\n");
        }

        // Responses API: output[*].content[*].text
        $texts = collect(is_array($response['output'] ?? null) ? $response['output'] : [])
            ->flatMap(fn (mixed $outputItem) => Arr::wrap(data_get($outputItem, 'content')))
            ->map(fn (mixed $contentItem) => is_array($contentItem) ? $contentItem['text'] ?? null : null)
            ->filter(fn (mixed $text) => filled($text));

        return $texts->isNotEmpty() ? $texts->implode("\n") : null;
    }

    /**
     * Chat Completions: the first choice's finish reason. Responses API: why
     * an incomplete response stopped (`max_output_tokens`, `content_filter`,
     * ...), otherwise its status (`completed`, `failed`, `cancelled`).
     *
     * @param  array<string, mixed>  $response
     */
    public function extractFinishReason(array $response): ?string
    {
        return data_get($response, 'choices.0.finish_reason') ?? self::responseFinishReason($response);
    }

    /**
     * Get a Responses API body's incomplete reason, or otherwise its status.
     *
     * @param  array<string, mixed>  $response
     */
    public static function responseFinishReason(array $response): ?string
    {
        $status = $response['status'] ?? null;
        $reason = $response['incomplete_details']['reason'] ?? null;

        if ($status === 'incomplete' && filled($reason)) {
            return $reason;
        }

        return is_string($status) ? $status : null;
    }

    /**
     * Determine if the body is a Responses, Completions or compaction result.
     *
     * @param  array<string, mixed>  $data
     */
    public function matchesResponse(array $data): bool
    {
        return self::isResponsesApi($data) || self::isCompletionsApi($data) || self::isCompaction($data);
    }

    /**
     * Determine if the body is a Responses API response.
     *
     * @param  array<string, mixed>  $data
     */
    public static function isResponsesApi(array $data): bool
    {
        return ($data['object'] ?? null) === 'response';
    }

    /**
     * Determine if the body is a chat or legacy text completion.
     *
     * @param  array<string, mixed>  $data
     */
    public static function isCompletionsApi(array $data): bool
    {
        return in_array($data['object'] ?? null, ['chat.completion', 'text_completion'], true);
    }

    /**
     * A `/v1/responses/compact` result: billed model usage without a `model` field.
     *
     * @param  array<string, mixed>  $data
     */
    public static function isCompaction(array $data): bool
    {
        return ($data['object'] ?? null) === 'response.compaction';
    }

    /**
     * Determine if the body is a Batch API result line.
     *
     * @param  array<string, mixed>  $data
     */
    public static function isBatch(array $data): bool
    {
        return isset($data['custom_id'], $data['response']['body']);
    }

    /**
     * Unwrap a batch response to its inner body, or return the original data.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function unwrapBatch(array $data): array
    {
        if (self::isBatch($data)) {
            return $data['response']['body'] ?? [];
        }

        return $data;
    }

    /**
     * Get the stream handler for a streamed text response.
     */
    public function streamingHandler(): StreamHandler
    {
        return new TextStreaming;
    }
}
