<?php

namespace Spectra\Data;

use Illuminate\Support\Collection;
use Spectra\Enums\ModelType;
use Spectra\Models\SpectraRequest;

readonly class ModelTypeStat extends DataTransferObject
{
    public function __construct(
        public string $model_type,
        public string $label,
        public int $count,
        public float $cost,
        public float $avg_latency,
    ) {}

    /**
     * @param  \Illuminate\Support\Collection<int, SpectraRequest>  $items
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function fromCollection(Collection $items): Collection
    {
        return $items->map(fn (SpectraRequest $row) => new self(
            model_type: $row->model_type ?? 'unknown',
            label: $row->model_type
                ? (ModelType::tryFrom($row->model_type)?->label() ?? $row->model_type)
                : 'Unknown',
            count: (int) $row->count,
            cost: (float) $row->cost,
            avg_latency: (float) $row->avg_latency,
        ));
    }
}
