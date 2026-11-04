<?php

declare(strict_types=1);

namespace Spectra\Enums;

/**
 * What a score holds: a number (a rating, an accuracy), a yes/no (thumbs up
 * or down), or a category ("positive", "off-topic"). Booleans are stored as
 * 1 and 0 in `value`; categories in `label`.
 */
enum ScoreType: string
{
    case Numeric = 'numeric';
    case Boolean = 'boolean';
    case Categorical = 'categorical';

    /**
     * Get the type of a value passed to Spectra::score().
     */
    public static function of(int|float|bool|string $value): self
    {
        return match (true) {
            is_bool($value) => self::Boolean,
            is_string($value) => self::Categorical,
            default => self::Numeric,
        };
    }
}
