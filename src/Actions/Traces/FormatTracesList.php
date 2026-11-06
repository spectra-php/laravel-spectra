<?php

declare(strict_types=1);

namespace Spectra\Actions\Traces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Spectra\Actions\Trackables\ResolveTrackableMetadata;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Query\RequestAggregateQuery;

/**
 * Shapes a page of trace totals into the rows the traces screen shows: each
 * trace named by the origin of its first call, with the models it used in
 * the order it first used them, and its trackable when it has only one.
 *
 * The extras come from one grouped query for the whole page, over each
 * trace's calls in any date range, so a trace is described in full.
 */
class FormatTracesList
{
    /** ISO 8601 with milliseconds, as the trace screen reads it. */
    private const ISO_MILLISECONDS = 'Y-m-d\\TH:i:s.vP';

    /**
     * Create a new format traces list action instance.
     */
    public function __construct(
        private readonly ResolveTrackableMetadata $resolveTrackableMetadata,
    ) {}

    /**
     * Get the page of trace totals as rows, with their origin, models and sole trackable.
     *
     * @param  LengthAwarePaginator<int, SpectraRequest>  $paginated
     * @return array<int, array<string, mixed>>
     */
    public function __invoke(LengthAwarePaginator $paginated): array
    {
        /** @var Collection<int, SpectraRequest> $traces */
        $traces = collect($paginated->items());

        if ($traces->isEmpty()) {
            return [];
        }

        $callsByTrace = RequestAggregateQuery::make()
            ->forTraces($traces->pluck('trace_id')->map(fn ($id) => (string) $id)->all())
            ->groupByColumns('trace_id', 'origin', 'provider', 'model', 'trackable_type', 'trackable_id')
            ->selectRaw('MIN(COALESCE(started_at, created_at)) as first_started_at')
            ->get()
            ->sortBy(fn (SpectraRequest $call) => (string) $call->first_started_at)
            ->groupBy('trace_id');

        $metadata = ($this->resolveTrackableMetadata)($callsByTrace->flatten(1));

        return $traces->map(function (SpectraRequest $trace) use ($callsByTrace, $metadata) {
            /** @var Collection<int, SpectraRequest> $calls */
            $calls = $callsByTrace->get((string) $trace->trace_id, collect());

            return [
                'trace_id' => (string) $trace->trace_id,
                'origin' => $calls->first(fn (SpectraRequest $call) => filled($call->origin))?->origin,
                'request_count' => $trace->request_count,
                'failed_count' => $trace->failed_count,
                'total_cost_in_cents' => $trace->cost,
                'total_tokens' => $trace->tokens,
                'started_at' => blank($trace->first_started_at) ? null : Date::parse((string) $trace->first_started_at)->format(self::ISO_MILLISECONDS),
                'duration_ms' => max(0, (int) round($trace->duration_ms)),
                'models' => $calls
                    ->unique(fn (SpectraRequest $call) => "{$call->provider}|{$call->model}")
                    ->map(fn (SpectraRequest $call) => [
                        'provider' => $call->provider,
                        'provider_display_name' => $call->provider_display_name,
                        'model' => $call->model,
                    ])
                    ->values()
                    ->all(),
                'trackable' => $this->resolveTrackable($calls, $metadata),
            ];
        })->values()->all();
    }

    /**
     * Get the trace's trackable when all its calls have the same one.
     *
     * @param  Collection<int, SpectraRequest>  $calls
     * @param  array<string, array<string, mixed>>  $metadata
     * @return array<string, mixed>|null
     */
    private function resolveTrackable(Collection $calls, array $metadata): ?array
    {
        $trackables = $calls
            ->filter(fn (SpectraRequest $call) => filled($call->trackable_type) && filled($call->trackable_id))
            ->unique(fn (SpectraRequest $call) => "{$call->trackable_type}:{$call->trackable_id}");

        if ($trackables->count() !== 1) {
            return null;
        }

        /** @var SpectraRequest $call */
        $call = $trackables->first();
        $key = "{$call->trackable_type}:{$call->trackable_id}";

        return [
            'type' => $call->trackable_type,
            'id' => $call->trackable_id,
            'name' => $metadata[$key]['name'] ?? null,
            'email' => $metadata[$key]['email'] ?? null,
        ];
    }
}
