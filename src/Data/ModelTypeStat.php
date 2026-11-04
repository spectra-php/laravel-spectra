<?php

declare(strict_types=1);

namespace Spectra\Data;

use Illuminate\Support\Collection;
use Spectra\Enums\ModelType;
use Spectra\Models\SpectraRequest;

/**
 * The per-model-type aggregate the overview and trackable breakdowns start
 * from: request count, cost over successful requests and their average
 * latency, with the type's display label.
 */
readonly class ModelTypeStat extends DataTransferObject
{
    /**
     * Create a new model type stat instance.
     */
    public function __construct(
        public string $model_type,
        public string $label,
        public int $count,
        public float $cost,
        public float $avg_latency,
    ) {}

    /**
     * Build one stat per model type, with its label, from the per-type aggregate rows.
     *
     * @param  Collection<int, SpectraRequest>  $items
     * @return Collection<int, self>
     */
    public static function fromCollection(Collection $items): Collection
    {
        return $items->map(fn (SpectraRequest $row) => new self(
            model_type: $row->model_type ?? 'unknown',
            label: $row->model_type
                ? (ModelType::tryFrom($row->model_type)?->label() ?? $row->model_type)
                : 'Unknown',
            count: $row->count,
            cost: $row->cost,
            avg_latency: $row->avg_latency,
        ));
    }
}
