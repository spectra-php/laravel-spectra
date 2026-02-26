<?php

use Spectra\Support\AudioDurationExtractor;

it('returns null when getID3 is not installed', function () {
    $extractor = new AudioDurationExtractor;

    // Validates graceful fallback in environments where getID3 is missing.
    if (AudioDurationExtractor::isAvailable()) {
        $this->markTestSkipped('getID3 is installed, cannot test fallback');
    }

    $result = $extractor->extract('fake binary data');

    expect($result)->toBeNull();
});

it('reports availability correctly', function () {
    $available = AudioDurationExtractor::isAvailable();

    expect($available)->toBe(
        class_exists(\JamesHeinrich\GetID3\GetID3::class) || class_exists(\getID3::class)
    );
});

it('extracts duration from valid wav audio when getID3 is available', function () {
    if (! AudioDurationExtractor::isAvailable()) {
        $this->markTestSkipped('getID3 is not installed');
    }

    $extractor = new AudioDurationExtractor;
    $result = $extractor->extract($this->generateSilentWav());

    expect($result)->not->toBeNull();
    expect($result)->toBeGreaterThan(0.9)->toBeLessThan(1.1);
});

it('returns null for empty data when getID3 is available', function () {
    if (! AudioDurationExtractor::isAvailable()) {
        $this->markTestSkipped('getID3 is not installed');
    }

    $extractor = new AudioDurationExtractor;
    $result = $extractor->extract('');

    expect($result)->toBeNull();
});
