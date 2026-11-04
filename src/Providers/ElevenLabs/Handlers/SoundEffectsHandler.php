<?php

declare(strict_types=1);

namespace Spectra\Providers\ElevenLabs\Handlers;

/**
 * ElevenLabs sound effects, `POST /v1/sound-generation`. The request may set
 * `duration_seconds`; without it the model picks the length.
 */
class SoundEffectsHandler extends GeneratedAudioHandler
{
    /**
     * Get the ElevenLabs sound generation endpoint path.
     */
    public function endpoints(): array
    {
        return ['/v1/sound-generation'];
    }

    /**
     * Get the sound effects model ElevenLabs uses when the request names none.
     */
    protected function defaultModel(): string
    {
        return 'eleven_text_to_sound_v2';
    }

    /**
     * Get the requested `duration_seconds`, or null when unset.
     *
     * @param  array<string, mixed>  $requestData
     */
    protected function requestedSeconds(array $requestData): ?float
    {
        $seconds = $requestData['duration_seconds'] ?? null;

        return is_numeric($seconds) && (float) $seconds > 0 ? (float) $seconds : null;
    }
}
