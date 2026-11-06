<?php

declare(strict_types=1);

namespace Spectra\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Spectra\Models\SpectraScore;

/**
 * Dispatched after Spectra::score() saves a score, so an app can react to
 * feedback: alert on a run of thumbs-down answers, or copy scores elsewhere.
 */
class ScoreRecorded
{
    use Dispatchable;

    /**
     * Create a new score recorded event instance.
     */
    public function __construct(
        public readonly SpectraScore $score,
    ) {}
}
