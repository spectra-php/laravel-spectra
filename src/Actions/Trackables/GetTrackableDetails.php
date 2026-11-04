<?php

declare(strict_types=1);

namespace Spectra\Actions\Trackables;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Spectra\Data\ModelTypeLatency;
use Spectra\Data\Responses\TrackableDetailsResponse;
use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;

/**
 * Everything the trackable detail screen shows for one trackable: its stats,
 * cost per day, usage by model and provider, latency by model type, its
 * name and email when the model has them, and its latest requests.
 */
class GetTrackableDetails
{
    /**
     * Create a new get trackable details action instance.
     */
    public function __construct(
        private readonly GetTrackableStats $getStats,
        private readonly GetTrackableCostsByDate $getCostsByDate,
        private readonly GetTrackableUsageByModel $getUsageByModel,
        private readonly GetTrackableUsageByProvider $getUsageByProvider,
        private readonly GetTrackableLatencyByModelType $getLatencyByModelType,
    ) {}

    /**
     * Get the trackable's stats, breakdowns, name and latest requests.
     */
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
            'latency_by_model_type' => ModelTypeLatency::fromCollection(($this->getLatencyByModelType)($type, $id, $dateRange)),
        ];

        $trackable = $this->findTrackable($type, $id);

        if ($trackable !== null) {
            $trackableData['name'] = $trackable->name ?? null;
            $trackableData['email'] = $trackable->email ?? null;
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

    /**
     * The trackable model itself. The stored type is the morph class, which
     * is an alias when the app uses a morph map; anything that doesn't
     * resolve to an Eloquent model is never instantiated.
     */
    private function findTrackable(string $type, string $id): ?Model
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return $class::query()->find($id);
    }
}
