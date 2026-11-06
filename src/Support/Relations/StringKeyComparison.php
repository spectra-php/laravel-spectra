<?php

declare(strict_types=1);

namespace Spectra\Support\Relations;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Grammars\MySqlGrammar;

/**
 * The SQL that compares a string morph id column with a parent key column
 * cast to a string, rendered by the grammar of the connection it runs on.
 * Without the cast PostgreSQL refuses to compare the two columns and MySQL
 * compares every row as a number instead of using the morph id index.
 */
class StringKeyComparison implements Expression
{
    /**
     * Create a new comparison of the two qualified columns.
     */
    public function __construct(protected string $morphIdColumn, protected string $parentKeyColumn) {}

    /**
     * Get the comparison as SQL for the given grammar.
     */
    public function getValue(Grammar $grammar): string
    {
        // MySQL has no TEXT target for CAST, and on PostgreSQL CHAR is one character wide.
        $type = $grammar instanceof MySqlGrammar ? 'CHAR' : 'TEXT';

        return "{$grammar->wrap($this->morphIdColumn)} = CAST({$grammar->wrap($this->parentKeyColumn)} AS {$type})";
    }
}
