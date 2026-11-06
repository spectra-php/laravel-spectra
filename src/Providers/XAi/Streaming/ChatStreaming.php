<?php

declare(strict_types=1);

namespace Spectra\Providers\XAi\Streaming;

use Spectra\Providers\Concerns\OpenAiCompatibleStreaming;
use Spectra\Providers\XAi\XAiUsage;

/**
 * xAI Chat Completions stream.
 *
 * xAI sends a running `usage` object on every chunk and the final cost
 * (`cost_in_usd_ticks`) on the last chunk with empty `choices`. Token and cost
 * handling is shared with the non-streamed handler; this adds the
 * server-side tool counts.
 */
class ChatStreaming extends OpenAiCompatibleStreaming
{
    /**
     * Get the chunk's usage, adding xAI's server-side tool counts.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $currentUsage
     * @return array<string, mixed>
     */
    public function usage(array $data, array $currentUsage): array
    {
        $usage = parent::usage($data, $currentUsage);
        $chunkUsage = $this->handler->usageFrom($data);
        $toolCalls = $chunkUsage !== null ? XAiUsage::toolCalls($chunkUsage) : [];

        if (filled($toolCalls)) {
            $usage['tool_calls'] = $toolCalls;
        }

        return $usage;
    }
}
