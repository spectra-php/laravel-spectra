<?php

declare(strict_types=1);

namespace Spectra\Enums;

/**
 * Where a trace stands in a review queue: waiting, reviewed, or passed over.
 */
enum AnnotationStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Skipped = 'skipped';
}
