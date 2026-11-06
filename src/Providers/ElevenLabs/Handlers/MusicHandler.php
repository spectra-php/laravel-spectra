<?php

declare(strict_types=1);

namespace Spectra\Providers\ElevenLabs\Handlers;

use Spectra\Support\Payload;

/**
 * ElevenLabs music, `POST /v1/music` and `/v1/music/stream`. The request
 * may set `music_length_ms`; without it the model picks the length.
 */
class MusicHandler extends GeneratedAudioHandler
{
    /**
     * Get the ElevenLabs music endpoint paths.
     */
    public function endpoints(): array
    {
        return [
            '/v1/music',
            '/v1/music/stream',
        ];
    }

    /**
     * Get the music model ElevenLabs uses when the request names none.
     */
    protected function defaultModel(): string
    {
        return 'music_v1';
    }

    /**
     * Get the requested `music_length_ms` in seconds, or null when unset.
     *
     * @param  array<string, mixed>  $requestData
     */
    protected function requestedSeconds(array $requestData): ?float
    {
        $milliseconds = Payload::float($requestData['music_length_ms'] ?? null);

        return $milliseconds > 0 ? $milliseconds / 1000 : null;
    }
}
