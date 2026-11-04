<?php

declare(strict_types=1);

namespace Spectra\Support\Tracking;

use RuntimeException;

/**
 * An error response (4xx/5xx) from an AI provider, carrying the provider's
 * own error message.
 */
class ProviderErrorException extends RuntimeException
{
    /**
     * Create a new provider error exception with the response's HTTP status.
     */
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message, $status);
    }
}
