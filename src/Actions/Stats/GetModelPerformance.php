<?php

declare(strict_types=1);

namespace Spectra\Actions\Stats;

use Illuminate\Support\Collection;
use Spectra\Data\ModelPerformance;
use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;
use Spectra\Support\Query\Percentiles;
use Spectra\Support\Query\RequestAggregateQuery;

/**
 * The overview's Performance card: for the most used models, their error rate
 * and latency percentiles. Averages hide the slow tail a user waits through;
 * p95 and p99 show it.
 */
class GetModelPerformance
{
    /**
     * Create a new get model performance action instance.
     */
    public function __construct(
        private readonly Percentiles $percentiles,
    ) {}

    /**
     * Get the error rate and latency percentiles of the most used models, of
     * one trackable when given.
     *
     * @return Collection<int, ModelPerformance>
     */
    public function __invoke(DateRange $dateRange, string $layout, int $limit = 10, ?string $trackableType = null, ?string $trackableId = null): Collection
    {
        $scoped = function () use ($dateRange, $layout, $trackableType, $trackableId): RequestAggregateQuery {
            $query = RequestAggregateQuery::make($dateRange)->forLayout($layout);

            return $trackableType !== null && $trackableId !== null ? $query->forTrackable($trackableType, $trackableId) : $query;
        };

        return $scoped()
            ->groupByColumns('provider', 'model')
            ->countAs('request_count')
            ->failedCount()
            ->orderByDesc('request_count')
            ->limit($limit)
            ->get()
            ->map(function (SpectraRequest $row) use ($scoped): ModelPerformance {
                $successful = $scoped()
                    ->successfulOnly()
                    ->toQuery()
                    ->where('provider', $row->provider)
                    ->where('model', $row->model);

                $latency = $this->percentiles->of($successful, 'latency_ms');
                $timeToFirstToken = $this->percentiles->of($successful->clone()->where('is_streaming', true), 'time_to_first_token_ms', [50]);

                return new ModelPerformance(
                    provider: $row->provider,
                    model: $row->model,
                    request_count: $row->request_count,
                    failed_count: $row->failed_count,
                    error_rate: $row->request_count > 0 ? round($row->failed_count / $row->request_count, 4) : 0.0,
                    latency_p50: $latency['p50'],
                    latency_p95: $latency['p95'],
                    latency_p99: $latency['p99'],
                    ttft_p50: $timeToFirstToken['p50'],
                );
            })
            ->values();
    }
}
