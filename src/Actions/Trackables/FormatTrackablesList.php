<?php

namespace Spectra\Actions\Trackables;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;

class FormatTrackablesList
{
    /**
     * @param  \Illuminate\Support\Collection<int, SpectraRequest>  $pageItems
     * @param  array<string, string>  $topModels
     * @param  array<string, array<string, mixed>>  $metadata
     * @return array<int, array<string, mixed>>
     */
    public function __invoke(Collection $pageItems, array $topModels, array $metadata): array
    {
        return $pageItems->map(function (SpectraRequest $item) use ($topModels, $metadata) {
            $key = $item->trackable_type.':'.$item->trackable_id;

            return [
                'trackable_type' => $item->trackable_type,
                'trackable_id' => (int) $item->trackable_id,
                'requests' => (int) $item->requests,
                'tokens' => (int) $item->tokens_sum,
                'images' => (int) $item->images_sum,
                'videos' => (int) $item->videos_sum,
                'tts_characters' => (int) $item->tts_characters_sum,
                'audio_duration' => (float) $item->audio_duration_sum,
                'cost' => (float) $item->cost,
                'avg_latency' => (float) $item->latency_avg,
                'top_model' => $topModels[$key] ?? null,
                'trackable_name' => $metadata[$key]['name'] ?? null,
                'trackable_email' => $metadata[$key]['email'] ?? null,
            ];
        })->toArray();
    }
}
