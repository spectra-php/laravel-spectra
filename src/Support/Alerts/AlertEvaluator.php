<?php

declare(strict_types=1);

namespace Spectra\Support\Alerts;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Spectra\Enums\AlertMetric;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Query\Percentiles;

/**
 * Measures an alert rule's metric over its window, from the stored calls.
 */
class AlertEvaluator
{
    /**
     * Create a new alert evaluator instance.
     */
    public function __construct(
        private readonly Percentiles $percentiles,
    ) {}

    /**
     * Get the metric's value over the window ending now, or null when the
     * window has too few calls for the metric to mean anything.
     */
    public function measure(AlertRule $rule, CarbonInterface $now): ?float
    {
        $calls = $this->window($rule, $now);
        $count = $calls->clone()->count();

        if ($rule->metric->needsVolume() && $count < $rule->minRequests) {
            return null;
        }

        $successful = $calls->clone()->whereBetween('status_code', [200, 299]);

        return match ($rule->metric) {
            AlertMetric::ErrorRate => ($count - $successful->count()) / $count,
            AlertMetric::LatencyP95 => $this->latencyP95($successful),
            AlertMetric::LatencyAverage => $this->number($successful->avg('latency_ms')),
            AlertMetric::Cost => (float) $successful->sum('total_cost_in_cents'),
            AlertMetric::Requests => (float) $count,
        };
    }

    /**
     * The rule's calls created in the window that ends now.
     *
     * @return Builder<SpectraRequest>
     */
    private function window(AlertRule $rule, CarbonInterface $now): Builder
    {
        return SpectraRequest::query()
            ->where('created_at', '>', $now->copy()->subMinutes($rule->windowMinutes))
            ->where('created_at', '<=', $now)
            ->when($rule->provider !== null, fn (Builder $query) => $query->where('provider', $rule->provider))
            ->when($rule->model !== null, fn (Builder $query) => $query->where(fn (Builder $model) => $model
                ->where('model', $rule->model)
                ->orWhere('snapshot', $rule->model)));
    }

    /**
     * The 95th percentile of the successful calls' latency.
     *
     * @param  Builder<SpectraRequest>  $successful
     */
    private function latencyP95(Builder $successful): ?float
    {
        $p95 = $this->percentiles->of($successful, 'latency_ms', [95])['p95'];

        return $p95 !== null ? (float) $p95 : null;
    }

    /**
     * A database aggregate as a float, or null when there was nothing to aggregate.
     */
    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
