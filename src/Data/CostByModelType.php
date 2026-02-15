<?php

namespace Spectra\Data;

use Illuminate\Support\Collection;
use Spectra\Enums\ModelType;
use Spectra\Models\SpectraRequest;

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
     * @param  \Illuminate\Support\Collection<int, SpectraRequest>  $items
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function fromCollection(Collection $items): Collection
    {
        return $items->map(fn (SpectraRequest $item) => new self(
            model_type: $item->model_type ?? 'unknown',
            label: $item->model_type
                ? (ModelType::tryFrom($item->model_type)?->label() ?? ucfirst($item->model_type))
                : 'Unknown',
            requests: (int) $item->requests,
            tokens: (int) $item->tokens_sum,
            images: (int) $item->images_sum,
            videos: (int) $item->videos_sum,
            input_characters: (int) $item->input_characters_sum,
            duration_seconds: (float) $item->duration_seconds_sum,
            cost: (float) $item->cost_sum,
        ));
    }
}
