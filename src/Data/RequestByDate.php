<?php

declare(strict_types=1);

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
     * @param  Collection<int, SpectraRequest>  $items
     * @return Collection<int, self>
     */
    public static function fromCollection(Collection $items): Collection
    {
        return $items->map(fn (SpectraRequest $item) => new self(
            date: $item->date,
            count: (int) $item->count,
        ));
    }
}
