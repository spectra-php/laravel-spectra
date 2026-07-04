<?php

declare(strict_types=1);

namespace Spectra\Data;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;

readonly class CostByUser extends DataTransferObject
{
    public function __construct(
        public mixed $user_id,
        public int $requests,
        public float $cost,
        public ?string $user_name = null,
    ) {}

    /**
     * @param  Collection<int, SpectraRequest>  $items
     * @param  array<string, array<string, mixed>>  $metadata  "{trackable_type}:{trackable_id}" => ['name' => ..., 'email' => ...]
     * @return Collection<int, self>
     */
    public static function fromCollection(Collection $items, array $metadata = []): Collection
    {
        return $items->map(function (SpectraRequest $item) use ($metadata) {
            $meta = $metadata[$item->trackable_type.':'.$item->trackable_id] ?? [];

            return new self(
                user_id: $item->trackable_id,
                requests: (int) $item->requests,
                cost: (float) $item->cost,
                user_name: $meta['name'] ?? null,
            );
        });
    }
}
