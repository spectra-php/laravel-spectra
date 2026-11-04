<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Spectra\Data\AlertEntry;
use Spectra\Data\DataTransferObject;

/**
 * The overview's Alerts card: each configured rule with its value now, or
 * the reason the rules can't be read.
 */
readonly class AlertsResponse extends DataTransferObject
{
    /**
     * Create a new alerts response instance.
     *
     * @param  array<int, AlertEntry>  $alerts
     */
    public function __construct(
        public array $alerts,
        public ?string $error = null,
    ) {}
}
