<?php

declare(strict_types=1);

namespace Spectra\Providers\DeepInfra\Handlers;

use Spectra\Providers\Concerns\OpenAiCompatibleChatHandler;

/**
 * DeepInfra chat completions (OpenAI-compatible), served under `/v1/openai/`.
 *
 * `usage.estimated_cost` is what DeepInfra charges for the request, in US
 * dollars. It is null on some responses, and the catalog prices apply then.
 */
class ChatHandler extends OpenAiCompatibleChatHandler
{
    /**
     * Get the chat completion endpoint paths.
     */
    public function endpoints(): array
    {
        return ['/v1/openai/chat/completions'];
    }

    /**
     * Get the cost DeepInfra reports for the request, in US cents.
     *
     * @param  array<string, mixed>  $usage
     * @param  array<string, mixed>  $responseData
     */
    public function reportedCostInCents(array $usage, array $responseData): ?float
    {
        $cost = $usage['estimated_cost'] ?? null;

        return is_numeric($cost) ? (float) $cost * 100 : null;
    }
}
