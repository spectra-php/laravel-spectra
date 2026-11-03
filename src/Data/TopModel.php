<?php

declare(strict_types=1);

namespace Spectra\Data;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;

/**
 * One of the overview's most used models: its request count and the cost of
 * successful requests.
 */
readonly class TopModel extends DataTransferObject
{
    public function __construct(
        public string $model,
        public ?string $provider,
        public int $count,
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
            provider: $item->provider,
            count: (int) $item->count,
            cost: (float) $item->cost,
        ));
    }
}
