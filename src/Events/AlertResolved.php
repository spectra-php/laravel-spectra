<?php

declare(strict_types=1);

namespace Spectra\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Spectra\Support\Alerts\AlertRule;

/**
 * Dispatched when a firing alert rule is no longer broken. The value is null
 * when the window no longer has enough calls to measure.
 */
class AlertResolved
{
    use Dispatchable;

    /**
     * Create a new alert resolved event instance.
     */
    public function __construct(
        public readonly AlertRule $rule,
        public readonly ?float $value,
    ) {}
}
