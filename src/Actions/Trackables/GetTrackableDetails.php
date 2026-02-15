<?php

namespace Spectra\Actions\Trackables;

use Illuminate\Http\Request;
use Spectra\Data\ModelTypeStat;
use Spectra\Data\Responses\TrackableDetailsResponse;
use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;

class GetTrackableDetails
{
    public function __construct(
        private readonly GetTrackableStats $getStats,
        private readonly GetTrackableCostsByDate $getCostsByDate,
        private readonly GetTrackableUsageByModel $getUsageByModel,
        private readonly GetTrackableUsageByProvider $getUsageByProvider,
        private readonly GetTrackableLatencyByModelType $getLatencyByModelType,
    ) {}

    public function __invoke(Request $request, string $id): TrackableDetailsResponse
    {
        $type = $request->input('type');

        abort_unless((bool) $type, 400, 'Missing type parameter.');

        $dateRange = DateRange::fromRequest($request);

        $stats = ($this->getStats)($type, $id, $dateRange);

        $trackableData = [
            'type' => $type,
            'id' => $id,
            ...$stats->toArray(),
            'costs_by_date' => ($this->getCostsByDate)($type, $id, $dateRange),
            'usage_by_model' => ($this->getUsageByModel)($type, $id, $dateRange),
            'usage_by_provider' => ($this->getUsageByProvider)($type, $id, $dateRange),
            'latency_by_model_type' => ($this->getLatencyByModelType)($type, $id, $dateRange)
                ->map(fn (ModelTypeStat $row) => [
                    'model_type' => $row->model_type,
                    'label' => $row->label,
                    'count' => $row->count,
                    'avg_latency' => $row->avg_latency,
                ]),
        ];

        if (class_exists($type)) {
            $trackable = $type::find($id);
            if ($trackable) {
                $trackableData['name'] = $trackable->name ?? null;
                $trackableData['email'] = $trackable->email ?? null;
            }
        }

        $requests = SpectraRequest::query()
            ->select([
                'id', 'provider', 'model', 'model_type',
                'prompt_tokens', 'completion_tokens',
                'image_count', 'video_count', 'input_characters', 'duration_seconds',
                'total_cost_in_cents', 'latency_ms', 'status_code', 'created_at',
            ])
            ->where('trackable_type', $type)
            ->where('trackable_id', $id)
            ->tap(fn ($query) => $dateRange->apply($query))
            ->latest('created_at')
            ->limit(20)
            ->get();

        return new TrackableDetailsResponse(
            trackable: $trackableData,
            requests: $requests,
        );
    }
}
