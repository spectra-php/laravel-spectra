<?php

declare(strict_types=1);

use Spectra\Enums\ModelType;

/**
 * ModelType derived from a catalog pricing type, and TTS or STT guessed from
 * an audio model slug.
 */
it('maps a catalog pricing type to its model type', function () {
    expect(ModelType::fromPricingType('image'))->toBe(ModelType::Image);
});

it('leaves the audio pricing type untyped so the slug decides', function () {
    expect(ModelType::fromPricingType('audio'))->toBeNull();
});

it('types an audio slug naming tts as text-to-speech', function () {
    expect(ModelType::fromAudioSlug('tts-1'))->toBe(ModelType::Tts);
});

it('types any other audio slug as speech-to-text', function () {
    expect(ModelType::fromAudioSlug('some-audio-model'))->toBe(ModelType::Stt);
});
