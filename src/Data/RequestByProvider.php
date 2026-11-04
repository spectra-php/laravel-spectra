<?php

declare(strict_types=1);

namespace Spectra\Data;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;

/**
 * One provider's request count on the overview's provider chart, failures
 * included.
 */
readonly class RequestByProvider extends DataTransferObject
{
    public function __construct(
        public ?string $provider,
        public int $count,
    ) {}

    /**
     * @param  Collection<int, SpectraRequest>  $items
     * @return Collection<int, self>
     */
    public static function fromCollection(Collection $items): Collection
    {
        return $items->map(fn (SpectraRequest $item) => new self(
            provider: $item->provider,
            count: $item->count,
        ));
    }
}
