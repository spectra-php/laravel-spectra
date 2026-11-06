<?php

declare(strict_types=1);

namespace Spectra\Actions\Dashboards\Sections;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Schema;
use Spectra\Data\Responses\DashboardErrorsResponse;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Dashboards\DashboardScope;
use Spectra\Support\DateRange;
use stdClass;

/**
 * The trackable's failures for a shared dashboard, grouped by cause. Provider
 * error messages and error types can hold internal details, such as an exception's
 * class, so they are sent only on a dashboard that shows messages. No groups until the error fingerprint migration runs.
 */
class GetDashboardErrors
{
    /** Groups listed. */
    private const LIMIT = 50;

    /** Models listed per group. */
    private const MODELS = 3;

    /** Days in each group's trend. */
    private const TREND_DAYS = 14;

    /**
     * Get the trackable's failure totals and groups in the date range.
     */
    public function __invoke(DashboardScope $scope, DateRange $dateRange, bool $showMessages): DashboardErrorsResponse
    {
        /** @var stdClass $totals */
        $totals = $scope->calls($dateRange)->toBase()
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw(DashboardScope::FAILED_SQL.' as failed')
            ->first();

        $requests = (int) $totals->requests;
        $failed = (int) $totals->failed;

        return new DashboardErrorsResponse(
            request_count: $requests,
            failed_count: $failed,
            error_rate: $requests > 0 ? round($failed / $requests, 4) : 0.0,
            groups: $this->isMigrated() ? $this->groups($scope, $dateRange, $failed, $showMessages) : [],
        );
    }

    /**
     * Get the groups with the most failures in the period.
     *
     * @return array<int, array<string, mixed>>
     */
    private function groups(DashboardScope $scope, DateRange $dateRange, int $failed, bool $showMessages): array
    {
        $failures = fn (?DateRange $range = null): Builder => $scope->calls($range ?? $dateRange)->failed()->whereNotNull('error_fingerprint');

        return $failures()->toBase()
            ->select('error_fingerprint')
            ->selectRaw('COUNT(*) as failures, MIN(created_at) as first_seen_at, MAX(created_at) as last_seen_at')
            ->groupBy('error_fingerprint')
            ->orderByDesc('failures')
            ->limit(self::LIMIT)
            ->get()
            ->map(function (stdClass $row) use ($failures, $dateRange, $failed, $showMessages): array {
                $fingerprint = (string) $row->error_fingerprint;
                $inGroup = fn (?DateRange $range = null): Builder => $failures($range)->where('error_fingerprint', $fingerprint);

                /** @var SpectraRequest $latest */
                $latest = $inGroup()
                    ->select(['id', 'provider', 'status_code', 'error_type', ...($showMessages ? ['error_message'] : []), 'created_at'])
                    ->latest('created_at')
                    ->orderByDesc('id')
                    ->firstOrFail();

                return [
                    'fingerprint' => $fingerprint,
                    'provider' => $latest->provider,
                    'status_code' => $latest->status_code,
                    'error_type' => $showMessages ? $latest->error_type : null,
                    'message' => $showMessages ? $latest->error_message : null,
                    'failure_count' => (int) $row->failures,
                    'share' => $failed > 0 ? round((int) $row->failures / $failed, 4) : 0.0,
                    'models' => $inGroup()->toBase()
                        ->select('model')
                        ->selectRaw('COUNT(*) as failures')
                        ->groupBy('model')
                        ->orderByDesc('failures')
                        ->limit(self::MODELS)
                        ->get()
                        ->map(fn (stdClass $model) => ['model' => (string) $model->model, 'count' => (int) $model->failures])
                        ->all(),
                    'first_seen_at' => Date::parse((string) $row->first_seen_at)->toIso8601String(),
                    'last_seen_at' => Date::parse((string) $row->last_seen_at)->toIso8601String(),
                    'trend' => $this->trend($inGroup, $dateRange),
                ];
            })
            ->all();
    }

    /**
     * Get the group's failures per day over the last days of the period, oldest
     * first, with days without failures as 0.
     *
     * @param  callable(?DateRange): Builder<SpectraRequest>  $inGroup
     * @return array<int, int>
     */
    private function trend(callable $inGroup, DateRange $dateRange): array
    {
        $end = ($dateRange->end ?? Date::now())->copy()->startOfDay();
        $start = $end->copy()->subDays(self::TREND_DAYS - 1);

        if ($dateRange->start !== null && $dateRange->start->greaterThan($start)) {
            $start = $dateRange->start->copy()->startOfDay();
        }

        $counts = $inGroup(new DateRange($start, $end->copy()->endOfDay()))->toBase()
            ->selectRaw('DATE(created_at) as day, COUNT(*) as failures')
            ->groupBy('day')
            ->get()
            ->mapWithKeys(fn (stdClass $row) => [Date::parse((string) $row->day)->toDateString() => (int) $row->failures]);

        return collect(range(0, (int) $start->diffInDays($end)))
            ->map(fn (int $offset) => $counts->get($start->copy()->addDays($offset)->toDateString(), 0))
            ->all();
    }

    /**
     * Determine if the error fingerprint migration has run.
     */
    private function isMigrated(): bool
    {
        return rescue(
            fn () => Schema::connection((new SpectraRequest)->getConnectionName())->hasColumn('spectra_requests', 'error_fingerprint'),
            false,
            report: false,
        );
    }
}
