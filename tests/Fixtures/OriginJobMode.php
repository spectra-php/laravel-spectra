<?php

declare(strict_types=1);

namespace Spectra\Tests\Fixtures;

/**
 * What RecordsOriginJob does after recording its origin.
 */
enum OriginJobMode
{
    case Record;
    case Nested;
    case Fail;
}
