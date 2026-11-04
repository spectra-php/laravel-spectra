<?php

declare(strict_types=1);

namespace Spectra\Contracts;

use Spectra\Support\Tracking\StreamHandler;

/**
 * Implemented by handlers whose endpoint can stream. The stream handler reads
 * each chunk's text, usage and finish reason, which arrive spread over the
 * stream instead of in one body.
 */
interface StreamsResponse
{
    /**
     * Get the handler that reads text, usage and finish reason from stream chunks.
     */
    public function streamingHandler(): StreamHandler;
}
