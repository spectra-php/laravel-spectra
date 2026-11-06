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

    /** A group's latest failures its models are counted from. */
    private const MODEL_SAMPLE = 1000;

    /**
     * Get the failure totals and the groups.
     */
    public function __invoke(DateRange $dateRange): ErrorsResponse
    {
        $totalsQuery = RequestAggregateQuery::fromRollups($dateRange);
        $isOnRollups = $totalsQuery->isOnRollups();
        $totals = $totalsQuery->countAs('request_count')->failedCount()->first();
        $requestCount = $totals->request_count ?? 0;
        $failedCount = $totals->failed_count ?? 0;
        $isMigrated = $this->hasFingerprintColumn();

        $rows = $isMigrated ? $this->getGroupRows($dateRange) : collect();
        $groups = $isMigrated ? $this->getGroups($rows, $dateRange, $failedCount) : collect();
        // The stats know every failure and the groups the fingerprinted ones, so
        // the rest doesn't need a walk through every call of the period.
        $ungrouped = match (true) {
            ! $isMigrated => $failedCount,
            $isOnRollups => max(0, $failedCount - (int) $rows->sum('failure_count')),
            default => $this->queryFailures($dateRange)->whereNull('error_fingerprint')->count(),
        };

        return new ErrorsResponse(
            request_count: $requestCount,
            failed_count: $failedCount,
            error_rate: $requestCount > 0 ? round($failedCount / $requestCount, 4) : 0.0,
            ungrouped_count: $ungrouped,
            migrated: $isMigrated,
            groups: $groups,
        );
    }

    /**
     * Count the failures of every group, most first.
     *
     * A fingerprint is only saved on a failed call, so the index on it answers
     * this without reading the calls themselves.
     *
     * @return Collection<int, stdClass>
     */
    private function getGroupRows(DateRange $dateRange): Collection
    {
        return RequestAggregateQuery::make($dateRange)
            ->toQuery()
            ->whereNotNull('error_fingerprint')
            ->toBase()
            ->select('error_fingerprint')
            ->selectRaw('COUNT(*) as failure_count, MIN(created_at) as first_seen_at, MAX(created_at) as last_seen_at')
            ->groupBy('error_fingerprint')
            ->orderByDesc('failure_count')
            ->get();
    }

    /**
     * Get the groups with the most failures in the period.
     *
     * @param  Collection<int, stdClass>  $rows
     * @return Collection<int, ErrorGroup>
     */
    private function getGroups(Collection $rows, DateRange $dateRange, int $failedCount): Collection
    {
        $rows = $rows->take(self::LIMIT);

        if ($rows->isEmpty()) {
            return collect();
        }

        $fingerprints = $rows->pluck('error_fingerprint')->map(fn (mixed $fingerprint): string => (string) $fingerprint)->all();
        $latestFailures = $this->getLatestFailures($dateRange, $fingerprints);
        $models = $this->getModels($dateRange, $fingerprints);
        $trends = $this->getTrends($dateRange, $fingerprints);

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
                first_seen_at: $this->formatIso($row->first_seen_at),
                last_seen_at: $this->formatIso($row->last_seen_at),
                trend: $trends[$fingerprint] ?? [],
            );
        })->filter()->values();
    }

    /**
     * Get each group's most recent failure in the period, in one query.
     *
     * The rows at their group's latest time (the last saved when two share
     * it: ids are time-ordered).
     *
     * @param  array<int, string>  $fingerprints
     * @return array<string, SpectraRequest>
     */
    private function getLatestFailures(DateRange $dateRange, array $fingerprints): array
    {
        $latest = [];

        foreach ($fingerprints as $fingerprint) {
            // One index lookup per group.
            $failure = RequestAggregateQuery::make($dateRange)
                ->toQuery()
                ->where('error_fingerprint', $fingerprint)->latest()
                ->orderByDesc('id')
                ->first(['id', 'provider', 'status_code', 'error_type', 'error_message', 'error_fingerprint']);

            if ($failure !== null) {
                $latest[$fingerprint] = $failure;
            }
        }

        return $latest;
    }

    /**
     * Get the models each group hit, most failures first.
     *
     * @param  array<int, string>  $fingerprints
     * @return array<string, array<int, array{model: string, count: int}>>
     */
    private function getModels(DateRange $dateRange, array $fingerprints): array
    {
        $models = [];

        foreach ($fingerprints as $fingerprint) {
            // The group's latest failures: its models rarely change, and reading
            // all of them for every group grows with the failures of the period.
            $models[$fingerprint] = RequestAggregateQuery::make($dateRange)
                ->toQuery()
                ->where('error_fingerprint', $fingerprint)
                ->toBase()
                ->latest('created_at')
                ->limit(self::MODEL_SAMPLE)
                ->pluck('model')
                ->countBy()
                ->sortDesc()
                ->take(self::MODELS)
                ->map(fn (int $count, int|string $model) => ['model' => (string) $model, 'count' => $count])
                ->values()
                ->all();
        }

        return $models;
    }

    /**
     * Get each group's failures per day over the last TREND_DAYS days of the
     * period, oldest first, with days without failures as 0.
     *
     * @param  array<int, string>  $fingerprints
     * @return array<string, array<int, int>>
     */
    private function getTrends(DateRange $dateRange, array $fingerprints): array
    {
        $end = ($dateRange->end ?? Date::now())->copy()->startOfDay();
        $start = $end->copy()->subDays(self::TREND_DAYS - 1);

        if ($dateRange->start !== null && $dateRange->start->greaterThan($start)) {
            $start = $dateRange->start->copy()->startOfDay();
        }

        $days = collect(range(0, (int) $start->diffInDays($end)))->map(fn (int $offset) => $start->copy()->addDays($offset)->toDateString());

        $counts = RequestAggregateQuery::make(new DateRange($start, $end->copy()->endOfDay()))
            ->toQuery()
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
     * Query the period's failed calls (SpectraRequest::failed()).
     *
     * @return Builder<SpectraRequest>
     */
    private function queryFailures(DateRange $dateRange): Builder
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
     * Format a database date as ISO 8601, as the screen reads it.
     */
    private function formatIso(mixed $value): ?string
    {
        return filled($value) ? Date::parse((string) $value)->toIso8601String() : null;
    }
}
