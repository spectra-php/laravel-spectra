<?php

declare(strict_types=1);

namespace Spectra\Actions\Trackables;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;

/**
 * Shapes one page of aggregated trackable rows into the JSON rows the
 * trackables list shows, joined with each row's top model and name.
 */
class FormatTrackablesList
{
    /**
     * Get the page of trackable totals as rows, with each one's top model and name.
     *
     * @param  Collection<int, SpectraRequest>  $pageItems
     * @param  array<string, string>  $topModels
     * @param  array<string, array<string, mixed>>  $metadata
     * @return array<int, array<string, mixed>>
     */
    public function __invoke(Collection $pageItems, array $topModels, array $metadata): array
    {
        return $pageItems->map(function (SpectraRequest $item) use ($topModels, $metadata) {
            $key = "{$item->trackable_type}:{$item->trackable_id}";

            return [
                'trackable_type' => $item->trackable_type,
                // Kept as a string: apps key trackables by UUID or ULID too.
                'trackable_id' => (string) $item->trackable_id,
                'requests' => $item->requests,
                'tokens' => $item->tokens_sum,
                'images' => $item->images_sum,
                'videos' => $item->videos_sum,
                'tts_characters' => $item->tts_characters_sum,
                'audio_duration' => $item->audio_duration_sum,
                'cost' => $item->cost,
                'avg_latency' => $item->latency_avg,
                'top_model' => $topModels[$key] ?? null,
                'trackable_name' => $metadata[$key]['name'] ?? null,
                'trackable_email' => $metadata[$key]['email'] ?? null,
            ];
        })->toArray();
    }
}
