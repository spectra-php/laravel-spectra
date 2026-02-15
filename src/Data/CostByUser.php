<?php

namespace Spectra\Data;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;

readonly class CostByUser extends DataTransferObject
{
    public function __construct(
        public mixed $user_id,
        public int $requests,
        public float $cost,
    ) {}

    /**
     * @param  \Illuminate\Support\Collection<int, SpectraRequest>  $items
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function fromCollection(Collection $items): Collection
    {
        return $items->map(fn (SpectraRequest $item) => new self(
            user_id: $item->user_id,
            requests: (int) $item->requests,
            cost: (float) $item->cost_sum,
        ));
    }
}
