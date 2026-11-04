<?php

declare(strict_types=1);

namespace Spectra\Support\Alerts;

use Carbon\CarbonInterface;

/**
 * One alert rule's state: its value now, whether it is firing, since when,
 * and when it last notified.
 */
readonly class AlertStatus
{
    /**
     * Create a new alert status instance.
     */
    public function __construct(
        public AlertRule $rule,
        public ?float $value,
        public bool $firing,
        public ?CarbonInterface $since,
        public ?CarbonInterface $notifiedAt,
    ) {}
}
