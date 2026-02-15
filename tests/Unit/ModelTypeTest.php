<?php

use Spectra\Enums\ModelType;

// --- label() ---

it('returns correct labels for each model type', function () {
    expect(ModelType::Text->label())->toBe('Text');
    expect(ModelType::Embedding->label())->toBe('Embedding');
    expect(ModelType::Image->label())->toBe('Image');
    expect(ModelType::Video->label())->toBe('Video');
    expect(ModelType::Tts->label())->toBe('Text-to-Speech');
    expect(ModelType::Stt->label())->toBe('Speech-to-Text');
});

// --- fromPricingType() ---

it('maps text pricing type to text', function () {
    expect(ModelType::fromPricingType('text'))->toBe(ModelType::Text);
});

it('maps embedding pricing type to embedding', function () {
    expect(ModelType::fromPricingType('embedding'))->toBe(ModelType::Embedding);
});

it('maps image pricing type to image', function () {
    expect(ModelType::fromPricingType('image'))->toBe(ModelType::Image);
});

it('maps video pricing type to video', function () {
    expect(ModelType::fromPricingType('video'))->toBe(ModelType::Video);
});

it('returns null for audio pricing type', function () {
    expect(ModelType::fromPricingType('audio'))->toBeNull();
});

it('returns null for unknown pricing type', function () {
    expect(ModelType::fromPricingType('unknown'))->toBeNull();
});

it('returns null for null pricing type', function () {
    expect(ModelType::fromPricingType(null))->toBeNull();
});

// --- fromAudioSlug() ---

it('detects tts from slug containing tts', function () {
    expect(ModelType::fromAudioSlug('tts-1'))->toBe(ModelType::Tts);
    expect(ModelType::fromAudioSlug('tts-1-hd'))->toBe(ModelType::Tts);
});

it('detects tts from slug containing speech', function () {
    expect(ModelType::fromAudioSlug('speech-1'))->toBe(ModelType::Tts);
});

it('detects stt from slug containing whisper', function () {
    expect(ModelType::fromAudioSlug('whisper-1'))->toBe(ModelType::Stt);
});

it('detects stt from slug containing transcri', function () {
    expect(ModelType::fromAudioSlug('transcription-model'))->toBe(ModelType::Stt);
});

it('defaults audio slug to stt', function () {
    expect(ModelType::fromAudioSlug('some-audio-model'))->toBe(ModelType::Stt);
});

// --- Backed enum values ---

it('has correct string values', function () {
    expect(ModelType::Text->value)->toBe('text');
    expect(ModelType::Embedding->value)->toBe('embedding');
    expect(ModelType::Image->value)->toBe('image');
    expect(ModelType::Video->value)->toBe('video');
    expect(ModelType::Tts->value)->toBe('tts');
    expect(ModelType::Stt->value)->toBe('stt');
});

it('can be created from string value', function () {
    expect(ModelType::from('text'))->toBe(ModelType::Text);
    expect(ModelType::from('embedding'))->toBe(ModelType::Embedding);
    expect(ModelType::from('image'))->toBe(ModelType::Image);
    expect(ModelType::from('video'))->toBe(ModelType::Video);
    expect(ModelType::from('tts'))->toBe(ModelType::Tts);
    expect(ModelType::from('stt'))->toBe(ModelType::Stt);
});

it('returns null for invalid tryFrom value', function () {
    expect(ModelType::tryFrom('invalid'))->toBeNull();
});
