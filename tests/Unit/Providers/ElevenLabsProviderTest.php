<?php

declare(strict_types=1);

use Spectra\Providers\ElevenLabs\ElevenLabs;
use Spectra\Providers\ElevenLabs\Handlers\MusicHandler;
use Spectra\Providers\ElevenLabs\Handlers\SoundEffectsHandler;
use Spectra\Providers\ElevenLabs\Handlers\SpeechToTextHandler;
use Spectra\Providers\ElevenLabs\Handlers\TextToSpeechHandler;
use Spectra\Providers\ElevenLabs\Handlers\TextToSpeechWithTimestampsHandler;

/**
 * The ElevenLabs provider resolves a handler for each endpoint and falls
 * back to that endpoint's default model.
 */
it('resolves a handler for each elevenlabs endpoint', function (string $endpoint, string $handler) {
    expect((new ElevenLabs)->resolveHandler($endpoint))->toBeInstanceOf($handler);
})->with([
    ['/v1/text-to-speech/JBFqnCBsd6RMkjVDRZzb', TextToSpeechHandler::class],
    ['/v1/text-to-speech/JBFqnCBsd6RMkjVDRZzb/stream', TextToSpeechHandler::class],
    ['/v1/text-to-speech/JBFqnCBsd6RMkjVDRZzb/with-timestamps', TextToSpeechWithTimestampsHandler::class],
    ['/v1/text-to-speech/JBFqnCBsd6RMkjVDRZzb/stream/with-timestamps', TextToSpeechWithTimestampsHandler::class],
    ['/v1/speech-to-text', SpeechToTextHandler::class],
    ['/v1/sound-generation', SoundEffectsHandler::class],
    ['/v1/music', MusicHandler::class],
]);

it('falls back to the default model of each endpoint', function () {
    $provider = new ElevenLabs;

    expect($provider->extractModelFromRequest(['text' => 'hi'], '/v1/text-to-speech/abc'))->toBe('eleven_multilingual_v2')
        ->and($provider->extractModelFromRequest(['text' => 'hi', 'model_id' => 'eleven_v4'], '/v1/text-to-speech/abc'))->toBe('eleven_v4')
        ->and($provider->extractModelFromRequest(['text' => 'boom'], '/v1/sound-generation'))->toBe('eleven_text_to_sound_v2')
        ->and($provider->extractModelFromRequest(['prompt' => 'jazz'], '/v1/music'))->toBe('music_v1')
        ->and($provider->extractModelFromRequest(['model_id' => 'scribe_v2'], '/v1/speech-to-text'))->toBe('scribe_v2');
});
