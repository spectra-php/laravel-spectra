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
 * p95 and p99 show it. Each model's percentiles cover its latest calls, up to
 * a limit; `sampled` says when that was fewer than all of them.
 */
class GetModelPerformance
{
    /** How many of a model's latest successful calls its percentiles read. */
    private const SAMPLE = 2000;

    /**
     * Create a new get model performance action instance.
     */
    public function __construct(
        private readonly Percentiles $percentiles,
    ) {}

    /**
     * Get the error rate and latency percentiles of the most used models.
     *
     * @return Collection<int, ModelPerformance>
     */
    public function __invoke(DateRange $dateRange, string $layout, int $limit = 10): Collection
    {
        return RequestAggregateQuery::fromRollups($dateRange)
            ->forLayout($layout)
            ->groupByColumns('provider', 'model')
            ->countAs('request_count')
            ->failedCount()
            ->orderByDesc('request_count')
            ->orderBy('model')
            ->orderBy('provider')
            ->limit($limit)
            ->get()
            ->map(function (SpectraRequest $row) use ($dateRange, $layout): ModelPerformance {
                $successful = RequestAggregateQuery::make($dateRange)->forLayout($layout)->successfulOnly();

                // Only the model's latest calls: every percentile the query
                // used to rank sorted all of them, so this scales with the
                // sample and not the table. Below the limit it is every call.
                $calls = $successful->toQuery()
                    ->toBase()
                    ->where('provider', $row->provider)
                    ->where('model', $row->model)->latest()
                    ->limit(self::SAMPLE)
                    ->get(['latency_ms', 'time_to_first_token_ms', 'is_streaming']);

                $latency = $this->percentiles->ofValues($calls->pluck('latency_ms')->filter(fn (mixed $value) => $value !== null)->values()->all());
                $timeToFirstToken = $this->percentiles->ofValues(
                    $calls->filter(fn (object $call) => (bool) $call->is_streaming && $call->time_to_first_token_ms !== null)->pluck('time_to_first_token_ms')->values()->all(),
                    [50],
                );

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
                    sampled: $calls->count() === self::SAMPLE,
                );
            })
            ->values();
    }
}
