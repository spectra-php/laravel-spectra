<?php

declare(strict_types=1);

namespace Spectra\Providers\OpenAI\Streaming;

use Spectra\Providers\OpenAI\OpenAiUsage;
use Spectra\Support\Tracking\StreamHandler;

/**
 * Reads streamed Images API responses (`/v1/images/generations` and
 * `/v1/images/edits` with `stream: true`).
 *
 * Partial images arrive as `*.partial_image` events; the final
 * `image_generation.completed` / `image_edit.completed` event carries the
 * usage. The streamed usage has no output breakdown, so every output token
 * is an image token. The model is only in the request.
 */
class ImageStreaming extends StreamHandler
{
    /** Events that close an image stream. */
    public const COMPLETED_EVENTS = ['image_generation.completed', 'image_edit.completed'];

    /**
     * Get the text of an image stream event, which never has any.
     *
     * @param  array<string, mixed>  $data
     */
    public function text(array $data): ?string
    {
        return null;
    }

    /**
     * Merge the usage of a completed image event into the usage so far.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $currentUsage
     * @return array<string, mixed>
     */
    public function usage(array $data, array $currentUsage): array
    {
        if (! in_array($data['type'] ?? null, self::COMPLETED_EVENTS, true) || ! is_array($data['usage'] ?? null)) {
            return $currentUsage;
        }

        return array_merge($currentUsage, OpenAiUsage::fromImages($data['usage'])->toUsageArray());
    }

    /**
     * Get `completed` as the finish reason of a completed image event.
     *
     * @param  array<string, mixed>  $data
     */
    public function finishReason(array $data): ?string
    {
        return in_array($data['type'] ?? null, self::COMPLETED_EVENTS, true) ? 'completed' : null;
    }

    /**
     * Get the model and response id, which image stream events never carry.
     *
     * @param  array<string, mixed>  $data
     * @return array{model?: string|null, id?: string|null}|null
     */
    public function model(array $data): ?array
    {
        return null;
    }
}
