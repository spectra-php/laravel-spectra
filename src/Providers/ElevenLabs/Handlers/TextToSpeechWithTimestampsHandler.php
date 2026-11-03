<?php

declare(strict_types=1);

namespace Spectra\Providers\ElevenLabs\Handlers;

use Spectra\Support\MediaPersister;

/**
 * ElevenLabs text to speech with character timings
 * (`/v1/text-to-speech/{voice_id}/with-timestamps` and
 * `/stream/with-timestamps`).
 *
 * The answer is JSON (one object, or one per line when streamed) holding the
 * audio as `audio_base64`. It is still treated as a binary response so the
 * base64 audio never lands in the stored response; billing is per character
 * of the request text, as for plain text to speech.
 */
class TextToSpeechWithTimestampsHandler extends TextToSpeechHandler
{
    public function endpoints(): array
    {
        return [
            '/v1/text-to-speech/{voice_id}/with-timestamps',
            '/v1/text-to-speech/{voice_id}/stream/with-timestamps',
        ];
    }

    public function matchesEndpoint(string $endpoint): bool
    {
        return (bool) preg_match('#^/v1/text-to-speech/[^/]+(/stream)?/with-timestamps$#', $endpoint);
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        return '[audio with timestamps]';
    }

    /**
     * @param  array<string, mixed>  $responseData
     * @return array<string>
     */
    public function storeMedia(string $requestId, array $responseData, ?string $rawBody = null): array
    {
        $body = trim((string) $rawBody);
        $whole = json_decode($body, true);
        $chunks = is_array($whole) ? [$whole] : array_map(
            fn (string $line) => json_decode($line, true),
            preg_split('/\r?\n/', $body) ?: [],
        );

        $audio = '';

        foreach ($chunks as $chunk) {
            if (is_array($chunk) && is_string($chunk['audio_base64'] ?? null)) {
                $audio .= (string) base64_decode($chunk['audio_base64'], true);
            }
        }

        if (blank($audio)) {
            return [];
        }

        return [app(MediaPersister::class)->store($requestId, 0, $audio, 'mp3')];
    }
}
