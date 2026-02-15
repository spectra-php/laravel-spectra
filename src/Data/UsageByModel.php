<?php

namespace Spectra\Data;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;

readonly class UsageByModel extends DataTransferObject
{
    public function __construct(
        public string $model,
        public int $requests,
        public int $tokens,
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
            requests: (int) $item->requests,
            tokens: (int) $item->tokens_sum,
            cost: (float) $item->cost,
        ));
    }
}
