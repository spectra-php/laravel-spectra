<?php

declare(strict_types=1);

use Spectra\Data\AudioMetrics;

/**
 * AudioMetrics leaves unset measurements out of its array, so a request
 * stores only the audio numbers its provider reported.
 */
it('converts audio metrics to array with only non-null values', function () {
    $audio = new AudioMetrics(durationSeconds: 4.5);

    expect($audio->toArray())->toBe(['durationSeconds' => 4.5]);
});
