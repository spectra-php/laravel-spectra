<?php

declare(strict_types=1);

namespace Spectra\Data;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;

/**
 * One row of the costs screen's "top users": a user with their request count
 * and the cost of their successful requests. `user_id` is the trackable id, a
 * string because apps key models by UUID or ULID too; `trackable_type` tells
 * apart equal ids of different user models.
 */
readonly class CostByUser extends DataTransferObject
{
    /**
     * Create a new cost by user instance.
     */
    public function __construct(
        public string $user_id,
        public int $requests,
        public float $cost,
        public ?string $user_name = null,
        public ?string $trackable_type = null,
    ) {}

    /**
     * Build one row per user from the aggregate rows, adding each user's name.
     *
     * @param  Collection<int, SpectraRequest>  $items
     * @param  array<string, array<string, mixed>>  $metadata  "{trackable_type}:{trackable_id}" => ['name' => ..., 'email' => ...]
     * @return Collection<int, self>
     */
    public static function fromCollection(Collection $items, array $metadata = []): Collection
    {
        return $items->map(function (SpectraRequest $item) use ($metadata) {
            $trackableMetadata = $metadata["{$item->trackable_type}:{$item->trackable_id}"] ?? [];

            return new self(
                user_id: (string) $item->trackable_id,
                requests: $item->requests,
                cost: $item->cost,
                user_name: $trackableMetadata['name'] ?? null,
                trackable_type: $item->trackable_type,
            );
        });
    }
}
