<?php

declare(strict_types=1);

namespace Spectra\Data;

use Spectra\Support\Alerts\AlertStatus;

/**
 * One alert rule as the overview's Alerts card shows it: the rule, its value
 * now, and whether it is firing.
 */
readonly class AlertEntry extends DataTransferObject
{
    /**
     * Create a new alert entry instance.
     */
    public function __construct(
        public string $name,
        public string $rule,
        public string $metric,
        public ?string $value,
        public bool $firing,
        public ?string $since,
        public ?string $notified_at,
    ) {}

    /**
     * Build the entry from a rule's status.
     */
    public static function fromStatus(AlertStatus $status): self
    {
        return new self(
            name: $status->rule->name,
            rule: $status->rule->describe(),
            metric: $status->rule->metric->value,
            value: $status->value !== null ? $status->rule->metric->format($status->value) : null,
            firing: $status->firing,
            since: $status->since?->toIso8601String(),
            notified_at: $status->notifiedAt?->toIso8601String(),
        );
    }
}
