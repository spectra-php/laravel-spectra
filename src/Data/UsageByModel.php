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
     * @param  Collection<int, SpectraRequest>  $items
     * @return Collection<int, self>
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
