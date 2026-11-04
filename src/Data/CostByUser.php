<?php

declare(strict_types=1);

namespace Spectra\Data;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;

/**
 * One row of the costs screen's "top spenders": a trackable (any morph type,
 * not only users) with its request count and the cost of its successful
 * requests. `user_id` is the trackable id, a string because apps key models
 * by UUID or ULID too; `trackable_type` tells apart equal ids of different
 * types.
 */
readonly class CostByUser extends DataTransferObject
{
    public function __construct(
        public string $user_id,
        public int $requests,
        public float $cost,
        public ?string $user_name = null,
        public ?string $trackable_type = null,
    ) {}

    /**
     * @param  Collection<int, SpectraRequest>  $items
     * @param  array<string, array<string, mixed>>  $metadata  "{trackable_type}:{trackable_id}" => ['name' => ..., 'email' => ...]
     * @return Collection<int, self>
     */
    public static function fromCollection(Collection $items, array $metadata = []): Collection
    {
        return $items->map(function (SpectraRequest $item) use ($metadata) {
            $meta = $metadata["{$item->trackable_type}:{$item->trackable_id}"] ?? [];

            return new self(
                user_id: (string) $item->trackable_id,
                requests: $item->requests,
                cost: $item->cost,
                user_name: $meta['name'] ?? null,
                trackable_type: $item->trackable_type,
            );
        });
    }
}
