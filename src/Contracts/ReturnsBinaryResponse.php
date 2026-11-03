<?php

declare(strict_types=1);

namespace Spectra\Contracts;

/**
 * Marks handlers whose endpoint returns raw bytes (speech audio) instead of
 * JSON, so the body is kept as-is for media storage and duration detection
 * rather than decoded.
 */
interface ReturnsBinaryResponse
{
    //
}
