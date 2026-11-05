<?php

declare(strict_types=1);

namespace Spectra\Providers\ElevenLabs\Handlers;

use Illuminate\Support\Str;
use JsonException;
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
    /**
     * Get the ElevenLabs text to speech with timestamps endpoint paths.
     */
    public function endpoints(): array
    {
        return [
            '/v1/text-to-speech/{voice_id}/with-timestamps',
            '/v1/text-to-speech/{voice_id}/stream/with-timestamps',
        ];
    }

    /**
     * Determine if the path is a text to speech with timestamps call for a voice.
     */
    public function matchesEndpoint(string $endpoint): bool
    {
        return Str::isMatch('#^/v1/text-to-speech/[^/]+(/stream)?/with-timestamps$#', $endpoint);
    }

    /**
     * Get the placeholder recorded in place of the timed speech.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractResponse(array $response): ?string
    {
        return '[audio with timestamps]';
    }

    /**
     * Store the base64 audio of every JSON chunk as one mp3 file.
     *
     * @param  array<string, mixed>  $responseData
     * @return array<string>
     */
    public function storeMedia(string $requestId, array $responseData, ?string $rawBody = null): array
    {
        $body = trim((string) $rawBody);
        $whole = $this->decodeJson($body);
        $chunks = is_array($whole) ? [$whole] : Str::of($body)->split('/\r?\n/')
            ->map(fn (string $line) => $this->decodeJson($line))
            ->all();

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

    /**
     * Decode a JSON document, or return null when it is malformed.
     */
    private function decodeJson(string $json): mixed
    {
        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }
}
