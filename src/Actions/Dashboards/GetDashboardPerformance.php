<?php

declare(strict_types=1);

namespace Spectra\Actions\Dashboards;

use Illuminate\Support\Collection;
use Spectra\Support\Dashboards\DashboardScope;
use Spectra\Support\DateRange;
use Spectra\Support\Query\Percentiles;
use stdClass;

/**
 * How the trackable's most used models performed on a shared dashboard: failures
 * over every call, latency percentiles over successful ones.
 */
class GetDashboardPerformance
{
    /** Models listed. */
    private const LIMIT = 10;

    /**
     * Create a new get dashboard performance action instance.
     */
    public function __construct(
        private readonly Percentiles $percentiles,
    ) {}

    /**
     * Get one row per model in the date range, most used first.
     *
     * @return Collection<int, mixed>
     */
    public function __invoke(DashboardScope $scope, DateRange $dateRange): Collection
    {
        return $scope->calls($dateRange)->toBase()
            ->select('provider', 'model')
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw(DashboardScope::FAILED_SQL.' as failed')
            ->groupBy('provider', 'model')
            ->orderByDesc('requests')
            ->limit(self::LIMIT)
            ->get()
            ->map(function (stdClass $row) use ($scope, $dateRange): array {
                $successful = $scope->calls($dateRange)->successful()
                    ->where('provider', $row->provider)
                    ->where('model', $row->model);

                $latency = $this->percentiles->of($successful, 'latency_ms');
                $firstToken = $this->percentiles->of($successful->clone()->where('is_streaming', true), 'time_to_first_token_ms');
                $requests = (int) $row->requests;
                $failed = (int) $row->failed;

                return [
                    'provider' => (string) $row->provider,
                    'model' => (string) $row->model,
                    'request_count' => $requests,
                    'failed_count' => $failed,
                    'error_rate' => $requests > 0 ? round($failed / $requests, 4) : 0.0,
                    'latency_p50' => $latency['p50'],
                    'latency_p95' => $latency['p95'],
                    'latency_p99' => $latency['p99'],
                    'ttft_p50' => $firstToken['p50'],
                ];
            })
            ->values();
    }
}
