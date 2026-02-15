<?php

namespace Spectra\Data;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;

readonly class UsageByProvider extends DataTransferObject
{
    public function __construct(
        public ?string $provider,
        public int $requests,
        public int $tokens,
        public int $images,
        public int $videos,
        public int $tts_characters,
        public float $audio_duration,
        public float $cost,
    ) {}

    /**
     * @param  \Illuminate\Support\Collection<int, SpectraRequest>  $items
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function fromCollection(Collection $items): Collection
    {
        return $items->map(fn (SpectraRequest $item) => new self(
            provider: $item->provider,
            requests: (int) $item->requests,
            tokens: (int) $item->tokens_sum,
            images: (int) $item->images_sum,
            videos: (int) $item->videos_sum,
            tts_characters: (int) $item->tts_characters_sum,
            audio_duration: (float) $item->audio_duration_sum,
            cost: (float) $item->cost,
        ));
    }
}
