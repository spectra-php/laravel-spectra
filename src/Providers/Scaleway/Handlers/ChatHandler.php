<?php

declare(strict_types=1);

namespace Spectra\Providers\Scaleway\Handlers;

use Spectra\Concerns\MatchesParametricEndpoints;
use Spectra\Data\TokenMetrics;
use Spectra\Providers\Concerns\OpenAiCompatibleChatHandler;
use Spectra\Providers\Scaleway\Streaming\ScalewayStreaming;
use Spectra\Support\Tracking\StreamHandler;

/**
 * Scaleway Generative APIs chat: `chat/completions` and `responses`, streamed
 * or not.
 *
 * Both are served at `/v1/...` (default project) and
 * `/{project_id}/v1/...`. Chat usage is OpenAI-shaped; Responses usage uses
 * `input_tokens` / `output_tokens` with `input_tokens_details` and
 * `output_tokens_details`.
 */
class ChatHandler extends OpenAiCompatibleChatHandler
{
    use MatchesParametricEndpoints;

    public function endpoints(): array
    {
        return [
            '/v1/chat/completions',
            '/{project_id}/v1/chat/completions',
            '/v1/responses',
            '/{project_id}/v1/responses',
        ];
    }

    /**
     * Streamed Responses events carry usage under `response.usage`.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    public function usageFrom(array $data): ?array
    {
        $usage = $data['usage'] ?? $data['response']['usage'] ?? null;

        return is_array($usage) ? $usage : null;
    }

    /**
     * @param  array<string, mixed>  $usage
     */
    public function tokensFromUsage(array $usage): TokenMetrics
    {
        if (! isset($usage['input_tokens']) && ! isset($usage['output_tokens'])) {
            return parent::tokensFromUsage($usage);
        }

        // Responses API usage.
        return new TokenMetrics(
            promptTokens: (int) ($usage['input_tokens'] ?? 0),
            completionTokens: (int) ($usage['output_tokens'] ?? 0),
            cachedTokens: (int) ($usage['input_tokens_details']['cached_tokens'] ?? 0),
            reasoningTokens: (int) ($usage['output_tokens_details']['reasoning_tokens'] ?? 0),
        );
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
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

    /** @param  array<string, mixed>  $response */
    public function extractFinishReason(array $response): ?string
    {
        return data_get($response, 'choices.0.finish_reason') ?? $response['status'] ?? null;
    }

    /** @param  array<string, mixed>  $data */
    public function matchesResponse(array $data): bool
    {
        return in_array($data['object'] ?? null, ['chat.completion', 'response'], true);
    }

    public function streamingHandler(): StreamHandler
    {
        return new ScalewayStreaming($this);
    }
}
