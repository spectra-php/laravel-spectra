<?php

declare(strict_types=1);

namespace Spectra\Data;

use Illuminate\Support\Collection;
use Spectra\Enums\ModelType;
use Spectra\Models\SpectraRequest;

/**
 * One row of the costs screen's per-model-type table: requests (failures
 * included), usage, and the cost of successful requests.
 */
readonly class CostByModelType extends DataTransferObject
{
    public function __construct(
        public string $model_type,
        public string $label,
        public int $requests,
        public int $tokens,
        public int $images,
        public int $videos,
        public int $input_characters,
        public float $duration_seconds,
        public float $cost,
    ) {}

    /**
     * @param  Collection<int, SpectraRequest>  $items
     * @return Collection<int, self>
     */
    public static function fromCollection(Collection $items): Collection
    {
        return $items->map(fn (SpectraRequest $item) => new self(
            model_type: $item->model_type ?? 'unknown',
            label: $item->model_type
                ? (ModelType::tryFrom($item->model_type)?->label() ?? ucfirst($item->model_type))
                : 'Unknown',
            requests: $item->requests,
            tokens: $item->tokens_sum,
            images: $item->images_sum,
            videos: $item->videos_sum,
            input_characters: $item->input_characters_sum,
            duration_seconds: $item->duration_seconds_sum,
            cost: $item->cost,
        ));
    }
}
