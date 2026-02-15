<?php

namespace Spectra\Providers\ElevenLabs;

use Spectra\Providers\ElevenLabs\Handlers\TextToSpeechHandler;
use Spectra\Providers\Provider;

class ElevenLabs extends Provider
{
    public function getProvider(): string
    {
        return 'elevenlabs';
    }

    public function getHosts(): array
    {
        return ['api.elevenlabs.io'];
    }

    public function handlers(): array
    {
        return [
            app(TextToSpeechHandler::class),
        ];
    }
}
