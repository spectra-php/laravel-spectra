<?php

declare(strict_types=1);

use Spectra\Support\AudioDurationExtractor;

/**
 * AudioDurationExtractor reads the length of audio bytes with getID3 (a dev
 * dependency, so always installed here) and returns null for data it can't
 * measure.
 */
it('extracts the duration of a one-second wav', function () {
    $result = (new AudioDurationExtractor)->extract($this->generateSilentWav());

    expect($result)->not->toBeNull()
        ->and($result)->toBeGreaterThan(0.9)
        ->and($result)->toBeLessThan(1.1);
});

it('returns null for empty audio data', function () {
    $result = (new AudioDurationExtractor)->extract('');

    expect($result)->toBeNull();
});
