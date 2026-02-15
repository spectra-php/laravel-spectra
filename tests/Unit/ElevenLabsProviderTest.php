<?php

use Spectra\Data\AudioMetrics;
use Spectra\Data\Metrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\ElevenLabs\ElevenLabs;
use Spectra\Providers\ElevenLabs\Handlers\TextToSpeechHandler;

function elevenLabsProvider(): ElevenLabs
{
    return new ElevenLabs;
}

it('returns elevenlabs as provider', function () {
    expect(elevenLabsProvider()->getProvider())->toBe('elevenlabs');
});

it('returns correct display name', function () {
    expect(app(\Spectra\Support\ProviderRegistry::class)->displayName('elevenlabs'))->toBe('ElevenLabs');
});

it('returns correct hosts', function () {
    expect(elevenLabsProvider()->getHosts())->toBe(['api.elevenlabs.io']);
});

it('resolves tts handler for voice endpoint', function () {
    $handler = elevenLabsProvider()->resolveHandler('/v1/text-to-speech/abc123');

    expect($handler)->toBeInstanceOf(TextToSpeechHandler::class);
});

it('resolves tts handler for stream endpoint', function () {
    $handler = elevenLabsProvider()->resolveHandler('/v1/text-to-speech/abc123/stream');

    expect($handler)->toBeInstanceOf(TextToSpeechHandler::class);
});

it('returns null for non-tts endpoint', function () {
    $handler = elevenLabsProvider()->resolveHandler('/v1/something-else');

    expect($handler)->toBeNull();
});

it('tts handler returns tts model type', function () {
    $handler = new TextToSpeechHandler;

    expect($handler->modelType())->toBe(ModelType::Tts);
});

it('returns no tokens for tts response', function () {
    $handler = new TextToSpeechHandler;
    $metrics = $handler->extractMetrics([], []);

    expect($metrics)->toBeInstanceOf(Metrics::class);
    expect($metrics->tokens)->toBeNull();
});

it('extracts input characters from request', function () {
    $handler = new TextToSpeechHandler;
    $metrics = $handler->extractMetrics(
        ['text' => 'Hello, this is a test.', 'model_id' => 'eleven_multilingual_v2'],
        []
    );

    expect($metrics)->toBeInstanceOf(Metrics::class);
    expect($metrics->audio)->toBeInstanceOf(AudioMetrics::class);
    expect($metrics->audio->inputCharacters)->toBe(22);
});

it('returns null input characters when no text', function () {
    $handler = new TextToSpeechHandler;
    $metrics = $handler->extractMetrics([], []);

    expect($metrics)->toBeInstanceOf(Metrics::class);
    expect($metrics->audio->inputCharacters)->toBeNull();
});

it('returns audio placeholder for response', function () {
    $handler = new TextToSpeechHandler;

    expect($handler->extractResponse([]))->toBe('[audio]');
});

it('returns null model from response (model is in request)', function () {
    $handler = new TextToSpeechHandler;

    expect($handler->extractModel([]))->toBeNull();
});
