<?php

declare(strict_types=1);

namespace Spectra\Enums;

/**
 * What an alert rule watches over its window: the share of failed calls, the
 * 95th percentile or average latency of successful calls, the spend in cents,
 * or the number of calls.
 */
enum AlertMetric: string
{
    case ErrorRate = 'error_rate';
    case LatencyP95 = 'latency_p95';
    case LatencyAverage = 'latency_avg';
    case Cost = 'cost';
    case Requests = 'requests';

    /**
     * Get the label shown on the dashboard and in notifications.
     */
    public function label(): string
    {
        return match ($this) {
            self::ErrorRate => 'Error rate',
            self::LatencyP95 => 'p95 latency',
            self::LatencyAverage => 'Average latency',
            self::Cost => 'Spend',
            self::Requests => 'Requests',
        };
    }

    /**
     * Determine if the metric needs a minimum number of calls to mean anything
     * (one failed call out of two is not a 50% error rate worth waking up for).
     */
    public function needsVolume(): bool
    {
        return match ($this) {
            self::ErrorRate, self::LatencyP95, self::LatencyAverage => true,
            self::Cost, self::Requests => false,
        };
    }

    /**
     * Format a value of this metric for a person to read.
     */
    public function format(float $value): string
    {
        return match ($this) {
            self::ErrorRate => round($value * 100, 1).'%',
            self::LatencyP95, self::LatencyAverage => number_format($value).' ms',
            self::Cost => '$'.number_format($value / 100, 2),
            self::Requests => number_format($value),
        };
    }
}
