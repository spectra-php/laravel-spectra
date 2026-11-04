<?php

declare(strict_types=1);

namespace Spectra\Data;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;

/**
 * One model's usage for a trackable: requests, tokens and the cost of
 * successful requests.
 */
readonly class UsageByModel extends DataTransferObject
{
    /**
     * Create a new usage by model instance.
     */
    public function __construct(
        public string $model,
        public int $requests,
        public int $tokens,
        public float $cost,
    ) {}

    /**
     * Build one row per model from a trackable's per-model aggregate rows.
     *
     * @param  Collection<int, SpectraRequest>  $items
     * @return Collection<int, self>
     */
    public static function fromCollection(Collection $items): Collection
    {
        return $items->map(fn (SpectraRequest $item) => new self(
            model: $item->model,
            requests: $item->requests,
            tokens: $item->tokens_sum,
            cost: $item->cost,
        ));
    }
}
