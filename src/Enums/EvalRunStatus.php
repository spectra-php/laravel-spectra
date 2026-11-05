<?php

declare(strict_types=1);

namespace Spectra\Enums;

/**
 * Where an evaluation run stands: running, finished, or stopped by an error
 * outside the examples.
 */
enum EvalRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
