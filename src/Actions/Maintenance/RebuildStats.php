<?php

declare(strict_types=1);

namespace Spectra\Actions\Maintenance;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Spectra\Models\SpectraDailyStat;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Maintenance\MaintenanceHistory;
use Spectra\Support\StatsAggregator;

/**
 * Recomputes the daily stats from the stored requests, for a date range or
 * for everything, and records the run for the dashboard.
 */
class RebuildStats
{
    /**
     * Create a new rebuild stats action instance.
     */
    public function __construct(
        private readonly StatsAggregator $aggregator,
    ) {}

    /**
     * Rebuild the daily stats between the dates (both optional) and return how many requests were processed.
     *
     * Without dates every daily stat is deleted first, including the days
     * whose requests were pruned; use storedDays() to keep those.
     */
    public function __invoke(?CarbonInterface $from = null, ?CarbonInterface $to = null): int
    {
        $count = $this->aggregator->rebuild($from, $to);

        MaintenanceHistory::record(MaintenanceHistory::REBUILD_STATS, [
            'requests' => $count,
            'from' => $from?->format('Y-m-d'),
            'to' => $to?->format('Y-m-d'),
        ]);

        return $count;
    }

    /**
     * Rebuild only the days whose requests are all still stored and return how many requests were processed.
     *
     * The stats of pruned days (the long-term history) survive. When older
     * stats exist, the oldest stored day was cut by a prune and keeps its
     * stats too.
     */
    public function storedDays(): int
    {
        $oldest = SpectraRequest::query()->min('created_at');

        if ($oldest === null) {
            MaintenanceHistory::record(MaintenanceHistory::REBUILD_STATS, ['requests' => 0, 'from' => null, 'to' => null]);

            return 0;
        }

        $from = Date::parse($oldest)->startOfDay();

        // whereDate: SQLite stores the date cast as "Y-m-d 00:00:00".
        if (SpectraDailyStat::query()->whereDate('date', '<', $from->format('Y-m-d'))->exists()) {
            $from = $from->addDay();
        }

        return $this($from, now());
    }
}
