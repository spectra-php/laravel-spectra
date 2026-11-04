<?php

declare(strict_types=1);

namespace Spectra\Data;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;

/**
 * One day's cost on a cost-per-day chart, in cents over successful requests.
 */
readonly class CostByDate extends DataTransferObject
{
    public function __construct(
        public string $date,
        public float $cost,
    ) {}

    /**
     * @param  Collection<int, SpectraRequest>  $items
     * @return Collection<int, self>
     */
    public static function fromCollection(Collection $items): Collection
    {
        return $items->map(fn (SpectraRequest $item) => new self(
            date: $item->date,
            cost: $item->cost,
        ));
    }
}
