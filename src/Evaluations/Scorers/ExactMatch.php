<?php

declare(strict_types=1);

namespace Spectra\Evaluations\Scorers;

use Illuminate\Support\Str;

/**
 * Scores whether the output equals the expected output: strings compared
 * trimmed and, unless strict, case-insensitively; anything else as JSON.
 * Gives no score for an example without an expected output.
 */
class ExactMatch
{
    /**
     * Create a new exact match scorer instance.
     */
    public function __construct(
        protected bool $caseSensitive = false,
    ) {}

    /**
     * Determine if the output matches the expected output.
     */
    public function __invoke(mixed $output, mixed $expected): ?bool
    {
        if ($expected === null) {
            return null;
        }

        if (is_string($output) && is_string($expected)) {
            return $this->caseSensitive
                ? Str::trim($output) === Str::trim($expected)
                : Str::lower(Str::trim($output)) === Str::lower(Str::trim($expected));
        }

        return json_encode($output) === json_encode($expected);
    }
}
