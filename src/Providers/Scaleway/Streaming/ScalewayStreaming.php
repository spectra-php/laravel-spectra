<?php

declare(strict_types=1);

namespace Spectra\Providers\Scaleway\Streaming;

use Spectra\Providers\Concerns\OpenAiCompatibleStreaming;

/**
 * Scaleway streams: OpenAI-style `chat/completions` chunks, plus the
 * Responses API events (`response.output_text.delta` for text,
 * `response.completed` with the final response and its usage).
 *
 * Usage for both is normalised by the Scaleway ChatHandler.
 */
class ScalewayStreaming extends OpenAiCompatibleStreaming
{
    /**
     * Get the text of a Responses `output_text` delta or a chat completions chunk.
     *
     * @param  array<string, mixed>  $data
     */
    public function text(array $data): ?string
    {
        if (($data['type'] ?? null) === 'response.output_text.delta') {
            return is_string($data['delta'] ?? null) ? $data['delta'] : null;
        }

        return parent::text($data);
    }

    /**
     * Get the status of a terminal Responses event, or a chat chunk's finish reason.
     *
     * @param  array<string, mixed>  $data
     */
    public function finishReason(array $data): ?string
    {
        if (in_array($data['type'] ?? null, ['response.completed', 'response.incomplete', 'response.failed'], true)) {
            $status = $data['response']['status'] ?? null;

            return is_string($status) ? $status : null;
        }

        return parent::finishReason($data);
    }

    /**
     * Get the model and id of a Responses event's response or a chat chunk.
     *
     * @param  array<string, mixed>  $data
     * @return array{model?: string|null, id?: string|null}|null
     */
    public function model(array $data): ?array
    {
        if (is_array($data['response'] ?? null)) {
            return parent::model($data['response']);
        }

        return parent::model($data);
    }
}
