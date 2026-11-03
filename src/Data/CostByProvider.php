<?php

declare(strict_types=1);

namespace Spectra\Data;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;

/**
 * One provider's cost on the costs screen, in cents over successful requests.
 */
readonly class CostByProvider extends DataTransferObject
{
    public function __construct(
        public ?string $provider,
        public float $cost,
    ) {}

    /**
     * @param  Collection<int, SpectraRequest>  $items
     * @return Collection<int, self>
     */
    public static function fromCollection(Collection $items): Collection
    {
        return $items->map(fn (SpectraRequest $item) => new self(
            provider: $item->provider,
            cost: (float) $item->cost,
        ));
    }
}
