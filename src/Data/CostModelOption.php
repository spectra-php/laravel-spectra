<?php

declare(strict_types=1);

namespace Spectra\Data;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;

/**
 * A model the costs screen can be narrowed to: one that was called in the
 * period, with its provider and cost in cents over successful requests.
 */
readonly class CostModelOption extends DataTransferObject
{
    /**
     * Create a new cost model option instance.
     */
    public function __construct(
        public string $model,
        public ?string $provider,
        public float $cost,
    ) {}

    /**
     * Build one option per provider and model from the aggregate rows.
     *
     * @param  Collection<int, SpectraRequest>  $items
     * @return Collection<int, self>
     */
    public static function fromCollection(Collection $items): Collection
    {
        return $items->map(fn (SpectraRequest $item) => new self(
            model: $item->model,
            provider: $item->provider,
            cost: $item->cost,
        ));
    }
}
