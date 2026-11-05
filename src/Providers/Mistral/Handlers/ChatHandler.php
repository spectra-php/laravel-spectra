<?php

declare(strict_types=1);

namespace Spectra\Providers\Mistral\Handlers;

use Spectra\Data\TokenMetrics;
use Spectra\Providers\Concerns\OpenAiCompatibleChatHandler;
use Spectra\Providers\Mistral\Streaming\ChatStreaming;
use Spectra\Support\Payload;
use Spectra\Support\Tracking\StreamHandler;

/**
 * Mistral chat, FIM and agent completions (OpenAI-compatible).
 *
 * Reasoning models (Magistral, and `reasoning_effort` on newer models) return
 * `message.content` as a list of chunks — `thinking` chunks with the trace and
 * `text` chunks with the answer. The thinking tokens are already counted in
 * `completion_tokens` (prompt + completion = total in the docs example), so no
 * token adjustment is needed; only the answer text is extracted.
 */
class ChatHandler extends OpenAiCompatibleChatHandler
{
    /**
     * Get the Mistral chat, FIM and agent completion endpoint paths.
     */
    public function endpoints(): array
    {
        return ['/v1/chat/completions', '/v1/fim/completions', '/v1/agents/completions'];
    }

    /**
     * Get the token usage of a Mistral completion, including its cache hits.
     *
     * Mistral reports cache hits as `prompt_tokens_details.cached_tokens`;
     * some responses use `prompt_token_details` or `num_cached_tokens`.
     *
     * @param  array<string, mixed>  $usage
     */
    public function tokensFromUsage(array $usage): TokenMetrics
    {
        $tokens = parent::tokensFromUsage($usage);

        if ($tokens->cachedTokens > 0) {
            return $tokens;
        }

        $cached = Payload::int($usage['prompt_token_details']['cached_tokens'] ?? $usage['num_cached_tokens'] ?? null);

        return new TokenMetrics(
            promptTokens: $tokens->promptTokens,
            completionTokens: $tokens->completionTokens,
            cachedTokens: min($cached, $tokens->promptTokens),
            reasoningTokens: $tokens->reasoningTokens,
            cacheWriteTokens: $tokens->cacheWriteTokens,
            audioInputTokens: $tokens->audioInputTokens,
            audioOutputTokens: $tokens->audioOutputTokens,
        );
    }

    /**
     * Get the answer text of a Mistral completion, leaving out thinking chunks.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractResponse(array $response): ?string
    {
        $content = $response['choices'][0]['message']['content'] ?? $response['choices'][0]['text'] ?? null;

        return self::answerText($content);
    }

    /**
     * Get the answer text of a message `content`, which is a plain string or
     * a list of `text` / `thinking` chunks.
     */
    public static function answerText(mixed $content): ?string
    {
        if (is_string($content)) {
            return $content;
        }

        if (! is_array($content)) {
            return null;
        }

        $texts = [];

        foreach ($content as $chunk) {
            if (is_array($chunk) && ($chunk['type'] ?? null) === 'text' && is_string($chunk['text'] ?? null)) {
                $texts[] = $chunk['text'];
            }
        }

        return $texts !== [] ? implode('', $texts) : null;
    }

    /**
     * Create the stream handler for Mistral chat streams.
     */
    public function streamingHandler(): StreamHandler
    {
        return new ChatStreaming($this);
    }
}
