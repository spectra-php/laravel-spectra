<?php

declare(strict_types=1);

namespace Spectra\Providers\Together\Handlers;

use Spectra\Data\TokenMetrics;
use Spectra\Providers\Concerns\OpenAiCompatibleChatHandler;

/**
 * Together AI chat completions (OpenAI-compatible).
 *
 * Where the cached prompt tokens are reported depends on the model:
 * reasoning models nest them OpenAI-style in
 * `usage.prompt_tokens_details.cached_tokens`, and some other models return
 * `cached_tokens` at the top level of `usage` with no details object.
 */
class ChatHandler extends OpenAiCompatibleChatHandler
{
    /**
     * Get the chat completion endpoint paths.
     */
    public function endpoints(): array
    {
        return ['/v1/chat/completions'];
    }

    /**
     * Normalise a Together usage object, reading cached tokens from either place.
     *
     * @param  array<string, mixed>  $usage
     */
    public function tokensFromUsage(array $usage): TokenMetrics
    {
        $details = is_array($usage['prompt_tokens_details'] ?? null) ? $usage['prompt_tokens_details'] : [];

        if (! isset($details['cached_tokens']) && isset($usage['cached_tokens'])) {
            $usage['prompt_tokens_details'] = [...$details, 'cached_tokens' => $usage['cached_tokens']];
        }

        return parent::tokensFromUsage($usage);
    }
}
