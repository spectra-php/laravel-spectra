<?php

declare(strict_types=1);

namespace Spectra\Providers\OpenAI\Handlers;

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

    public function modelType(): ModelType
    {
        return ModelType::Text;
    }

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

    /** @param  array<string, mixed>  $responseData */
    public function shouldSkipResponse(array $responseData): bool
    {
        return self::isResponsesApi($responseData)
            && in_array($responseData['status'] ?? null, self::PENDING_STATUSES, true);
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        // Chat completions: choices[*].message.content
        if (is_array($response['choices'] ?? null)) {
            $contents = [];

            foreach ($response['choices'] as $choice) {
                $content = data_get($choice, 'message.content') ?? data_get($choice, 'text');

                if (filled($content)) {
                    $contents[] = $content;
                }
            }

            if (filled($contents)) {
                return implode("\n", $contents);
            }
        }

        // Responses API: output[*].content[*].text
        if (is_array($response['output'] ?? null)) {
            $texts = [];

            foreach ($response['output'] as $outputItem) {
                $contentItems = data_get($outputItem, 'content');

                foreach (is_array($contentItems) ? $contentItems : [] as $contentItem) {
                    if (filled($contentItem['text'] ?? null)) {
                        $texts[] = $contentItem['text'];
                    }
                }
            }

            if (filled($texts)) {
                return implode("\n", $texts);
            }
        }

        return null;
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

    /** @param  array<string, mixed>  $data */
    public function matchesResponse(array $data): bool
    {
        return self::isResponsesApi($data) || self::isCompletionsApi($data) || self::isCompaction($data);
    }

    /** @param  array<string, mixed>  $data */
    public static function isResponsesApi(array $data): bool
    {
        return ($data['object'] ?? null) === 'response';
    }

    /** @param  array<string, mixed>  $data */
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

    /** @param  array<string, mixed>  $data */
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

    public function streamingHandler(): StreamHandler
    {
        return new TextStreaming;
    }
}
