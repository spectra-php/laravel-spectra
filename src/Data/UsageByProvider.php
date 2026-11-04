<?php

declare(strict_types=1);

namespace Spectra\Data;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;

/**
 * One provider's usage for a trackable: requests, tokens, media and audio
 * usage, and the cost of successful requests.
 */
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
     * @param  Collection<int, SpectraRequest>  $items
     * @return Collection<int, self>
     */
    public static function fromCollection(Collection $items): Collection
    {
        return $items->map(fn (SpectraRequest $item) => new self(
            provider: $item->provider,
            requests: $item->requests,
            tokens: $item->tokens_sum,
            images: $item->images_sum,
            videos: $item->videos_sum,
            tts_characters: $item->tts_characters_sum,
            audio_duration: $item->audio_duration_sum,
            cost: $item->cost,
        ));
    }
}
