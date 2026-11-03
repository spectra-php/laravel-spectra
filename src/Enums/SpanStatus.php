<?php

declare(strict_types=1);

namespace Spectra\Enums;

/**
 * How a span ended: its code finished, or it threw.
 */
enum SpanStatus: string
{
    case Ok = 'ok';
    case Error = 'error';
}
