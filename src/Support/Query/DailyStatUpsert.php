<?php

declare(strict_types=1);

namespace Spectra\Support\Query;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Spectra\Models\SpectraDailyStat;

/**
 * Adds amounts to one daily stat row in a single statement, creating the row
 * when it isn't there.
 *
 * One statement means the row is locked for as little as possible, which is
 * what lets many workers count the same model at once, and two workers can't
 * both create it: `stat_key` is never NULL, unlike the trackable columns.
 * MySQL and MariaDB spell an upsert differently from PostgreSQL and SQLite;
 * another driver has no upsert here and keeps the read-then-write path in
 * SpectraDailyStat::accumulate().
 */
class DailyStatUpsert
{
    /** The columns amounts may be added to. Only these names reach the SQL. */
    public const COLUMNS = [
        'request_count',
        'successful_count',
        'failed_count',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'total_reasoning_tokens',
        'total_cached_tokens',
        'total_cache_write_tokens',
        'total_images',
        'total_videos',
        'total_pages',
        'total_duration_seconds',
        'total_input_characters',
        'total_cost_in_cents',
        'total_latency_ms',
        'latency_samples',
    ];

    /**
     * Build the key of a day, provider, model, model type and trackable. A
     * missing value hashes differently from any string a column can hold.
     */
    public static function key(string $date, string $provider, string $model, ?string $modelType, ?string $trackableType, ?string $trackableId): string
    {
        $missing = "\x00";

        return md5(implode("\x1F", [
            Str::substr($date, 0, 10),
            $provider,
            $model,
            $modelType ?? $missing,
            $trackableType ?? $missing,
            $trackableId ?? $missing,
        ]));
    }

    /**
     * Determine if this driver and these amounts can use the upsert.
     *
     * @param  array<string, int|float>  $increments
     */
    public function supports(array $increments): bool
    {
        return in_array((new SpectraDailyStat)->getConnection()->getDriverName(), ['mysql', 'mariadb', 'pgsql', 'sqlite'], true)
            && array_diff(array_keys($increments), self::COLUMNS) === [];
    }

    /**
     * Add the amounts to the row.
     *
     * @param  array<string, int|float>  $increments
     */
    public function run(string $date, string $provider, string $model, ?string $modelType, ?string $trackableType, ?string $trackableId, array $increments, ?int $latencyMs): void
    {
        $stat = new SpectraDailyStat;
        $connection = $stat->getConnection();
        $now = $stat->fromDateTime(Date::now());

        $isMySql = in_array($connection->getDriverName(), ['mysql', 'mariadb'], true);
        $conflict = $isMySql ? ' on duplicate key update ' : ' on conflict (stat_key) do update set ';
        $table = $connection->getTablePrefix().$stat->getTable();

        $insertColumns = ['id', 'stat_key', 'date', 'provider', 'model', 'model_type', 'trackable_type', 'trackable_id', 'min_latency_ms', 'max_latency_ms', 'created_at', 'updated_at'];
        $insertValues = [
            $stat->newUniqueId(),
            self::key($date, $provider, $model, $modelType, $trackableType, $trackableId),
            $stat->fromDateTime(Date::parse($date)->startOfDay()),
            $provider,
            $model,
            $modelType,
            $trackableType,
            $trackableId,
            $latencyMs,
            $latencyMs,
            $now,
            $now,
        ];

        $updates = [];
        $updateValues = [];

        foreach ($increments as $column => $amount) {
            $insertColumns[] = $column;
            // A new row can't start below zero: amounts only go negative when
            // an existing request moves between the success and failure counts.
            $insertValues[] = max(0, $amount);

            $updates[] = $isMySql ? "{$column} = {$column} + ?" : "{$column} = {$table}.{$column} + ?";
            $updateValues[] = $amount;
        }

        if ($latencyMs !== null) {
            // LEAST and GREATEST treat NULL differently per driver, and SQLite
            // has neither, so a NULL bound first becomes this call's latency.
            [$least, $greatest] = $connection->getDriverName() === 'sqlite' ? ['MIN', 'MAX'] : ['LEAST', 'GREATEST'];
            $qualifier = $isMySql ? '' : "{$table}.";

            $updates[] = "min_latency_ms = {$least}(COALESCE({$qualifier}min_latency_ms, ?), ?)";
            $updates[] = "max_latency_ms = {$greatest}(COALESCE({$qualifier}max_latency_ms, ?), ?)";
            $updateValues[] = $latencyMs;
            $updateValues[] = $latencyMs;
            $updateValues[] = $latencyMs;
            $updateValues[] = $latencyMs;
        }

        $updates[] = 'updated_at = ?';
        $updateValues[] = $now;

        $key = self::key($date, $provider, $model, $modelType, $trackableType, $trackableId);
        $assignments = implode(', ', $updates);

        // On MySQL a duplicate key in an upsert locks the gap beside the row too,
        // and two such locks can deadlock. A row that exists, which is nearly
        // every call, needs only a lock on itself.
        if ($isMySql && $connection->update("update {$table} set {$assignments} where stat_key = ?", [...$updateValues, $key]) > 0) {
            return;
        }

        $columns = implode(', ', $insertColumns);
        $placeholders = implode(', ', array_fill(0, count($insertColumns), '?'));

        $connection->affectingStatement(
            "insert into {$table} ({$columns}) values ({$placeholders}){$conflict}{$assignments}",
            [...$insertValues, ...$updateValues],
        );
    }
}
