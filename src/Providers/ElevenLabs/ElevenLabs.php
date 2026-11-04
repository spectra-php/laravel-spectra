<?php

declare(strict_types=1);

namespace Spectra\Providers\ElevenLabs;

use Spectra\Providers\ElevenLabs\Handlers\MusicHandler;
use Spectra\Providers\ElevenLabs\Handlers\SoundEffectsHandler;
use Spectra\Providers\ElevenLabs\Handlers\SpeechToTextHandler;
use Spectra\Providers\ElevenLabs\Handlers\TextToSpeechHandler;
use Spectra\Providers\ElevenLabs\Handlers\TextToSpeechWithTimestampsHandler;
use Spectra\Providers\Provider;

/**
 * ElevenLabs audio APIs: text to speech, speech to text, sound effects and
 * music.
 */
class ElevenLabs extends Provider
{
    /**
     * Get the slug ElevenLabs requests are recorded under.
     */
    public function getProvider(): string
    {
        return 'elevenlabs';
    }

    /**
     * Get the hosts the ElevenLabs API is served from.
     */
    public function getHosts(): array
    {
        return ['api.elevenlabs.io'];
    }

    /**
     * Get the handlers for ElevenLabs speech, transcription, sound effects and music.
     */
    public function handlers(): array
    {
        return [
            app(TextToSpeechHandler::class),
            app(TextToSpeechWithTimestampsHandler::class),
            app(SpeechToTextHandler::class),
            app(SoundEffectsHandler::class),
            app(MusicHandler::class),
        ];
    }
}
