<?php

declare(strict_types=1);

namespace Spectra\Support\Relations\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Spectra\Support\Relations\StringKeyComparison;

/**
 * Compare the parent's key with a string morph id column as a string. Spectra
 * stores trackable and budgetable ids as strings so UUID, ULID and integer
 * keys all fit, but Eloquent compares an integer key as a number: MySQL then
 * reads every row of the type instead of using the index, and PostgreSQL
 * refuses to compare the column with an integer at all, both when the
 * relation is loaded (`whereIntegerInRaw` inlines the keys) and in has(),
 * whereHas() and withCount() (`users.id = spectra_requests.trackable_id`).
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @phpstan-require-extends MorphOneOrMany
 */
trait ComparesParentKeyAsString
{
    /**
     * Get the parent's key as the string the morph id column holds.
     */
    public function getParentKey(): ?string
    {
        $key = parent::getParentKey();

        return $key === null ? null : (string) $key;
    }

    /**
     * Constrain the relation to the given parents, with their keys bound as strings.
     *
     * @param  array<int, TDeclaringModel>  $models
     */
    public function addEagerConstraints(array $models): void
    {
        $keys = array_map(fn (mixed $key): string => (string) $key, $this->getKeys($models, $this->localKey));

        // HasOneOrMany inlines an integer-keyed parent's keys as numbers (whereIntegerInRaw), which PostgreSQL refuses against a string column.
        $this->getRelationQuery()
            ->whereIn($this->foreignKey, $keys)
            ->where($this->morphType, $this->morphClass);

        $this->eagerKeysWereEmpty = $keys === [];
    }

    /**
     * Add the constraints of a has(), whereHas() or withCount() query, comparing
     * the morph id column with the parent's key cast to a string.
     *
     * @param  Builder<TRelatedModel>  $query
     * @return Builder<TRelatedModel>
     */
    protected function addStringKeyedExistenceConstraints(Builder $query, mixed $columns): Builder
    {
        return $query->select($columns)
            ->whereRaw(new StringKeyComparison($this->getExistenceCompareKey(), $this->getQualifiedParentKeyName()))
            ->where($query->qualifyColumn($this->getMorphType()), $this->morphClass);
    }
}
