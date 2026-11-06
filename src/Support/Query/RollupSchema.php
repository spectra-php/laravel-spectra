<?php

declare(strict_types=1);

namespace Spectra\Support\Query;

use Illuminate\Support\Facades\Schema;
use Spectra\Models\SpectraDailyStat;
use Spectra\Support\DateRange;

/**
 * Whether the daily stats can answer a question, so a dashboard query can read
 * them instead of scanning spectra_requests.
 *
 * They can once the migration that added `stat_key` has run and
 * `dashboard.read_from_rollups` is on, for ranges made of whole days: a stat
 * row holds one calendar day, so a range that starts or ends mid-day has to
 * read the requests themselves.
 */
class RollupSchema
{
    /**
     * Whether the table has the key, checked once per process.
     */
    protected ?bool $hasStatKey = null;

    /**
     * Determine if daily stats are saved with their key (an atomic upsert).
     */
    public function hasStatKey(): bool
    {
        return $this->hasStatKey ??= rescue(
            fn () => Schema::connection((new SpectraDailyStat)->getConnectionName())->hasColumn('spectra_daily_stats', 'stat_key'),
            false,
            report: false,
        );
    }

    /**
     * Determine if dashboards should read the daily stats instead of the requests.
     */
    public function isReadable(): bool
    {
        return (bool) config('spectra.dashboard.read_from_rollups', true) && $this->hasStatKey();
    }

    /**
     * Determine if the daily stats can answer for the range.
     */
    public function covers(?DateRange $dateRange): bool
    {
        if (! $this->isReadable()) {
            return false;
        }

        if ($dateRange === null || $dateRange->start === null) {
            return true;
        }

        if ($dateRange->start->format('H:i:s') !== '00:00:00') {
            return false;
        }

        return $dateRange->end === null || $dateRange->end->format('H:i:s') === '23:59:59';
    }
}
