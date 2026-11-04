<?php

declare(strict_types=1);

/**
 * Numbers read from provider JSON: numeric values and numeric strings count,
 * anything else (missing, a word, an array) is the default, so a changed
 * payload shape never bills a made-up amount.
 */

use Spectra\Support\Payload;

it('reads numbers and numeric strings from a payload', function () {
    expect(Payload::int(42))->toBe(42)
        ->and(Payload::int('42'))->toBe(42)
        ->and(Payload::int(12.9))->toBe(12)
        ->and(Payload::float('0.25'))->toBe(0.25)
        ->and(Payload::float(3))->toBe(3.0);
});

it('falls back to the default for a value that is not a number', function () {
    expect(Payload::int(null))->toBe(0)
        ->and(Payload::int('n/a'))->toBe(0)
        ->and(Payload::int([1]))->toBe(0)
        ->and(Payload::int(null, 1))->toBe(1)
        ->and(Payload::float(['1.5'], 2.5))->toBe(2.5);
});
