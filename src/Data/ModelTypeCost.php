<?php

declare(strict_types=1);

namespace Spectra\Data;

use Illuminate\Support\Collection;

/**
 * One row of the overview's "requests and cost by model type" breakdown:
 * the request count (failures included) and the cost of successful ones.
 */
readonly class ModelTypeCost extends DataTransferObject
{
    /**
     * Create a new model type cost instance.
     */
    public function __construct(
        public string $model_type,
        public string $label,
        public int $count,
        public float $cost,
    ) {}

    /**
     * Build the count and cost rows from the per-model-type stats.
     *
     * @param  Collection<int, ModelTypeStat>  $stats
     * @return Collection<int, self>
     */
    public static function fromCollection(Collection $stats): Collection
    {
        return $stats->map(fn (ModelTypeStat $stat) => new self(
            model_type: $stat->model_type,
            label: $stat->label,
            count: $stat->count,
            cost: $stat->cost,
        ));
    }
}
