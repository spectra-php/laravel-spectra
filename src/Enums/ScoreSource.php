<?php

declare(strict_types=1);

namespace Spectra\Enums;

/**
 * Who gave a score: an end user (thumbs up in the app), a model judging the
 * answer, a reviewer in the dashboard, or the app's own code.
 */
enum ScoreSource: string
{
    case User = 'user';
    case Model = 'model';
    case Annotation = 'annotation';
    case Api = 'api';

    /**
     * Get the label shown on the dashboard.
     */
    public function label(): string
    {
        return match ($this) {
            self::User => 'User feedback',
            self::Model => 'Model',
            self::Annotation => 'Reviewer',
            self::Api => 'API',
        };
    }
}
