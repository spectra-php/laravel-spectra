<?php

declare(strict_types=1);

namespace Spectra\Actions\Trackables;

use Illuminate\Support\Collection;
use Spectra\Data\UsageByProvider;
use Spectra\Support\DateRange;
use Spectra\Support\Query\RequestAggregateQuery;

class GetTrackableUsageByProvider
{
    /**
     * @return Collection<int, UsageByProvider>
     */
    public function __invoke(string $type, string $id, DateRange $dateRange): Collection
    {
        return UsageByProvider::fromCollection(
            RequestAggregateQuery::make($dateRange)
                ->forTrackable($type, $id)
                ->groupByColumns('provider')
                ->countAs('requests')
                ->tokenSum()
                ->selectRaw('COALESCE(SUM(image_count), 0) as images_sum')
                ->selectRaw('COALESCE(SUM(video_count), 0) as videos_sum')
                ->selectRaw("COALESCE(SUM(CASE WHEN model_type = 'tts' THEN input_characters ELSE 0 END), 0) as tts_characters_sum")
                ->selectRaw("COALESCE(SUM(CASE WHEN model_type IN ('tts', 'stt') THEN duration_seconds ELSE 0 END), 0) as audio_duration_sum")
                ->successfulCost()
                ->orderByDesc('cost')
                ->get()
        );
    }
}
