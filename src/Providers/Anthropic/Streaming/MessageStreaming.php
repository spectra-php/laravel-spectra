<?php

declare(strict_types=1);

namespace Spectra\Providers\Anthropic\Streaming;

use Spectra\Providers\Anthropic\Anthropic;
use Spectra\Providers\Anthropic\Handlers\MessageHandler;
use Spectra\Support\Tracking\StreamHandler;

/**
 * Reads a streamed Messages API response (server-sent events).
 *
 * Usage arrives in two places: `message_start` carries the first counts and
 * every `message_delta` carries CUMULATIVE counts that replace them. With
 * server tools the delta's `input_tokens` grows past the start value, so the
 * latest value of each field is kept, never a sum.
 */
class MessageStreaming extends StreamHandler
{
    /**
     * The raw Anthropic usage seen so far in this stream, before normalising.
     *
     * @var array<string, mixed>
     */
    protected array $rawUsage = [];

    /**
     * Extract text from Anthropic streaming chunk.
     *
     * {"type": "content_block_delta", "delta": {"type": "text_delta", "text": "Hello"}}
     *
     * @param  array<string, mixed>  $data
     */
    public function text(array $data): ?string
    {
        if (($data['type'] ?? null) === 'content_block_delta') {
            return $data['delta']['text'] ?? null;
        }

        return null;
    }

    /**
     * Get the usage so far from a `message_start` or `message_delta` event.
     *
     * message_start: {"message": {"usage": {"input_tokens": 10, "cache_read_input_tokens": 5, ...}}}
     * message_delta: {"usage": {"input_tokens": 12, "output_tokens": 20, "server_tool_use": {...}}}
     *
     * Returns the normalised TokenMetrics keys plus `tool_calls` (server
     * tools), `pricing_tier` (fast mode / service tier) and `cost_multiplier`
     * (US-only inference) when the stream reports them.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $currentUsage
     * @return array<string, mixed>
     */
    public function usage(array $data, array $currentUsage): array
    {
        $type = $data['type'] ?? null;

        if ($type === 'message_start' && is_array($data['message']['usage'] ?? null)) {
            // A new message: forget anything from an earlier stream.
            $this->rawUsage = $data['message']['usage'];
        } elseif ($type === 'message_delta' && is_array($data['usage'] ?? null)) {
            // Delta counts are cumulative: the latest value of each field wins.
            $this->rawUsage = array_replace($this->rawUsage, $data['usage']);
        } else {
            return $currentUsage;
        }

        return $this->normalised($currentUsage);
    }

    /**
     * Extract finish reason from Anthropic streaming chunk.
     *
     * The real stop reason comes from `message_delta` with `delta.stop_reason`.
     * `message_stop` is just an end-of-stream signal, not a stop reason.
     *
     * @param  array<string, mixed>  $data
     */
    public function finishReason(array $data): ?string
    {
        if (($data['type'] ?? null) === 'message_delta' && isset($data['delta']['stop_reason'])) {
            return $data['delta']['stop_reason'];
        }

        return null;
    }

    /**
     * Extract model and response ID from Anthropic streaming chunk.
     *
     * message_start: {"message": {"model": "claude-3-5-sonnet-...", "id": "msg_..."}}
     *
     * @param  array<string, mixed>  $data
     * @return array{model?: string|null, id?: string|null}|null
     */
    public function model(array $data): ?array
    {
        if (($data['type'] ?? null) === 'message_start') {
            return [
                'model' => $data['message']['model'] ?? null,
                'id' => $data['message']['id'] ?? null,
            ];
        }

        return null;
    }

    /**
     * Build the stream usage from the raw usage seen so far, with tool calls, tier and multiplier.
     *
     * @param  array<string, mixed>  $currentUsage
     * @return array<string, mixed>
     */
    protected function normalised(array $currentUsage): array
    {
        $usage = array_replace(
            $currentUsage,
            MessageHandler::tokensFromUsage($this->rawUsage)->toUsageArray(),
        );

        $toolCalls = MessageHandler::serverToolCalls($this->rawUsage);
        $tier = Anthropic::pricingTierFromUsage($this->rawUsage);
        $multiplier = Anthropic::costMultiplierForGeo($this->rawUsage['inference_geo'] ?? null);

        if (filled($toolCalls)) {
            $usage['tool_calls'] = $toolCalls;
        }

        if ($tier !== null) {
            $usage['pricing_tier'] = $tier;
        }

        if ($multiplier !== null) {
            $usage['cost_multiplier'] = $multiplier;
        }

        return $usage;
    }
}
