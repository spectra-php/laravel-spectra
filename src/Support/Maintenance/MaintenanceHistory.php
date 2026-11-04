<?php

declare(strict_types=1);

namespace Spectra\Support\Maintenance;

use Illuminate\Support\Facades\Cache;

/**
 * Remembers the last prune and stats rebuild, and which of them is queued, so
 * the dashboard's maintenance screen can show them. Kept in the default cache
 * store: losing it loses only this history, never data.
 */
class MaintenanceHistory
{
    public const PRUNE = 'prune';

    public const REBUILD_STATS = 'rebuild-stats';

    public const PRICING_SCRAPE = 'pricing-scrape';

    public const PRICING_APPLY = 'pricing-apply';

    /**
     * How long a queued task counts as pending when its job never reports back.
     */
    protected const PENDING_SECONDS = 3600;

    /**
     * Record that a task finished, with what it did.
     *
     * @param  array<string, mixed>  $details
     */
    public static function record(string $task, array $details): void
    {
        rescue(function () use ($task, $details): void {
            Cache::forever(self::key($task), ['at' => now()->toIso8601String(), ...$details]);
            Cache::forget(self::pendingKey($task));
        }, report: false);
    }

    /**
     * Get the last run of a task, or null when it never ran (or the cache was cleared).
     *
     * @return array<string, mixed>|null
     */
    public static function last(string $task): ?array
    {
        $value = rescue(fn () => Cache::get(self::key($task)), null, report: false);

        return is_array($value) ? $value : null;
    }

    /**
     * Mark a task as queued from the dashboard.
     */
    public static function markPending(string $task): void
    {
        rescue(fn () => Cache::put(self::pendingKey($task), now()->toIso8601String(), self::PENDING_SECONDS), report: false);
    }

    /**
     * Clear a task's queued mark without recording a run (its job failed).
     */
    public static function clearPending(string $task): void
    {
        rescue(fn () => Cache::forget(self::pendingKey($task)), report: false);
    }

    /**
     * Determine whether a task was queued and has not finished yet.
     */
    public static function isPending(string $task): bool
    {
        return rescue(fn () => Cache::has(self::pendingKey($task)), false, report: false);
    }

    /**
     * Get the cache key of a task's last run.
     */
    protected static function key(string $task): string
    {
        return "spectra:maintenance:{$task}:last";
    }

    /**
     * Get the cache key of a task's queued mark.
     */
    protected static function pendingKey(string $task): string
    {
        return "spectra:maintenance:{$task}:pending";
    }
}
