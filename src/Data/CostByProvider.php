<?php

namespace Spectra\Data;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;

readonly class CostByProvider extends DataTransferObject
{
    public function __construct(
        public ?string $provider,
        public float $cost,
    ) {}

    /**
     * @param  \Illuminate\Support\Collection<int, SpectraRequest>  $items
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function fromCollection(Collection $items): Collection
    {
        return $items->map(fn (SpectraRequest $item) => new self(
            provider: $item->provider,
            cost: (float) $item->cost_sum,
        ));
    }
}
