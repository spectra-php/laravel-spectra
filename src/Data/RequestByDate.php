<?php

namespace Spectra\Data;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;

readonly class RequestByDate extends DataTransferObject
{
    public function __construct(
        public string $date,
        public int $count,
    ) {}

    /**
     * @param  \Illuminate\Support\Collection<int, SpectraRequest>  $items
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function fromCollection(Collection $items): Collection
    {
        return $items->map(fn (SpectraRequest $item) => new self(
            date: $item->date,
            count: (int) $item->count,
        ));
    }
}
