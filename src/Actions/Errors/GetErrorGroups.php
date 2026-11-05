<?php

declare(strict_types=1);

namespace Spectra\Actions\Errors;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Schema;
use Spectra\Data\ErrorGroup;
use Spectra\Data\Responses\ErrorsResponse;
use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RequestAggregateQuery;
use stdClass;

/**
 * The Errors screen: the period's failed calls, grouped by fingerprint (see
 * ErrorFingerprint), the groups with the most failures first. Each group has
 * its latest message, the models it hit, and its failures per day over the
 * last TREND_DAYS days of the period.
 */
class GetErrorGroups
{
    /** Groups listed. */
    private const LIMIT = 50;

    /** Days in each group's trend. */
    private const TREND_DAYS = 14;

    /** Models listed per group. */
    private const MODELS = 3;

    /**
     * Get the failure totals and the groups.
     */
    public function __invoke(DateRange $dateRange): ErrorsResponse
    {
        $totals = RequestAggregateQuery::make($dateRange)->countAs('request_count')->failedCount()->first();
        $requestCount = $totals->request_count ?? 0;
        $failedCount = $totals->failed_count ?? 0;
        $migrated = $this->hasFingerprintColumn();

        $groups = $migrated ? $this->groups($dateRange, $failedCount) : collect();
        $ungrouped = $migrated
            ? $this->failures($dateRange)->whereNull('error_fingerprint')->count()
            : $failedCount;

        return new ErrorsResponse(
            request_count: $requestCount,
            failed_count: $failedCount,
            error_rate: $requestCount > 0 ? round($failedCount / $requestCount, 4) : 0.0,
            ungrouped_count: $ungrouped,
            migrated: $migrated,
            groups: $groups,
        );
    }

    /**
     * The groups with the most failures in the period.
     *
     * @return Collection<int, ErrorGroup>
     */
    private function groups(DateRange $dateRange, int $failedCount): Collection
    {
        $rows = $this->failures($dateRange)
            ->whereNotNull('error_fingerprint')
            ->toBase()
            ->select('error_fingerprint')
            ->selectRaw('COUNT(*) as failure_count, MIN(created_at) as first_seen_at, MAX(created_at) as last_seen_at')
            ->groupBy('error_fingerprint')
            ->orderByDesc('failure_count')
            ->limit(self::LIMIT)
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        $fingerprints = $rows->pluck('error_fingerprint')->map(fn (mixed $fingerprint): string => (string) $fingerprint)->all();
        $latestFailures = $this->latestFailures($dateRange, $fingerprints);
        $models = $this->models($dateRange, $fingerprints);
        $trends = $this->trends($dateRange, $fingerprints);

        return $rows->map(function (stdClass $row) use ($failedCount, $latestFailures, $models, $trends): ?ErrorGroup {
            $fingerprint = (string) $row->error_fingerprint;
            $latest = $latestFailures[$fingerprint] ?? null;

            if ($latest === null) {
                return null;
            }

            return new ErrorGroup(
                fingerprint: $fingerprint,
                provider: $latest->provider,
                status_code: $latest->status_code,
                error_type: $latest->error_type,
                message: $latest->error_message,
                latest_request_id: $latest->id,
                failure_count: (int) $row->failure_count,
                share: $failedCount > 0 ? round((int) $row->failure_count / $failedCount, 4) : 0.0,
                models: $models[$fingerprint] ?? [],
                first_seen_at: $this->iso($row->first_seen_at),
                last_seen_at: $this->iso($row->last_seen_at),
                trend: $trends[$fingerprint] ?? [],
            );
        })->filter()->values();
    }

    /**
     * Each group's most recent failure in the period, in one query: the rows
     * at their group's latest time (the last saved when two share it: ids
     * are time-ordered).
     *
     * @param  array<int, string>  $fingerprints
     * @return array<string, SpectraRequest>
     */
    private function latestFailures(DateRange $dateRange, array $fingerprints): array
    {
        $table = (new SpectraRequest)->getTable();

        $latestTimes = $this->failures($dateRange)
            ->whereIn('error_fingerprint', $fingerprints)
            ->toBase()
            ->select('error_fingerprint')
            ->selectRaw('MAX(created_at) as latest_at')
            ->groupBy('error_fingerprint');

        return $this->failures($dateRange)
            ->joinSub($latestTimes, 'latest', fn ($join) => $join
                ->on("{$table}.error_fingerprint", '=', 'latest.error_fingerprint')
                ->on("{$table}.created_at", '=', 'latest.latest_at'))
            ->orderByDesc("{$table}.id")
            ->get(["{$table}.id", "{$table}.provider", "{$table}.status_code", "{$table}.error_type", "{$table}.error_message", "{$table}.error_fingerprint"])
            ->unique('error_fingerprint')
            ->keyBy(fn (SpectraRequest $request): string => (string) $request->error_fingerprint)
            ->all();
    }

    /**
     * The models each group hit, most failures first.
     *
     * @param  array<int, string>  $fingerprints
     * @return array<string, array<int, array{model: string, count: int}>>
     */
    private function models(DateRange $dateRange, array $fingerprints): array
    {
        return $this->failures($dateRange)
            ->whereIn('error_fingerprint', $fingerprints)
            ->toBase()
            ->select('error_fingerprint', 'model')
            ->selectRaw('COUNT(*) as failure_count')
            ->groupBy('error_fingerprint', 'model')
            ->orderByDesc('failure_count')
            ->get()
            ->groupBy('error_fingerprint')
            ->map(fn (Collection $models) => $models->take(self::MODELS)->map(fn (stdClass $model) => [
                'model' => (string) $model->model,
                'count' => (int) $model->failure_count,
            ])->values()->all())
            ->all();
    }

    /**
     * Each group's failures per day over the last TREND_DAYS days of the
     * period, oldest first, with days without failures as 0.
     *
     * @param  array<int, string>  $fingerprints
     * @return array<string, array<int, int>>
     */
    private function trends(DateRange $dateRange, array $fingerprints): array
    {
        $end = ($dateRange->end ?? Date::now())->copy()->startOfDay();
        $start = $end->copy()->subDays(self::TREND_DAYS - 1);

        if ($dateRange->start !== null && $dateRange->start->greaterThan($start)) {
            $start = $dateRange->start->copy()->startOfDay();
        }

        $days = collect(range(0, (int) $start->diffInDays($end)))->map(fn (int $offset) => $start->copy()->addDays($offset)->toDateString());

        $counts = $this->failures(new DateRange($start, $end->copy()->endOfDay()))
            ->whereIn('error_fingerprint', $fingerprints)
            ->toBase()
            ->select('error_fingerprint')
            ->selectRaw('DATE(created_at) as day, COUNT(*) as failure_count')
            ->groupBy('error_fingerprint', 'day')
            ->get()
            ->groupBy('error_fingerprint');

        return collect($fingerprints)->mapWithKeys(function (string $fingerprint) use ($counts, $days) {
            $byDay = collect($counts->get($fingerprint, []))->mapWithKeys(fn (stdClass $row) => [
                Date::parse((string) $row->day)->toDateString() => (int) $row->failure_count,
            ]);

            return [$fingerprint => $days->map(fn (string $day) => $byDay->get($day, 0))->all()];
        })->all();
    }

    /**
     * The period's failed calls: no 2xx status.
     *
     * @return Builder<SpectraRequest>
     */
    private function failures(DateRange $dateRange): Builder
    {
        return RequestAggregateQuery::make($dateRange)->toQuery()->failed();
    }

    /**
     * Determine if the error fingerprint migration has run.
     */
    private function hasFingerprintColumn(): bool
    {
        return rescue(
            fn () => Schema::connection((new SpectraRequest)->getConnectionName())->hasColumn('spectra_requests', 'error_fingerprint'),
            false,
            report: false,
        );
    }

    /**
     * A database date as ISO 8601, as the screen reads it.
     */
    private function iso(mixed $value): ?string
    {
        return filled($value) ? Date::parse((string) $value)->toIso8601String() : null;
    }
}
