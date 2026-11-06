<?php

declare(strict_types=1);

namespace Spectra\Providers\Google;

use Illuminate\Support\Str;

/**
 * Reads the parts of a Gemini answer (`candidates[0].content.parts`), where
 * generated images and audio arrive inline as base64 with a mime type.
 *
 * Shared by the image and speech handlers, which tell their responses apart
 * by that mime type. The payload is type-checked, so a part of an unexpected
 * shape is skipped instead of breaking the request's tracking.
 */
trait ReadsGeminiParts
{
    /**
     * Get the parts of the first candidate's content.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<mixed>>
     */
    protected function firstCandidateParts(array $data): array
    {
        $parts = data_get($data, 'candidates.0.content.parts');

        if (! is_array($parts)) {
            return [];
        }

        return collect($parts)->filter(fn (mixed $part) => is_array($part))->values()->all();
    }

    /**
     * Get the mime type of an inline part.
     *
     * @param  array<mixed>  $part
     */
    protected function inlineMimeType(array $part): ?string
    {
        $mimeType = data_get($part, 'inlineData.mimeType');

        return is_string($mimeType) ? $mimeType : null;
    }

    /**
     * Determine if a part is inline data whose mime type starts with the prefix.
     *
     * @param  array<mixed>  $part
     */
    protected function isInlinePart(array $part, string $mimePrefix): bool
    {
        return Str::startsWith($this->inlineMimeType($part) ?? '', $mimePrefix);
    }

    /**
     * Get the decoded bytes of an inline part, or null when it has none.
     *
     * @param  array<mixed>  $part
     */
    protected function inlineBytes(array $part): ?string
    {
        $encoded = data_get($part, 'inlineData.data');

        if (blank($encoded)) {
            return null;
        }

        $bytes = base64_decode($encoded, true);

        if ($bytes === false) {
            return null;
        }

        return filled($bytes) ? $bytes : null;
    }
}
