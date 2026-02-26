<?php

use Spectra\Data\AudioMetrics;
use Spectra\Data\ImageMetrics;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Data\VideoMetrics;

it('creates token metrics with defaults', function () {
    $tokens = new TokenMetrics;

    expect($tokens->promptTokens)->toBe(0)
        ->and($tokens->completionTokens)->toBe(0)
        ->and($tokens->cachedTokens)->toBe(0)
        ->and($tokens->reasoningTokens)->toBe(0);
});

it('creates token metrics with values', function () {
    $tokens = new TokenMetrics(
        promptTokens: 100,
        completionTokens: 50,
        cachedTokens: 10,
    );

    expect($tokens->promptTokens)->toBe(100)
        ->and($tokens->completionTokens)->toBe(50)
        ->and($tokens->cachedTokens)->toBe(10)
        ->and($tokens->reasoningTokens)->toBe(0);
});

it('creates token metrics with reasoning tokens', function () {
    $tokens = new TokenMetrics(
        promptTokens: 100,
        completionTokens: 50,
        cachedTokens: 10,
        reasoningTokens: 25,
    );

    expect($tokens->promptTokens)->toBe(100)
        ->and($tokens->completionTokens)->toBe(50)
        ->and($tokens->cachedTokens)->toBe(10)
        ->and($tokens->reasoningTokens)->toBe(25);
});

it('converts token metrics to array', function () {
    $tokens = new TokenMetrics(
        promptTokens: 100,
        completionTokens: 50,
        cachedTokens: 10,
    );

    expect($tokens->toArray())->toBe([
        'promptTokens' => 100,
        'completionTokens' => 50,
        'cachedTokens' => 10,
        'reasoningTokens' => 0,
        'cacheCreationTokens' => 0,
    ]);
});

it('converts token metrics with reasoning tokens to array', function () {
    $tokens = new TokenMetrics(
        promptTokens: 100,
        completionTokens: 50,
        cachedTokens: 10,
        reasoningTokens: 25,
    );

    expect($tokens->toArray())->toBe([
        'promptTokens' => 100,
        'completionTokens' => 50,
        'cachedTokens' => 10,
        'reasoningTokens' => 25,
        'cacheCreationTokens' => 0,
    ]);
});

it('token metrics is readonly', function () {
    $tokens = new TokenMetrics(promptTokens: 100);

    expect(fn () => $tokens->promptTokens = 200)->toThrow(Error::class);
});

it('creates image metrics with defaults', function () {
    $image = new ImageMetrics;

    expect($image->count)->toBe(0);
});

it('creates image metrics with values', function () {
    $image = new ImageMetrics(count: 3);

    expect($image->count)->toBe(3);
});

it('converts image metrics to array', function () {
    $image = new ImageMetrics(count: 2);

    expect($image->toArray())->toBe(['count' => 2]);
});

it('image metrics is readonly', function () {
    $image = new ImageMetrics(count: 2);

    expect(fn () => $image->count = 5)->toThrow(Error::class);
});

it('creates audio metrics with defaults', function () {
    $audio = new AudioMetrics;

    expect($audio->durationSeconds)->toBeNull()
        ->and($audio->inputCharacters)->toBeNull();
});

it('creates audio metrics with duration', function () {
    $audio = new AudioMetrics(durationSeconds: 12.5);

    expect($audio->durationSeconds)->toBe(12.5)
        ->and($audio->inputCharacters)->toBeNull();
});

it('creates audio metrics with characters', function () {
    $audio = new AudioMetrics(inputCharacters: 250);

    expect($audio->durationSeconds)->toBeNull()
        ->and($audio->inputCharacters)->toBe(250);
});

it('converts audio metrics to array with only non-null values', function () {
    $audio = new AudioMetrics(durationSeconds: 4.5);

    expect($audio->toArray())->toBe(['durationSeconds' => 4.5]);
});

it('converts audio metrics to empty array when all null', function () {
    $audio = new AudioMetrics;

    expect($audio->toArray())->toBe([]);
});

it('converts audio metrics to full array when all set', function () {
    $audio = new AudioMetrics(durationSeconds: 3.45, inputCharacters: 100);

    expect($audio->toArray())->toBe([
        'durationSeconds' => 3.45,
        'inputCharacters' => 100,
    ]);
});

it('audio metrics is readonly', function () {
    $audio = new AudioMetrics(durationSeconds: 1.0);

    expect(fn () => $audio->durationSeconds = 2.0)->toThrow(Error::class);
});

it('creates video metrics with defaults', function () {
    $video = new VideoMetrics;

    expect($video->count)->toBe(0);
});

it('creates video metrics with values', function () {
    $video = new VideoMetrics(count: 1);

    expect($video->count)->toBe(1);
});

it('converts video metrics to array', function () {
    $video = new VideoMetrics(count: 2);

    expect($video->toArray())->toBe(['count' => 2]);
});

it('video metrics is readonly', function () {
    $video = new VideoMetrics(count: 1);

    expect(fn () => $video->count = 3)->toThrow(Error::class);
});

it('creates metrics container with defaults', function () {
    $metrics = new Metrics;

    expect($metrics->tokens)->toBeNull()
        ->and($metrics->image)->toBeNull()
        ->and($metrics->audio)->toBeNull()
        ->and($metrics->video)->toBeNull();
});

it('creates metrics with tokens only', function () {
    $metrics = new Metrics(
        tokens: new TokenMetrics(promptTokens: 100, completionTokens: 50),
    );

    expect($metrics->tokens)->toBeInstanceOf(TokenMetrics::class)
        ->and($metrics->tokens->promptTokens)->toBe(100)
        ->and($metrics->image)->toBeNull()
        ->and($metrics->audio)->toBeNull()
        ->and($metrics->video)->toBeNull();
});

it('creates metrics with image only', function () {
    $metrics = new Metrics(
        image: new ImageMetrics(count: 3),
    );

    expect($metrics->tokens)->toBeNull()
        ->and($metrics->image)->toBeInstanceOf(ImageMetrics::class)
        ->and($metrics->image->count)->toBe(3);
});

it('creates metrics with audio only', function () {
    $metrics = new Metrics(
        audio: new AudioMetrics(inputCharacters: 250),
    );

    expect($metrics->tokens)->toBeNull()
        ->and($metrics->audio)->toBeInstanceOf(AudioMetrics::class)
        ->and($metrics->audio->inputCharacters)->toBe(250);
});

it('creates metrics with multiple types', function () {
    $metrics = new Metrics(
        tokens: new TokenMetrics(promptTokens: 50),
        audio: new AudioMetrics(durationSeconds: 3.45),
    );

    expect($metrics->tokens->promptTokens)->toBe(50)
        ->and($metrics->audio->durationSeconds)->toBe(3.45)
        ->and($metrics->image)->toBeNull()
        ->and($metrics->video)->toBeNull();
});

it('metrics container is readonly', function () {
    $metrics = new Metrics(tokens: new TokenMetrics(promptTokens: 100));

    expect(fn () => $metrics->tokens = null)->toThrow(Error::class);
});
