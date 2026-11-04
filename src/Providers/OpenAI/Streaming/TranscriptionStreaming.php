<?php

declare(strict_types=1);

namespace Spectra\Providers\OpenAI\Streaming;

use Spectra\Providers\OpenAI\OpenAiUsage;
use Spectra\Support\Tracking\StreamHandler;

/**
 * Reads streamed transcriptions (`/v1/audio/transcriptions` with `stream=true`).
 *
 * Text arrives in `transcript.text.delta` events; the final
 * `transcript.text.done` event carries the full text and the usage
 * (audio input tokens and text output tokens). The model is only in the
 * request, so model() reports nothing.
 */
class TranscriptionStreaming extends StreamHandler
{
    /**
     * Get the text delta of a `transcript.text.delta` event.
     *
     * @param  array<string, mixed>  $data
     */
    public function text(array $data): ?string
    {
        if (($data['type'] ?? null) !== 'transcript.text.delta') {
            return null;
        }

        return is_string($data['delta'] ?? null) ? $data['delta'] : null;
    }

    /**
     * Merge the usage of the `transcript.text.done` event into the usage so far.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $currentUsage
     * @return array<string, mixed>
     */
    public function usage(array $data, array $currentUsage): array
    {
        if (($data['type'] ?? null) !== 'transcript.text.done' || ! is_array($data['usage'] ?? null)) {
            return $currentUsage;
        }

        return array_merge($currentUsage, OpenAiUsage::fromTranscription($data['usage'])->toUsageArray());
    }

    /**
     * Get `completed` as the finish reason of the `transcript.text.done` event.
     *
     * @param  array<string, mixed>  $data
     */
    public function finishReason(array $data): ?string
    {
        return ($data['type'] ?? null) === 'transcript.text.done' ? 'completed' : null;
    }

    /**
     * Get the model and response id, which transcription stream events never carry.
     *
     * @param  array<string, mixed>  $data
     * @return array{model?: string|null, id?: string|null}|null
     */
    public function model(array $data): ?array
    {
        return null;
    }
}
