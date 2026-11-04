<?php

declare(strict_types=1);

namespace Spectra\Actions\Traces;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
use Spectra\Actions\Requests\BuildModelBreakdown;
use Spectra\Actions\Trackables\ResolveSoleTrackable;
use Spectra\Data\Responses\TraceDetailsResponse;
use Spectra\Models\SpectraRequest;
use Spectra\Models\SpectraSpan;
use Spectra\Models\SpectraTag;
use Spectra\Support\Query\RequestAggregateQuery;

/**
 * Everything about one trace: its totals, its calls in the order they
 * started (with each call's offset from the start of the trace), the spans
 * around them, the models it used, its tags and, when every call has the
 * same one, its trackable. Calls and spans carry their span ids, so the
 * screen can draw the trace as a tree.
 *
 * The request screen asks for it with its own request id, so the call it
 * shows is marked and its position counted.
 */
class GetTraceDetails
{
    /** Spans the trace screen shows; a trace rarely has more. */
    private const SPAN_LIMIT = 500;

    /** ISO 8601 with milliseconds: calls of one trace are often under a second apart. */
    private const ISO_MILLISECONDS = 'Y-m-d\\TH:i:s.vP';

    /**
     * Create a new get trace details action instance.
     */
    public function __construct(
        private readonly BuildModelBreakdown $buildModelBreakdown,
        private readonly ResolveSoleTrackable $resolveSoleTrackable,
    ) {}

    /**
     * Get the trace's totals, calls, spans, models and tags, or null when it has no calls.
     */
    public function __invoke(string $traceId, ?string $currentRequestId = null, int $limit = 100): ?TraceDetailsResponse
    {
        $totals = RequestAggregateQuery::make()->forTrace($traceId)->callTotals()->first();

        if ($totals === null || $totals->request_count === 0) {
            return null;
        }

        $requestCount = $totals->request_count;

        $spans = $this->spans($traceId);

        // The timeline starts at the earliest call or span: a request's span starts before its first call.
        $traceStart = collect([$this->parseDate($totals->first_started_at), $spans->min('started_at')])
            ->filter()
            ->min();

        $calls = SpectraRequest::query()
            ->forTrace($traceId)
            ->select([
                'id', 'provider', 'model', 'model_type', 'endpoint', 'origin', 'span_id', 'parent_span_id', 'status_code', 'error_type',
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
            'span_id' => $call->span_id,
            'parent_span_id' => $call->parent_span_id,
            'status_code' => $call->status_code,
            'is_error' => $call->isFailed(),
            'is_streaming' => $call->is_streaming,
            'total_tokens' => $call->prompt_tokens + $call->completion_tokens,
            'total_cost_in_cents' => $call->total_cost_in_cents,
            'latency_ms' => $call->latency_ms,
            'created_at' => $call->created_at?->toIso8601String(),
            'started_at' => $this->startOf($call)?->format(self::ISO_MILLISECONDS),
            'offset_ms' => $this->offsetMs($traceStart, $this->startOf($call)),
            'is_current' => $call->id === $currentRequestId,
        ])->values()->all();

        $spanRows = $spans->map(fn (SpectraSpan $span) => [
            'span_id' => $span->span_id,
            'parent_span_id' => $span->parent_span_id,
            'name' => $span->name,
            'kind' => $span->kind->value,
            'origin' => $span->origin,
            'is_error' => $span->isFailed(),
            'error_message' => $span->error_message,
            'attributes' => $span->attributes ?? [],
            'started_at' => $span->started_at->format(self::ISO_MILLISECONDS),
            'offset_ms' => $this->offsetMs($traceStart, $span->started_at),
            'duration_ms' => $span->duration_ms,
        ])->values()->all();

        return new TraceDetailsResponse(
            trace_id: $traceId,
            parent_span_id: $this->upstreamParentSpanId($requests, $spanRows),
            origin: $calls->first(fn (SpectraRequest $call) => filled($call->origin))?->origin,
            request_count: $requestCount,
            failed_count: $totals->failed_count,
            position: $this->position($traceId, $currentRequestId, $requests),
            total_cost_in_cents: $totals->cost,
            total_tokens: $totals->tokens,
            started_at: $traceStart?->format(self::ISO_MILLISECONDS),
            duration_ms: max(0, (int) round($totals->duration_ms)),
            timeline_ms: $this->timelineMs($requests, $spanRows),
            truncated: $requestCount > count($requests),
            requests: $requests,
            spans: $spanRows,
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
     * Get the names of the tags on the trace's calls, alphabetically.
     *
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

    /**
     * The trace's spans, earliest first. None until the app runs the spans
     * migration.
     *
     * @return Collection<int, SpectraSpan>
     */
    private function spans(string $traceId): Collection
    {
        return rescue(
            fn () => SpectraSpan::query()->where('trace_id', $traceId)->oldest('started_at')->limit(self::SPAN_LIMIT)->get(),
            fn () => new Collection,
            report: false,
        );
    }

    /**
     * The span the trace came from (the incoming `traceparent`): the first
     * parent that is none of the trace's own spans or calls.
     *
     * @param  array<int, array<string, mixed>>  $requests
     * @param  array<int, array<string, mixed>>  $spans
     */
    private function upstreamParentSpanId(array $requests, array $spans): ?string
    {
        $own = collect([...$spans, ...$requests])->pluck('span_id')->filter()->all();

        foreach ([...$spans, ...$requests] as $node) {
            if (filled($node['parent_span_id']) && ! in_array($node['parent_span_id'], $own, true)) {
                return $node['parent_span_id'];
            }
        }

        return null;
    }

    /**
     * From the start of the timeline to the end of its last call or span, so
     * every bar fits.
     *
     * @param  array<int, array<string, mixed>>  $requests
     * @param  array<int, array<string, mixed>>  $spans
     */
    private function timelineMs(array $requests, array $spans): int
    {
        $ends = [
            0,
            ...Arr::map($requests, fn (array $call) => ($call['offset_ms'] ?? 0) + ($call['latency_ms'] ?? 0)),
            ...Arr::map($spans, fn (array $span) => ($span['offset_ms'] ?? 0) + $span['duration_ms']),
        ];

        return max($ends);
    }

    /**
     * Get when the call started, falling back to when it was recorded.
     */
    private function startOf(SpectraRequest $call): ?CarbonInterface
    {
        return $call->started_at ?? $call->created_at;
    }

    /**
     * Parse a stored timestamp, or null when blank.
     */
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
