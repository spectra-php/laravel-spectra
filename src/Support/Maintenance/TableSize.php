<?php

declare(strict_types=1);

namespace Spectra\Support\Maintenance;

use Illuminate\Database\Eloquent\Model;

/**
 * How many rows a table holds, for a screen that only needs the size of it.
 *
 * COUNT(*) reads the whole table on MySQL and PostgreSQL. Both keep an estimate
 * from their last ANALYZE, which is close enough once a table is large; a small
 * table, where a few rows would show, is counted.
 */
class TableSize
{
    /** Below this estimate, the table is counted exactly. */
    private const EXACT_BELOW = 100000;

    /**
     * Get the number of rows in the model's table.
     */
    public function rows(Model $model): int
    {
        $estimate = rescue(fn () => $this->estimate($model), report: false);

        return $estimate !== null && $estimate >= self::EXACT_BELOW
            ? $estimate
            : $model->newQuery()->count();
    }

    /**
     * Get the database's estimate of the table's rows, when it keeps one.
     */
    protected function estimate(Model $model): ?int
    {
        $connection = $model->getConnection();
        $table = $connection->getTablePrefix().$model->getTable();

        $row = match ($connection->getDriverName()) {
            'mysql', 'mariadb' => $connection->selectOne('select TABLE_ROWS as estimate from information_schema.TABLES where TABLE_SCHEMA = database() and TABLE_NAME = ?', [$table]),
            'pgsql' => $connection->selectOne('select reltuples as estimate from pg_class where oid = to_regclass(?)', [$table]),
            default => null,
        };

        return $row !== null && is_numeric($row->estimate) ? (int) $row->estimate : null;
    }
}
