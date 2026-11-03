<?php

declare(strict_types=1);

namespace Spectra\Actions\Traces;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Spectra\Actions\Requests\BuildModelBreakdown;
use Spectra\Actions\Trackables\ResolveSoleTrackable;
use Spectra\Data\Responses\TraceDetailsResponse;
use Spectra\Models\SpectraRequest;
use Spectra\Models\SpectraTag;
use Spectra\Support\Query\RequestAggregateQuery;

/**
 * Everything about one trace: its totals, its calls in the order they
 * started (with each call's offset from the start of the trace), the models
 * it used, its tags and, when every call has the same one, its trackable.
 *
 * The request screen asks for it with its own request id, so the call it
 * shows is marked and its position counted.
 */
class GetTraceDetails
{
    /** ISO 8601 with milliseconds: calls of one trace are often under a second apart. */
    private const ISO_MILLISECONDS = 'Y-m-d\\TH:i:s.vP';

    public function __construct(
        private readonly BuildModelBreakdown $buildModelBreakdown,
        private readonly ResolveSoleTrackable $resolveSoleTrackable,
    ) {}

    public function __invoke(string $traceId, ?string $currentRequestId = null, int $limit = 100): ?TraceDetailsResponse
    {
        $totals = RequestAggregateQuery::make()->forTrace($traceId)->callTotals()->first();

        if ($totals === null || (int) $totals->request_count === 0) {
            return null;
        }

        $requestCount = (int) $totals->request_count;

        $traceStart = $this->parseDate($totals->first_started_at);

        $calls = SpectraRequest::query()
            ->forTrace($traceId)
            ->select([
                'id', 'provider', 'model', 'model_type', 'endpoint', 'origin', 'parent_span_id', 'status_code', 'error_type',
                'is_streaming', 'prompt_tokens', 'completion_tokens', 'total_cost_in_cents', 'latency_ms', 'created_at', 'started_at',
            ])
            ->orderByRaw('COALESCE(started_at, created_at)')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $requests = $calls->map(fn (SpectraRequest $call) => [
            'id' => $call->id,
            'provider' => $call->provider,
            'provider_display_name' => $call->provider_display_name,
            'model' => $call->model,
            'model_type' => $call->model_type,
            'endpoint' => $call->endpoint,
            'origin' => $call->origin,
            'status_code' => $call->status_code,
            'is_error' => $call->isFailed(),
            'is_streaming' => $call->is_streaming,
            'total_tokens' => $call->prompt_tokens + $call->completion_tokens,
            'total_cost_in_cents' => (float) $call->total_cost_in_cents,
            'latency_ms' => $call->latency_ms,
            'created_at' => $call->created_at?->toIso8601String(),
            'started_at' => $this->startOf($call)?->format(self::ISO_MILLISECONDS),
            'offset_ms' => $this->offsetMs($traceStart, $this->startOf($call)),
            'is_current' => $call->id === $currentRequestId,
        ])->values()->all();

        return new TraceDetailsResponse(
            trace_id: $traceId,
            parent_span_id: $calls->first()?->parent_span_id,
            origin: $calls->first(fn (SpectraRequest $call) => filled($call->origin))?->origin,
            request_count: $requestCount,
            failed_count: (int) $totals->failed_count,
            position: $this->position($traceId, $currentRequestId, $requests),
            total_cost_in_cents: (float) $totals->cost,
            total_tokens: (int) $totals->tokens,
            started_at: $traceStart?->format(self::ISO_MILLISECONDS),
            duration_ms: max(0, (int) round((float) $totals->duration_ms)),
            truncated: $requestCount > count($requests),
            requests: $requests,
            models: ($this->buildModelBreakdown)(RequestAggregateQuery::make()->forTrace($traceId)),
            tags: $this->tags($traceId),
            trackable: ($this->resolveSoleTrackable)(RequestAggregateQuery::make()->forTrace($traceId)),
        );
    }

    /**
     * The 1-based place of the current call in the trace. Counted with a
     * query only when the call is past the calls returned.
     *
     * @param  array<int, array<string, mixed>>  $requests
     */
    private function position(string $traceId, ?string $currentRequestId, array $requests): ?int
    {
        if ($currentRequestId === null) {
            return null;
        }

        foreach ($requests as $index => $request) {
            if ($request['is_current']) {
                return $index + 1;
            }
        }

        $current = SpectraRequest::query()->select(['id', 'created_at', 'started_at'])->find($currentRequestId);
        $start = $current === null ? null : $this->startOf($current)?->format('Y-m-d H:i:s.v');

        if ($start === null) {
            return null;
        }

        return SpectraRequest::query()
            ->forTrace($traceId)
            ->where(fn ($earlier) => $earlier
                ->whereRaw('COALESCE(started_at, created_at) < ?', [$start])
                ->orWhere(fn ($tie) => $tie->whereRaw('COALESCE(started_at, created_at) = ?', [$start])->where('id', '<', $currentRequestId)))
            ->count() + 1;
    }

    /**
     * @return array<int, string>
     */
    private function tags(string $traceId): array
    {
        return SpectraTag::query()
            ->whereHas('requests', fn ($requests) => $requests->forTrace($traceId))
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    private function startOf(SpectraRequest $call): ?CarbonInterface
    {
        return $call->started_at ?? $call->created_at;
    }

    private function parseDate(mixed $value): ?CarbonInterface
    {
        return blank($value) ? null : Date::parse((string) $value);
    }

    /**
     * Milliseconds from the start of the trace to the given time.
     */
    private function offsetMs(?CarbonInterface $from, ?CarbonInterface $to): ?int
    {
        if ($from === null || $to === null) {
            return null;
        }

        return max(0, (int) round($from->diffInMilliseconds($to)));
    }
}
