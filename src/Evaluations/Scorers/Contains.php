<?php

declare(strict_types=1);

namespace Spectra\Evaluations\Scorers;

use Illuminate\Support\Str;
use Stringable;

/**
 * Scores whether the output contains every one of the given strings, or,
 * with none given, the expected output. Case-insensitive unless strict.
 */
class Contains
{
    /** @var array<int, string> */
    protected array $needles;

    /**
     * Create a new contains scorer instance.
     *
     * @param  string|array<int, string>  $needles
     */
    public function __construct(
        string|array $needles = [],
        protected bool $caseSensitive = false,
    ) {
        $this->needles = (array) $needles;
    }

    /**
     * Determine if the output contains the strings.
     */
    public function __invoke(mixed $output, mixed $expected): ?bool
    {
        $needles = $this->needles !== [] ? $this->needles : (is_string($expected) ? [$expected] : []);

        if ($needles === [] || ! is_string($output) && ! $output instanceof Stringable) {
            return null;
        }

        return Str::containsAll((string) $output, $needles, ignoreCase: ! $this->caseSensitive);
    }
}
