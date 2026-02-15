<?php

namespace Spectra\Data;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;

readonly class TopModel extends DataTransferObject
{
    public function __construct(
        public string $model,
        public ?string $provider,
        public int $count,
        public float $cost,
    ) {}

    /**
     * @param  \Illuminate\Support\Collection<int, SpectraRequest>  $items
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function fromCollection(Collection $items): Collection
    {
        return $items->map(fn (SpectraRequest $item) => new self(
            model: $item->model,
            provider: $item->provider,
            count: (int) $item->count,
            cost: (float) $item->cost,
        ));
    }
}
