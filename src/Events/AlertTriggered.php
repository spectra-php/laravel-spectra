<?php

declare(strict_types=1);

namespace Spectra\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Spectra\Support\Alerts\AlertRule;

/**
 * Dispatched when `spectra:check-alerts` finds a rule broken: when it starts
 * firing, and again after each cooldown while it keeps firing.
 */
class AlertTriggered
{
    use Dispatchable;

    /**
     * Create a new alert triggered event instance.
     */
    public function __construct(
        public readonly AlertRule $rule,
        public readonly float $value,
    ) {}
}
