<?php

declare(strict_types=1);

namespace Spectra\Support\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Spectra\Support\Relations\Concerns\ComparesParentKeyAsString;

/**
 * A morphOne relation to a Spectra table, whose morph id column is a string:
 * the parent's key is compared as a string however the parent is keyed.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends MorphOne<TRelatedModel, TDeclaringModel>
 */
class StringKeyedMorphOne extends MorphOne
{
    /** @use ComparesParentKeyAsString<TRelatedModel, TDeclaringModel> */
    use ComparesParentKeyAsString;

    /**
     * Add the constraints for a has(), whereHas() or withCount() query on the relation.
     *
     * @param  Builder<TRelatedModel>  $query
     * @param  Builder<TDeclaringModel>  $parentQuery
     * @return Builder<TRelatedModel>
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*'])
    {
        $query = $this->addStringKeyedExistenceConstraints($query, $columns);

        if ($this->isOneOfMany()) {
            $this->mergeOneOfManyJoinsTo($query);
        }

        return $query;
    }
}
